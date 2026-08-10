<?php

namespace App\Http\Controllers\Partner;

use App\Enums\StudentSource;
use App\Http\Controllers\Controller;
use App\Models\Student\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private function present(Student $s): array
    {
        return [
            'id' => $s->id,
            'public_ref' => $s->student_ref,
            'name' => trim(($s->first_name ?? '').' '.($s->last_name ?? '')) ?: $s->displayName(),
            'email' => $s->email,
            'phone' => trim(($s->phone_cc ?? '').' '.($s->phone ?? '')),
            'destination_country' => $s->destination_country,
            'intake' => trim(($s->intake_month ?? '').' '.($s->intake_year ?? '')),
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
