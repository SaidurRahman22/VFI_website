<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Partner\ApplicationStatusEvent;
use App\Services\ApplicationReadiness;
use App\Services\ApplicationReviewService;
use App\Support\RlsBypass;
use App\Support\StaffAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The staff application queue, as JSON, for the admin panel.
 *
 * WHY THIS EXISTS AT ALL
 * The same queue already exists as a Filament resource. Filament renders it as
 * server-side HTML inside its own shell, which is what made the admin
 * unusable: a sidebar generated one entry per Eloquent model, 21 of them. The
 * replacement panel is a static Vue app, so it needs the same data over HTTP.
 *
 * Nothing here reimplements the workflow. Transitions go through
 * ApplicationReviewService, which owns the state machine, the reason
 * requirement on negative outcomes, the audit trail and the tenant adoption for
 * the write. Readiness comes from ApplicationReadiness, so the panel, the
 * partner console and the 201 from a new application cannot disagree about
 * whether a case can be processed.
 *
 * TWO NETS STOOD DOWN FOR READS, NEITHER FOR WRITES
 * Staff hold no tenant, and these tables are tenant-scoped twice over: the
 * fail-closed BelongsToAgency Eloquent scope, and Postgres RLS FORCE. Reads
 * drop both (withoutGlobalScope inside RlsBypass::run) because oversight across
 * agencies is the job. Writes do NOT - the policies' WITH CHECK carries no
 * bypass by design, so ApplicationReviewService adopts the owning tenant for
 * the duration of the write and a staff member cannot move a row into an agency
 * they never named.
 */
class AdminApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationReviewService $review,
        private readonly ApplicationReadiness $readiness,
    ) {}

    /** Every endpoint here is one job: processing applications. */
    private function authorise(): void
    {
        abort_unless(StaffAbilities::current('applications.process'), 403);
    }

    /** GET /api/admin/applications — the queue. */
    public function index(Request $request): JsonResponse
    {
        $this->authorise();

        $data = $request->validate([
            'status' => ['nullable', Rule::in(ApplicationStatus::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'agency_id' => ['nullable', 'integer'],
            // "waiting on us" is the default view: the actual work queue.
            'waiting' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        [$rows, $total, $counts] = RlsBypass::run(function () use ($data) {
            $base = fn () => Application::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class);

            $q = $base()
                ->with([
                    'student:id,first_name,last_name,email,student_ref',
                    'agency:id,legal_name',
                ])
                ->withCount('notes');

            if (! empty($data['status'])) {
                $q->where('status', $data['status']);
            }
            if (! empty($data['agency_id'])) {
                $q->where('agency_id', (int) $data['agency_id']);
            }
            if (! empty($data['waiting'])) {
                $q->whereIn('status', [
                    ApplicationStatus::Submitted->value,
                    ApplicationStatus::Review->value,
                ]);
            }
            if ($kw = trim((string) ($data['q'] ?? ''))) {
                // Bound LIKE on the student's own columns; the keyword never
                // reaches SQL unquoted.
                $q->whereHas('student', fn ($w) => $w
                    ->where('first_name', 'like', "%{$kw}%")
                    ->orWhere('last_name', 'like', "%{$kw}%")
                    ->orWhere('email', 'like', "%{$kw}%")
                    ->orWhere('student_ref', 'like', "%{$kw}%"));
            }

            $page = $q->orderByDesc('submitted_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 25);

            // The tab badges, in one grouped query rather than one per status.
            $byStatus = $base()->selectRaw('status, count(*) as c')
                ->groupBy('status')->pluck('c', 'status')->all();

            return [$page->items(), $page->total(), $byStatus];
        });

        $counts = collect(ApplicationStatus::values())
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();

        return response()->json([
            'data' => collect($rows)->map(fn (Application $a) => $this->row($a))->values(),
            'meta' => [
                'total' => $total,
                'counts' => $counts,
                'waiting' => $counts[ApplicationStatus::Submitted->value] + $counts[ApplicationStatus::Review->value],
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/admin/applications/{id} — one case, everything staff need. */
    public function show(Request $request, int $application): JsonResponse
    {
        $this->authorise();

        $payload = RlsBypass::run(function () use ($application) {
            $app = Application::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->with(['student', 'agency:id,legal_name,country'])
                ->whereKey($application)
                ->first();

            if (! $app) {
                return null;
            }

            $events = $app->events()->orderBy('id')->get();
            $notes = ApplicationNote::where('application_id', $app->id)
                ->with('author:id,name')->orderByDesc('id')->get();

            return [$app, $events, $notes];
        });

        abort_if($payload === null, 404);
        [$app, $events, $notes] = $payload;

        // applications.program_id is a bare column (public catalogue data, no
        // relation on the model), so it is resolved separately and only if set.
        $program = $app->program_id
            ? Program::select('id', 'title', 'institution_id', 'level', 'tuition_fee_minor', 'tuition_currency')
                ->with('institution:id,name,country')->find($app->program_id)
            : null;

        return response()->json([
            'application' => $this->row($app) + [
                'agency' => ['id' => $app->agency?->id, 'name' => $app->agency?->legal_name],
                'program' => $program ? [
                    'id' => $program->id,
                    'title' => $program->title,
                    'level' => $program->level,
                    'university' => $program->institution?->name,
                    'country' => $program->institution?->country,
                ] : null,
            ],
            // What the case is waiting on, from the one service that decides it.
            'readiness' => $app->student ? $this->readiness->for($app->student) : null,
            // Where it may legally go next — the panel must not invent buttons
            // the state machine would refuse.
            'next_statuses' => collect($this->review->allowedNextStatuses($app->status))
                ->map(fn (ApplicationStatus $s) => ['value' => $s->value, 'label' => $this->label($s)])
                ->values(),
            'events' => $events->map(fn (ApplicationStatusEvent $e) => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'actor_type' => $e->actor_type?->value,
                'note' => $e->note,
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
            ])->values(),
            'notes' => $notes->map(fn (ApplicationNote $n) => [
                'id' => $n->id,
                'body' => $n->body,
                // Denormalised on the row, so a note keeps its attribution
                // even if the staff account is later removed.
                'author' => $n->author_name ?: $n->author?->name,
                'created_at' => optional($n->created_at)->toIso8601String(),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /** POST /api/admin/applications/{id}/transition — move the case on. */
    public function transition(Request $request, int $application): JsonResponse
    {
        $this->authorise();

        $data = $request->validate([
            'to' => ['required', Rule::in(ApplicationStatus::values())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = $this->findForWrite($application);

        try {
            // The service owns the transition map, the reason requirement on a
            // negative outcome, the audit entries and the tenant adoption.
            $updated = $this->review->transition(
                $app,
                ApplicationStatus::from($data['to']),
                $request->user(),
                $data['reason'] ?? null,
            );
        } catch (\RuntimeException $e) {
            // A refused transition is the caller's mistake, not a server fault.
            return response()->json(['message' => $e->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['application' => $this->row($updated)])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /api/admin/applications/{id}/notes — staff-internal only. */
    public function addNote(Request $request, int $application): JsonResponse
    {
        $this->authorise();

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $app = $this->findForWrite($application);
        $note = $this->review->addNote($app, $request->user(), $data['body']);

        return response()->json(['note' => [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $request->user()->name,
            'created_at' => optional($note->created_at)->toIso8601String(),
        ]], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Load a case for writing. The READ is bypassed (staff hold no tenant); the
     * write that follows is not — ApplicationReviewService adopts the owning
     * tenant itself, because the RLS policies' WITH CHECK has no bypass.
     */
    private function findForWrite(int $id): Application
    {
        $app = RlsBypass::run(fn () => Application::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereKey($id)
            ->first());

        abort_if($app === null, 404);

        return $app;
    }

    /** @return array<string, mixed> */
    private function row(Application $a): array
    {
        $s = $a->student;

        return [
            'id' => $a->id,
            'ref' => ($s?->student_ref ? $s->student_ref.'-' : '').'A'.$a->id,
            'student' => [
                'id' => $s?->id,
                'name' => $s ? (trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: $s->email) : null,
                'email' => $s?->email,
            ],
            'agency_name' => $a->agency?->legal_name,
            'status' => $a->status->value,
            'status_label' => $this->label($a->status),
            'intake' => trim(($a->intake_month ?? '').' '.($a->intake_year ?? '')) ?: null,
            'ack_no' => $a->ack_no,
            'deadline_at' => optional($a->deadline_at)->toDateString(),
            'submitted_at' => optional($a->submitted_at)->toIso8601String(),
            'notes_count' => $a->notes_count ?? null,
        ];
    }

    /** Human wording for a status, kept here so the panel never invents one. */
    private function label(ApplicationStatus $s): string
    {
        return match ($s) {
            ApplicationStatus::Submitted => 'Submitted',
            ApplicationStatus::Review => 'Under review',
            ApplicationStatus::Offer => 'Offer',
            ApplicationStatus::Conditional => 'Conditional offer',
            ApplicationStatus::PendingFromPartner => 'Pending from partner',
            ApplicationStatus::Payment => 'Payment',
            ApplicationStatus::VisaReceived => 'Visa received',
            ApplicationStatus::VisaRejected => 'Visa rejected',
            ApplicationStatus::Deferral => 'Deferral',
            ApplicationStatus::NonEnrolment => 'Non-enrolment',
        };
    }
}
