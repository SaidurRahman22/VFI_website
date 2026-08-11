<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramShortlist;
use App\Models\Student\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8E — a partner saves programs to a specific student's shortlist. Every
 * row is tenant-scoped: the owning agency comes from the session (TenantContext
 * → BelongsToAgency stamp + Postgres RLS), never the request, and the student
 * must belong to that agency. Programs are public catalogue data.
 */
class PartnerShortlistController extends Controller
{
    /** GET /api/partner/students/{student}/shortlist */
    public function index(Request $request, int $student): JsonResponse
    {
        $this->ownedStudent($student);

        $rows = ProgramShortlist::where('student_id', $student)
            ->with(['program.institution', 'program.intakes'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ProgramShortlist $s) => $this->present($s))->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /** POST /api/partner/students/{student}/shortlist — add/update a saved program. */
    public function store(Request $request, int $student): JsonResponse
    {
        $this->ownedStudent($student);
        $agencyId = app(TenantContext::class)->agencyId();

        $data = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = ProgramShortlist::where('student_id', $student)
            ->where('program_id', $data['program_id'])->first();

        if ($existing) {
            $existing->update(['note' => $data['note'] ?? $existing->note]);

            return response()->json(['shortlist' => $this->present($existing->load(['program.institution', 'program.intakes']))], 200)
                ->header('Cache-Control', 'no-store');
        }

        $row = new ProgramShortlist;
        $row->forceFill([
            'agency_id' => $agencyId,                 // from SESSION, never the form
            'student_id' => $student,
            'program_id' => $data['program_id'],
            'note' => $data['note'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ])->save();

        return response()->json(['shortlist' => $this->present($row->load(['program.institution', 'program.intakes']))], 201)
            ->header('Cache-Control', 'no-store');
    }

    /** DELETE /api/partner/students/{student}/shortlist/{program} */
    public function destroy(int $student, int $program): JsonResponse
    {
        $this->ownedStudent($student);

        $deleted = ProgramShortlist::where('student_id', $student)
            ->where('program_id', $program)->delete();

        return response()->json(['removed' => (bool) $deleted])->header('Cache-Control', 'no-store');
    }

    /**
     * Resolve a student that belongs to the session agency, or 404. The
     * BelongsToAgency scope on Student already fences by agency; this also 404s a
     * foreign / unknown id so one agency can never probe another's students.
     */
    private function ownedStudent(int $student): Student
    {
        $agencyId = app(TenantContext::class)->agencyId();

        return Student::forAgency($agencyId)->whereKey($student)->firstOr(function () {
            abort(404, 'Student not found.');
        });
    }

    private function present(ProgramShortlist $s): array
    {
        $p = $s->program;
        $nextIntake = $p?->intakes->firstWhere(fn ($i) => ! $i->application_deadline_at || ! $i->application_deadline_at->isPast())
            ?? $p?->intakes->first();

        return [
            'program_id' => $s->program_id,
            'note' => $s->note,
            'saved_at' => optional($s->created_at)->toIso8601String(),
            'title' => $p?->title,
            'university' => $p?->institution?->name,
            'country' => $p?->institution?->country,
            'level' => $p?->level,
            'study_area' => $p?->study_area,
            'tuition' => $p?->tuition_fee_minor !== null
                ? ['minor' => $p->tuition_fee_minor, 'currency' => $p->tuition_currency]
                : null,
            'next_intake' => $nextIntake
                ? ['month' => $nextIntake->intake_month, 'year' => $nextIntake->intake_year, 'season' => $nextIntake->season_label]
                : null,
        ];
    }
}
