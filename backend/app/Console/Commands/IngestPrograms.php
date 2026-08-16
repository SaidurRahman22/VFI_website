<?php

namespace App\Console\Commands;

use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\TaxonomyTerm;
use App\Services\Ingest\CollegeScorecardSource;
use App\Services\Ingest\DaadSource;
use App\Services\Ingest\IngestSource;
use App\Services\Ingest\SeedSource;
use App\Services\SearchIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8C — pull the program catalogue from a source and rebuild the search index.
 *
 * Security (docs §Security): EVERY constrained field is validated against the
 * served taxonomy allow-list before it is stored — a feed can never inject a
 * novel country/level/test value. Critical fields (country, level) failing
 * validation drop the whole record; soft fields (study_area, duration_band) are
 * nulled; bad intakes/requirements are dropped individually. Upserts are keyed on
 * (source, external_ref) so re-ingest is idempotent, and all writes for one
 * program run in a transaction.
 */
class IngestPrograms extends Command
{
    protected $signature = 'programs:ingest
        {--source=seed : seed | scorecard | daad | all}
        {--fresh : delete this source\'s existing rows first (drops programs pulled from the feed)}
        {--no-index : skip the search-index rebuild}';

    protected $description = 'Ingest the program catalogue from a source and rebuild the search index';

    /** @var array<string, array<string, true>> kind => allowed values */
    private array $allow = [];

    private int $kept = 0;

    private int $skipped = 0;

    public function handle(SearchIndexer $indexer): int
    {
        $this->loadAllowLists();

        foreach ($this->resolveSources((string) $this->option('source')) as $source) {
            if ($this->option('fresh')) {
                $n = Institution::where('source', $source->name())->count();
                Institution::where('source', $source->name())->delete(); // cascades to programs/intakes/requirements
                Program::where('source', $source->name())->delete();     // programs without a wiped institution
                $this->warn("  --fresh: removed {$n} {$source->name()} institutions");
            }

            $this->info("Ingesting from [{$source->name()}] …");
            try {
                $this->ingest($source);
            } catch (\Throwable $e) {
                $this->error("  source [{$source->name()}] failed: {$e->getMessage()}");
                report($e);
            }

            // A source may stop on a provider quota — say so instead of looking done.
            if (method_exists($source, 'stoppedEarly') && ($why = $source->stoppedEarly())) {
                $this->warn("  [{$source->name()}] {$why}");
            }
        }

        $this->line("Records kept: {$this->kept}, skipped (allow-list): {$this->skipped}");

        if (! $this->option('no-index')) {
            $rows = $indexer->rebuild();
            $this->info("Search index rebuilt: {$rows} rows.");
        }

        return self::SUCCESS;
    }

    /** @return list<IngestSource> */
    private function resolveSources(string $opt): array
    {
        $map = [
            'seed' => fn () => new SeedSource,
            'scorecard' => fn () => new CollegeScorecardSource,
            'daad' => fn () => new DaadSource,
        ];

        if ($opt === 'all') {
            return array_map(fn ($f) => $f(), array_values($map));
        }
        if (! isset($map[$opt])) {
            $this->error("Unknown --source=$opt (use: seed | scorecard | daad | all)");

            return [];
        }

        return [$map[$opt]()];
    }

    private function ingest(IngestSource $source): void
    {
        foreach ($source->records() as $rec) {
            $clean = $this->validate($rec);
            if ($clean === null) {
                $this->skipped++;

                continue;
            }

            DB::transaction(function () use ($clean, $source) {
                $inst = Institution::updateOrCreate(
                    ['source' => $source->name(), 'external_ref' => $clean['institution']['external_ref']],
                    ['source' => $source->name()] + $clean['institution'],
                );

                // Soft-fill: published figures from the feed are written ONLY into
                // fields staff have left empty, so re-ingesting never overwrites
                // content someone authored in the admin.
                $patch = [];
                foreach ($clean['soft'] ?? [] as $col => $value) {
                    if (blank($inst->{$col})) {
                        $patch[$col] = $value;
                    }
                }
                if ($patch !== []) {
                    $inst->forceFill($patch)->save();
                }

                $program = Program::updateOrCreate(
                    ['source' => $source->name(), 'external_ref' => $clean['program']['external_ref']],
                    ['source' => $source->name(), 'institution_id' => $inst->id] + $clean['program'],
                );

                // children are fully owned by the feed — replace, don't merge
                $program->intakes()->delete();
                $program->requirements()->delete();
                foreach ($clean['intakes'] as $intake) {
                    $program->intakes()->create($intake);
                }
                foreach ($clean['requirements'] as $req) {
                    $program->requirements()->create($req);
                }
            });

            $this->kept++;
        }
    }

    /**
     * Validate + normalise one record against the taxonomy allow-list.
     * Returns null to reject the record entirely.
     */
    private function validate(array $rec): ?array
    {
        $inst = $rec['institution'] ?? [];
        $prog = $rec['program'] ?? [];

        // critical fields — reject the record if not allow-listed
        $country = trim((string) ($inst['country'] ?? ''));
        $level = (string) ($prog['level'] ?? '');
        if (! $this->allowed('country', $country) || ! $this->allowed('level', $level)) {
            return null;
        }
        if (($inst['name'] ?? '') === '' || ($prog['title'] ?? '') === '' || empty($inst['external_ref']) || empty($prog['external_ref'])) {
            return null;
        }

        // soft fields — null the value if it is not in the vocabulary
        $studyArea = $this->softValue('study_area', $prog['study_area'] ?? null);
        $duration = $this->softValue('duration_band', $prog['duration_band'] ?? null);

        $institution = [
            'name' => $this->str($inst['name'], 180),
            'country' => $country,
            'province_state' => $this->str($inst['province_state'] ?? null, 90),
            'city' => $this->str($inst['city'] ?? null, 90),
            'is_major_city' => (bool) ($inst['is_major_city'] ?? false),
            'has_own_english_test' => (bool) ($inst['has_own_english_test'] ?? false),
            'offer_tat_band' => $this->enum($inst['offer_tat_band'] ?? null, ['fast', 'standard', 'slow']),
            'offer_acceptance_band' => $this->enum($inst['offer_acceptance_band'] ?? null, ['high', 'medium', 'low']),
            'affordability_band' => $this->enum($inst['affordability_band'] ?? null, ['low', 'medium', 'high']),
            'tuition_deposit_policy' => $this->enum($inst['tuition_deposit_policy'] ?? 'standard', ['none', 'low', 'standard']) ?? 'standard',
            'interview_required' => (bool) ($inst['interview_required'] ?? false),
            'vfi_represented' => (bool) ($inst['vfi_represented'] ?? false),
            'external_ref' => $this->str($inst['external_ref'], 120),
        ];

        // Editorial values a feed can supply (real published figures). Kept apart
        // from the columns above because they are only ever SOFT-filled — staff
        // edits in the admin always win over a re-ingest. See ingest().
        $soft = array_filter([
            'website' => $this->str($inst['website'] ?? null, 190),
            'salary_note' => $this->str($inst['salary_note'] ?? null, 190),
            'placement_note' => $this->str($inst['placement_note'] ?? null, 2000),
            'overview_stats_json' => ! empty($inst['overview_stats']) && is_array($inst['overview_stats'])
                ? array_values($inst['overview_stats'])
                : null,
        ], fn ($v) => $v !== null && $v !== []);

        $program = [
            'title' => $this->str($prog['title'], 240),
            'level' => $level,
            'study_area' => $studyArea,
            'discipline_area' => $this->str($prog['discipline_area'] ?? null, 90),
            'duration_band' => $duration,
            'esl_elp_available' => (bool) ($prog['esl_elp_available'] ?? false),
            'tuition_fee_minor' => $this->intOrNull($prog['tuition_fee_minor'] ?? null),
            'tuition_currency' => $this->str($prog['tuition_currency'] ?? null, 3),
            'application_fee_minor' => $this->intOrNull($prog['application_fee_minor'] ?? null),
            'application_fee_currency' => $this->str($prog['application_fee_currency'] ?? null, 3),
            'is_stem' => (bool) ($prog['is_stem'] ?? false),
            'has_coop_internship' => (bool) ($prog['has_coop_internship'] ?? false),
            'scholarship_available' => (bool) ($prog['scholarship_available'] ?? false),
            'application_fee_waiver' => (bool) ($prog['application_fee_waiver'] ?? false),
            'moi_acceptable' => (bool) ($prog['moi_acceptable'] ?? false),
            'job_demand_band' => $this->enum($prog['job_demand_band'] ?? null, ['high', 'medium', 'low']),
            'is_open' => (bool) ($prog['is_open'] ?? true),
            'external_ref' => $this->str($prog['external_ref'], 120),
        ];

        $intakes = [];
        foreach ($rec['intakes'] ?? [] as $in) {
            $season = strtolower(trim((string) ($in['season'] ?? '')));
            if (! $this->allowed('intake', $season)) {
                continue; // drop an intake with an unknown season rather than the whole program
            }
            $month = (int) ($in['month'] ?? 0);
            if ($month < 1 || $month > 12) {
                continue;
            }
            $intakes[] = [
                'intake_month' => $month,
                'intake_year' => (int) ($in['year'] ?? 0),
                'season_label' => $season,
                'application_deadline_at' => $this->date($in['deadline'] ?? null),
                'status' => $this->enum($in['status'] ?? 'open', ['open', 'closed', 'waitlist']) ?? 'open',
            ];
        }
        if ($intakes === []) {
            return null; // a program with no valid intake is not searchable
        }

        $requirements = [];
        foreach ($rec['requirements'] ?? [] as $r) {
            $test = strtolower(trim((string) ($r['test'] ?? '')));
            if (! $this->allowed('test', $test)) {
                continue;
            }
            $requirements[] = [
                'test' => $test,
                'min_overall' => isset($r['min_overall']) ? (float) $r['min_overall'] : null,
                'is_required' => (bool) ($r['is_required'] ?? true),
                'waiver_available' => (bool) ($r['waiver_available'] ?? false),
                'maths_required' => (bool) ($r['maths_required'] ?? false),
            ];
        }

        return compact('institution', 'program', 'intakes', 'requirements', 'soft');
    }

    private function loadAllowLists(): void
    {
        foreach (['country', 'level', 'study_area', 'duration_band', 'intake', 'test'] as $kind) {
            $this->allow[$kind] = TaxonomyTerm::where('kind', $kind)->where('active', true)
                ->pluck('value')->flip()->map(fn () => true)->all();
        }
    }

    private function allowed(string $kind, string $value): bool
    {
        return $value !== '' && isset($this->allow[$kind][$value]);
    }

    private function softValue(string $kind, ?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $this->allowed($kind, (string) $value) ? $value : null;
    }

    private function enum(?string $value, array $allowed): ?string
    {
        $value = $value !== null ? strtolower(trim($value)) : null;

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function str(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int) $value);
    }

    private function date(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
