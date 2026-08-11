<?php

namespace App\Services;

use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramIntake;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8C — rebuilds the flat `program_search` table from the relational
 * catalogue (docs §2, §4). One row per program-intake. The ~40 facets are
 * encoded as space-padded `flags` tokens (portable + injection-proof — the
 * search query only ever binds `'% token %'`), including explicit WAIVER tokens
 * for the negative filters. Intakes past their deadline / closed are flagged
 * `is_stale` and hidden by the search endpoint.
 *
 * Rebuild is atomic with NO empty-result window: the delete+insert runs in one
 * transaction, so concurrent readers see the previous rows until commit
 * (Postgres MVCC / SQLite transaction isolation). At ~500k rows this scales to a
 * rename-swap; documented in the ADR.
 */
class SearchIndexer
{
    public function rebuild(): int
    {
        $rows = [];
        $now = now();

        Program::with(['institution', 'intakes', 'requirements'])->chunk(400, function ($chunk) use (&$rows, $now) {
            foreach ($chunk as $program) {
                if (! $program->institution) {
                    continue;
                }
                $flags = ' '.implode(' ', $this->flagsFor($program)).' ';
                $blob = mb_strtolower($program->title.' '.$program->institution->name);
                $tat = $this->tatDays($program->institution->offer_tat_band);

                foreach ($program->intakes as $intake) {
                    $rows[] = [
                        'program_id' => $program->id,
                        'institution_id' => $program->institution_id,
                        'title' => $program->title,
                        'university_name' => $program->institution->name,
                        'country' => $program->institution->country,
                        'province_state' => $program->institution->province_state,
                        'level' => $program->level,
                        'study_area' => $program->study_area,
                        'discipline_area' => $program->discipline_area,
                        'duration_band' => $program->duration_band,
                        'tuition_fee_minor' => $program->tuition_fee_minor,
                        'tuition_currency' => $program->tuition_currency,
                        'application_deadline_at' => optional($intake->application_deadline_at)->toDateString(),
                        'offer_tat_days' => $tat,
                        'intake_month' => $intake->intake_month,
                        'intake_year' => $intake->intake_year,
                        'season_label' => $intake->season_label,
                        'search_blob' => $blob,
                        'flags' => $flags,
                        'is_stale' => $this->isStale($program, $intake) ? 1 : 0,
                        'source' => $program->source,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        });

        DB::transaction(function () use ($rows) {
            DB::table('program_search')->delete();
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('program_search')->insert($batch);
            }
        });

        return count($rows);
    }

    /** @return list<string> the facet tokens for a program (deduped) */
    private function flagsFor(Program $p): array
    {
        $t = [];
        $i = $p->institution;

        // program attributes
        $p->is_stem && $t[] = 'stem';
        $p->has_coop_internship && $t[] = 'coop';
        $p->scholarship_available && $t[] = 'scholarship';
        $p->application_fee_waiver && $t[] = 'fee_waiver';
        $p->moi_acceptable && $t[] = 'moi';
        $p->esl_elp_available && $t[] = 'esl';
        $p->is_open && $t[] = 'open';
        ((int) $p->application_fee_minor === 0) && $t[] = 'no_app_fee';

        // institution attributes
        $i->is_major_city && $t[] = 'major_city';
        $i->has_own_english_test && $t[] = 'own_english';
        $i->vfi_represented && $t[] = 'vfi';
        $t[] = $i->interview_required ? 'interview_required' : 'no_interview';
        $i->offer_tat_band === 'fast' && $t[] = 'fast_offer';
        $i->offer_acceptance_band === 'high' && $t[] = 'high_acceptance';
        $p->job_demand_band === 'high' && $t[] = 'high_job_demand';
        $i->affordability_band === 'low' && $t[] = 'affordable';
        in_array($i->tuition_deposit_policy, ['none', 'low'], true) && $t[] = 'low_deposit';

        // requirements + WAIVER (negative) tokens
        $englishWaived = $p->moi_acceptable;
        $mathsRequired = false;
        foreach ($p->requirements as $r) {
            $r->is_required && $t[] = 'req_'.$r->test;
            if ($r->waiver_available) {
                $t[] = 'waive_'.$r->test;
                if (in_array($r->test, ['ielts', 'toefl', 'pte', 'duolingo'], true)) {
                    $englishWaived = true;
                }
            }
            $r->maths_required && $mathsRequired = true;
        }
        $englishWaived && $t[] = 'waive_english';
        $mathsRequired ? $t[] = 'req_maths' : $t[] = 'waive_maths';

        return array_values(array_unique($t));
    }

    private function isStale(Program $p, ProgramIntake $intake): bool
    {
        return ! $p->is_open
            || $intake->status !== 'open'
            || ($intake->application_deadline_at !== null && $intake->application_deadline_at->isPast());
    }

    private function tatDays(?string $band): ?int
    {
        return match ($band) {
            'fast' => 7,
            'standard' => 21,
            'slow' => 42,
            default => null,
        };
    }
}
