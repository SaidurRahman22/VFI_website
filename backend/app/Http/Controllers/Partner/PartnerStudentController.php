<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ApplicationStatus;
use App\Enums\StudentSource;
use App\Http\Controllers\Controller;
use App\Models\ContentAuditLog;
use App\Models\Partner\Application;
use App\Models\Student\Student;
use App\Services\Gdpr\DataSubjectErasureService;
use App\Support\IntakeLabel;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 7 — tenant-scoped students (docs §3). The owning agency ALWAYS comes
 * from the session-bound tenant (EnsurePartner → TenantContext), never the form
 * or a URL. Collision rule (settled at P6 sign-off): the manual modal refuses
 * ANY email that already exists — owned by another agency OR a self-signup —
 * so an agency is only ever credited students it genuinely brought in.
 */
class PartnerStudentController extends Controller
{
    /**
     * The statuses at which a case is FINISHED and no longer needs its student
     * reachable from the console. Everything else in ApplicationStatus is still
     * in flight — including `deferral` (moved to a later intake, not dropped)
     * and `pending_from_partner` (waiting on this very agency).
     *
     * Named as the closed set rather than the open one on purpose: a status
     * added to the enum later is in flight until someone decides otherwise,
     * which is the safe default for the archive guard below.
     */
    private const CLOSED_APPLICATION_STATUSES = [
        ApplicationStatus::VisaReceived,
        ApplicationStatus::VisaRejected,
        ApplicationStatus::NonEnrolment,
    ];

    /** POST /api/partner/students — register a lead from the console modal. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = app(TenantContext::class)->agencyId();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:70'],
            'dial' => ['required', 'string', 'max:8'],
            'mobile' => ['required', 'string', 'max:20', $this->minDigits(6)],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'destination_country' => ['nullable', 'string', 'max:90'],
            'intake_month' => ['nullable', 'string', 'max:20'],
            'intake_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Collision: refuse any existing email (never silently re-parent).
        if (Student::where('email', $email)->exists()) {
            return response()->json([
                'message' => 'That email is already registered with VFI. If this student is yours, contact the partner desk.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        $student = new Student;
        $student->forceFill([
            'agency_id' => $agencyId,                       // from SESSION, never the form
            'source' => StudentSource::PartnerModal->value,
            'registered_by_user_id' => $request->user()->id,
            'email' => $email,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'phone_cc' => $data['dial'],
            'phone' => preg_replace('/\D/', '', $data['mobile']),
            'destination_country' => $data['destination_country'] ?? null,
            'intake_month' => $data['intake_month'] ?? null,
            'intake_year' => $data['intake_year'] ?? null,
            'student_ref' => 'VFI-PENDING-'.Str::random(12),
        ])->save();
        $student->forceFill(['student_ref' => sprintf('VFI-%d-%05d', now()->year, $student->id + 4870)])->save();

        return response()->json(['student' => $this->present($student)], 201)->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/students — paged, filtered, tenant-scoped list. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = app(TenantContext::class)->agencyId();
        $q = Student::forAgency($agencyId);

        $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');

        if ($kw = trim((string) $request->query('q'))) {
            $q->where(function ($w) use ($kw) {
                $w->where('first_name', 'like', "%{$kw}%")
                    ->orWhere('last_name', 'like', "%{$kw}%")
                    ->orWhere('email', 'like', "%{$kw}%")
                    ->orWhere('student_ref', 'like', "%{$kw}%");
            });
        }
        if ($c = $request->query('country')) {
            $q->where('destination_country', $c);
        }
        if ($m = $request->query('intake')) {
            $q->where('intake_month', $m);
        }
        if ($y = $request->query('year')) {
            $q->where('intake_year', (int) $y);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $page = $q->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Student $s) => $this->present($s)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/partner/students/{student}/archive — file a lead away.
     *
     * ARCHIVE, NEVER DELETE. `students` is referenced by applications, uploaded
     * documents, shortlists and the document access log; a row removed here
     * would take a case the VFI office is working on with it, and the partner
     * who created it is not the party entitled to decide that. Erasure is a
     * GDPR request handled by DataSubjectErasureService, which is a different
     * decision made by different people with an audit trail of its own.
     *
     * Until this existed `archived_at` was READ by index() — the console's
     * "Archived Students" view — and written only by that erasure service, so
     * the console offered a view nothing could ever fill and a partner who
     * mistyped an email carried the bad row for ever.
     *
     * A STUDENT WITH A LIVE APPLICATION IS REFUSED (409), and that is the one
     * real decision in this method. Archiving does not touch the applications:
     * they stay in the pipeline, the KPIs and the office's queue, exactly as
     * they should. But the console reaches a student's document uploads through
     * /api/partner/students/{id}/documents, and the only route to that id is
     * the student list — which archiving removes them from. So archiving a
     * live case does not hide it, it strands it: staff keep asking for
     * paperwork the partner can no longer see how to send. Refusing is the
     * honest answer, and the body says how many cases are in the way so the
     * console can show a reason instead of a dead button.
     *
     * Partners cannot close a case themselves (status writes are staff-only,
     * Phase 9), so the message points at the desk rather than at a control that
     * does not exist.
     */
    public function archive(Request $request, int $student): JsonResponse
    {
        $s = $this->ownedStudent($student);

        // Idempotent: a double-click, or a retry after a dropped response, is
        // not an error and must not write a second audit row.
        if ($s->archived_at !== null) {
            return response()->json(['student' => $this->present($s)])->header('Cache-Control', 'no-store');
        }

        // Application carries the BelongsToAgency global scope, so this counts
        // only THIS tenant's cases — and the student is already fenced to the
        // tenant above, so the two agree by construction.
        $open = Application::where('student_id', $s->id)
            ->whereNotIn('status', array_map(fn (ApplicationStatus $c) => $c->value, self::CLOSED_APPLICATION_STATUSES))
            ->count();

        if ($open > 0) {
            return response()->json([
                'message' => $open === 1
                    ? 'This student has an application still in progress. Ask the VFI desk to close it before archiving.'
                    : "This student has {$open} applications still in progress. Ask the VFI desk to close them before archiving.",
                'open_applications' => $open,
            ], 409)->header('Cache-Control', 'no-store');
        }

        // One unit of work. Audited like the other partner-initiated state
        // change on somebody else's record (see PartnerStudentDocumentController),
        // and for the same reason: the data subject did not do this and cannot
        // see that it happened. agency_id rides in the payload because
        // content_audit_log is not tenant-scoped and the actor alone does not
        // say whose record moved.
        DB::transaction(function () use ($s) {
            $s->forceFill(['archived_at' => now()])->save();

            ContentAuditLog::record('partner_student_archive', 'student', (string) $s->id,
                ['archived_at' => null],
                ['archived_at' => optional($s->archived_at)->toIso8601String(), 'agency_id' => $s->agency_id],
            );
        });

        return response()->json(['student' => $this->present($s)])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/partner/students/{student}/unarchive — put a lead back.
     *
     * Not unconditional, and the exception is the whole reason this method
     * needs a comment. `archived_at` is not the partner's field alone:
     * DataSubjectErasureService::pseudonymise() sets it too, deliberately, to
     * "drop the row out of the console lists — an erased subject should not
     * keep surfacing as a workable lead". An unguarded Restore would hand a
     * partner a button that quietly reverses half of a GDPR erasure and puts a
     * person who asked to be forgotten back in front of staff.
     *
     * So: an erased subject stays archived, and says why. Everything else
     * restores freely — an archive a partner cannot undo is a delete with extra
     * steps.
     */
    public function unarchive(Request $request, int $student): JsonResponse
    {
        $s = $this->ownedStudent($student);

        if (DataSubjectErasureService::isErased($s)) {
            return response()->json([
                'message' => 'This record was erased at the request of the person it belonged to and cannot be restored.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        if ($s->archived_at === null) {
            return response()->json(['student' => $this->present($s)])->header('Cache-Control', 'no-store');
        }

        $was = optional($s->archived_at)->toIso8601String();

        // One unit of work, like every other audited write in this project:
        // an audit row that can fail independently of the thing it records is
        // an audit log that lies by omission.
        DB::transaction(function () use ($s, $was) {
            $s->forceFill(['archived_at' => null])->save();

            ContentAuditLog::record('partner_student_unarchive', 'student', (string) $s->id,
                ['archived_at' => $was],
                ['archived_at' => null, 'agency_id' => $s->agency_id],
            );
        });

        return response()->json(['student' => $this->present($s)])->header('Cache-Control', 'no-store');
    }

    /**
     * The id in the URL is client-controlled, so it is fenced to the SESSION
     * agency. Student carries no global scope (the portal reads it self-scoped
     * with no tenant in context), so the fence is explicit — the same shape
     * PartnerStudentDocumentController uses, for the same reason.
     *
     * A foreign or unknown id is a 404, never a 403: a 403 would confirm to one
     * agency that another agency's row exists.
     */
    private function ownedStudent(int $student): Student
    {
        $agencyId = (int) app(TenantContext::class)->agencyId();

        return Student::forAgency($agencyId)->whereKey($student)
            ->firstOr(fn () => abort(404, 'Student not found.'));
    }

    private function present(Student $s): array
    {
        return [
            'id' => $s->id,
            'public_ref' => $s->student_ref,
            'name' => trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: $s->displayName(),
            'email' => $s->email,
            'phone' => trim(($s->phone_cc ?? '').' '.($s->phone ?? '')),
            'destination_country' => $s->destination_country,
            'intake' => IntakeLabel::for($s->intake_month, $s->intake_year),
            'source' => $s->source?->value,
            'archived' => $s->archived_at !== null,
            'created_at' => optional($s->created_at)->toIso8601String(),
        ];
    }

    private function minDigits(int $n): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) use ($n) {
            if (strlen(preg_replace('/\D/', '', (string) $value)) < $n) {
                $fail('Enter a valid phone number.');
            }
        };
    }
}
