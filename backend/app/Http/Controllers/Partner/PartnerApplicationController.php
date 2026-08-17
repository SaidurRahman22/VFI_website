<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Student\Student;
use App\Services\ApplicationReadiness;
use App\Services\PipelineService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — the applications pipeline + dashboard aggregates. Everything is
 * tenant-scoped by the BelongsToAgency global scope (tenant from the session).
 * The 8 KPIs + deadline buckets are computed SERVER-SIDE per tenant — never
 * client-filtered from a global list (docs §4).
 */
class PartnerApplicationController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
        private readonly ApplicationReadiness $readiness,
    ) {}

    /** POST /api/partner/applications — create for a tenant student. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'program_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'intake_month' => ['nullable', 'string', 'max:20'],
            'intake_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'ack_no' => ['nullable', 'string', 'max:60'],
            'deadline_at' => ['nullable', 'date'],
        ]);

        $agencyId = app(TenantContext::class)->agencyId();
        // The student must belong to THIS tenant — blocks cross-tenant creation.
        $student = Student::forAgency($agencyId)->whereKey($data['student_id'])->first();
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404)->header('Cache-Control', 'no-store');
        }

        $app = $this->pipeline->create($student, $data, $request->user()->id);

        // Deliberate: missing paperwork does NOT block creation. An agency
        // legitimately files the case first and collects the student's documents
        // afterwards, and refusing the submission would only push that work off
        // the record. The readiness array rides along instead, so the console can
        // tell the agency what is still outstanding the moment the case is filed
        // rather than leaving it to sit unprocessable and unexplained.
        return response()->json([
            'application' => $this->present($app->load('student')),
            'readiness' => $this->readiness->for($student),
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/applications/{application} — one case in full. */
    public function show(Request $request, int $application): JsonResponse
    {
        // No RLS bypass here on purpose: EnsurePartner has already bound the
        // session's agency into BOTH nets, so the global scope — and the Postgres
        // RLS policy behind it — constrain this read to the caller's own cases.
        // Another agency's id simply matches nothing, which is a 404 and never a
        // 403: one agency must not learn that another agency's ids exist.
        $app = Application::with('student')->whereKey($application)->firstOr(fn () => abort(404));

        // occurred_at then id — several moves can land inside the same second and
        // the history is only useful if it always reads in the order it happened.
        $events = $app->events()->orderBy('id')->get();

        // applications.program_id is a bare column (public catalogue data, no
        // relation on the model), so resolve it in one query and only when set.
        $program = $app->program_id
            ? Program::select('id', 'title', 'institution_id')->with('institution:id,name')->find($app->program_id)
            : null;

        // A case with no student cannot be reviewed and has no checklist to read,
        // so it is not a viewable case. The FK is non-nullable and cascades, so
        // this is a guard against a corrupted row, not an expected state.
        $s = $app->student ?? abort(404);

        return response()->json([
            'application' => [
                'id' => $app->id,
                'public_ref' => $this->publicRef($app),
                'student' => [
                    'id' => $s->id,
                    'name' => trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: $s->displayName(),
                    'email' => $s->email,
                ],
                'status' => $app->status?->value,
                'intake' => trim(($app->intake_month ?? '').' '.($app->intake_year ?? '')),
                'ack_no' => $app->ack_no,
                'deadline_at' => optional($app->deadline_at)->toIso8601String(),
                'submitted_at' => optional($app->submitted_at)->toIso8601String(),
                'program' => $program ? [
                    'id' => $program->id,
                    'title' => $program->title,
                    'university' => $program->institution?->name,
                ] : null,
            ],
            'events' => $events->map(fn (ApplicationStatusEvent $e) => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'actor_type' => $e->actor_type?->value,
                'note' => $e->note,
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
            ])->values(),
            'readiness' => $this->readiness->for($s),
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/applications — paged, tenant-scoped, filtered list. */
    public function index(Request $request): JsonResponse
    {
        $q = Application::with('student');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($y = $request->query('year')) {
            $q->where('intake_year', (int) $y);
        }
        if ($m = $request->query('intake')) {
            $q->where('intake_month', $m);
        }
        if ($kw = trim((string) $request->query('q'))) {
            $q->whereHas('student', fn ($w) => $w->where('first_name', 'like', "%{$kw}%")
                ->orWhere('last_name', 'like', "%{$kw}%")->orWhere('email', 'like', "%{$kw}%"));
        }

        $page = $q->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Application $a) => $this->present($a)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/dashboard/kpis — GROUP BY status over the tenant + filters. */
    public function kpis(Request $request): JsonResponse
    {
        $q = Application::query();
        if ($y = $request->query('year')) {
            $q->where('intake_year', (int) $y);
        }
        if ($m = $request->query('intake')) {
            $q->where('intake_month', $m);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $counts = $q->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $out = [];
        foreach (ApplicationStatus::values() as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return response()->json(['counts' => $out, 'total' => array_sum($out)])->header('Cache-Control', 'no-store');
    }

    /** GET /api/partner/dashboard/deadlines — today/tomorrow/7d/14d buckets. */
    public function deadlines(): JsonResponse
    {
        $today = today();
        $base = fn () => Application::query()->whereNotNull('deadline_at');

        // Counts alone left the dashboard panels showing a permanent "No
        // upcoming deadlines" — a number with nothing behind it is not usable
        // work. Each bucket now carries the cases themselves, capped so a busy
        // agency cannot pull its whole pipeline into a dashboard widget.
        $items = function ($query) {
            return $query->with(['student:id,first_name,last_name,email'])
                ->orderBy('deadline_at')
                ->limit(25)
                ->get()
                ->map(fn (Application $a) => [
                    'id' => $a->id,
                    'student' => trim(($a->student->first_name ?? '').' '.($a->student->last_name ?? ''))
                        ?: ($a->student->email ?? 'Student'),
                    'status' => $a->status?->value,
                    'deadline' => optional($a->deadline_at)->toDateString(),
                ])->values();
        };

        $buckets = [
            'today' => (clone $base())->whereDate('deadline_at', $today),
            'tomorrow' => (clone $base())->whereDate('deadline_at', $today->copy()->addDay()),
            'in_7_days' => (clone $base())->whereBetween('deadline_at', [$today->copy()->startOfDay(), $today->copy()->addDays(7)->endOfDay()]),
            'in_14_days' => (clone $base())->whereBetween('deadline_at', [$today->copy()->startOfDay(), $today->copy()->addDays(14)->endOfDay()]),
        ];

        $out = [];
        foreach ($buckets as $key => $query) {
            $out[$key] = (clone $query)->count();          // unchanged shape for the tab badges
            $out['items'][$key] = $items(clone $query);
        }

        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    /**
     * `applications` carries no public reference column of its own, so the case
     * reference is derived: the student's ref plus the case id. Stable, readable
     * back to staff over the phone, and built only from identifiers the caller
     * already holds — it exposes nothing new.
     */
    private function publicRef(Application $a): string
    {
        $ref = $a->student?->student_ref;

        return ($ref ? $ref.'-' : '').'A'.$a->id;
    }

    private function present(Application $a): array
    {
        $s = $a->student;

        return [
            'id' => $a->id,
            'student' => [
                'id' => $s?->id,
                'name' => $s ? (trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: $s->displayName()) : null,
                'public_ref' => $s?->student_ref,
            ],
            'status' => $a->status?->value,
            'intake' => trim(($a->intake_month ?? '').' '.($a->intake_year ?? '')),
            'ack_no' => $a->ack_no,
            'deadline_at' => optional($a->deadline_at)->toIso8601String(),
            'submitted_at' => optional($a->submitted_at)->toIso8601String(),
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }
}
