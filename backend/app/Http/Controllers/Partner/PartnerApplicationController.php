<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner\Application;
use App\Models\Student\Student;
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
    public function __construct(private readonly PipelineService $pipeline) {}

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

        return response()->json(['application' => $this->present($app->load('student'))], 201)->header('Cache-Control', 'no-store');
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

        return response()->json([
            'today' => (clone $base())->whereDate('deadline_at', $today)->count(),
            'tomorrow' => (clone $base())->whereDate('deadline_at', $today->copy()->addDay())->count(),
            'in_7_days' => (clone $base())->whereBetween('deadline_at', [$today->copy()->startOfDay(), $today->copy()->addDays(7)->endOfDay()])->count(),
            'in_14_days' => (clone $base())->whereBetween('deadline_at', [$today->copy()->startOfDay(), $today->copy()->addDays(14)->endOfDay()])->count(),
        ])->header('Cache-Control', 'no-store');
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
