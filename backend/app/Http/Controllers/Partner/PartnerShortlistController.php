<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramShortlist;
use App\Models\Student\Student;
use App\Support\IntakeLabel;
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
            // The intake the counsellor chose on the card, which is not
            // necessarily the programme's next one and not necessarily the
            // student's own stated preference either. Recording it is the whole
            // point: without it the Apply button guessed.
            'intake_month' => ['nullable', 'string', 'max:20'],
            'intake_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = ProgramShortlist::where('student_id', $student)
            ->where('program_id', $data['program_id'])->first();

        if ($existing) {
            // Saving the same programme again is how a counsellor CHANGES the
            // intake, so these must be written on the update path too — not
            // only on create, which would make the second save look like it
            // worked and silently keep the first choice.
            $existing->update([
                'note' => $data['note'] ?? $existing->note,
                'intake_month' => $data['intake_month'] ?? $existing->intake_month,
                'intake_year' => $data['intake_year'] ?? $existing->intake_year,
            ]);

            return response()->json(['shortlist' => $this->present($existing->load(['program.institution', 'program.intakes']))], 200)
                ->header('Cache-Control', 'no-store');
        }

        $row = new ProgramShortlist;
        $row->forceFill([
            'agency_id' => $agencyId,                 // from SESSION, never the form
            'student_id' => $student,
            'program_id' => $data['program_id'],
            'intake_month' => $data['intake_month'] ?? null,
            'intake_year' => $data['intake_year'] ?? null,
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
            /*
             * `label` rides with each of these so the browser stops building
             * the display string itself. js/portal-data.js was capitalising the
             * season with its own regex — a THIRD copy of what IntakeLabel
             * exists to own, after the students list and the applications list.
             * The parts stay, because the Apply button posts season and year
             * back as data, not as text.
             */
            'next_intake' => $nextIntake
                ? [
                    'month' => $nextIntake->intake_month,
                    'year' => $nextIntake->intake_year,
                    'season' => $nextIntake->season_label,
                    'label' => IntakeLabel::for($nextIntake->season_label, $nextIntake->intake_year),
                ]
                : null,
            /*
             * What the counsellor actually chose, when they chose one.
             *
             * Returned BESIDE next_intake rather than instead of it: a row
             * saved before this existed has no choice recorded, and the screen
             * still has to show it something. The console prefers this and
             * falls back, so the Apply button stops guessing without any
             * backfill of old rows.
             */
            'chosen_intake' => $s->intake_month || $s->intake_year
                ? [
                    'season' => $s->intake_month,
                    'year' => $s->intake_year,
                    'label' => IntakeLabel::for($s->intake_month, $s->intake_year),
                ]
                : null,
        ];
    }
}
