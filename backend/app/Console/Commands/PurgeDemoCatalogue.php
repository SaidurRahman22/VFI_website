<?php

namespace App\Console\Commands;

use App\Enums\ActorType;
use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\ContentAuditLog;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Support\RlsBypass;
use App\Support\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Remove the fabricated catalogue (docs §1 `source = seed`).
 *
 * `programs:ingest --source=seed` invents universities and programmes for the
 * five destinations with no licensed feed, and the client would rather ship ten
 * real universities than a database padded with invented tuition. This takes the
 * fabrications back out.
 *
 * Two things make that riskier than `delete where source = 'seed'`:
 *
 *  - `applications.program_id` AND `applications.institution_id` are BARE
 *    integers with no foreign key, so the database will not stop a delete from
 *    dangling a live case. Submitted applications cite seed programmes today,
 *    and the case detail renders `program: null` once the row is gone. Either
 *    column citing something this purge removes is therefore a hard stop unless
 *    --relink is given, and --relink leaves a trail on both the content audit
 *    log and the application's own append-only history.
 *  - `program_shortlists` is supposed to cascade. That is asserted after the
 *    delete rather than trusted, because a missing cascade is the same silent
 *    dangle, and the assertion is what rolls the transaction back.
 *
 * Destructive by explicit consent only: with neither --dry-run nor --force the
 * command refuses, so nobody purges the catalogue out of shell history.
 */
class PurgeDemoCatalogue extends Command
{
    protected $signature = 'catalogue:purge-demo
        {--dry-run : print exactly what would go, per table, and change nothing}
        {--force : consent to the destructive run; without this (or --dry-run) the command refuses}
        {--relink : repoint applications citing a demo programme at a real one instead of refusing}';

    protected $description = 'Remove the fabricated (source=seed) catalogue rows, keeping only real feed data';

    /**
     * The fabricated source label. Deliberately NOT an option: no flag should be
     * able to aim this command at `scorecard` or `daad`.
     */
    private const DEMO_SOURCE = 'seed';

    /** Both fit content_audit_log.action (varchar 64) — see ContentAuditLogWidthTest. */
    private const AUDIT_ACTION = 'demo_purge_program_relink';

    private const AUDIT_ACTION_INSTITUTION = 'demo_purge_university_relink';

    /**
     * Every table holding a `program_id`. The explicit deletes below cover the
     * first three; the rest are declared ON DELETE CASCADE, and assertNoDangling()
     * proves that for all of them before the transaction may commit.
     */
    private const PROGRAM_CHILDREN = [
        'program_search', 'program_intakes', 'program_requirements',
        'program_shortlists', 'program_label_map', 'program_nationality_rules',
    ];

    /** Tables in the before/after breakdown => where each one's `source` lives. */
    private const COUNTED = [
        'institutions' => 'own',
        'programs' => 'own',
        'program_intakes' => 'program',
        'program_requirements' => 'program',
        'program_search' => 'own',
        'program_shortlists' => 'program',
    ];

    /**
     * How complete a programme's data is, used to choose the replacement for a
     * relinked application. A fixed expression over our own columns — no user
     * input reaches it — and portable between SQLite (tests) and Postgres (prod).
     */
    private const COMPLETENESS = '(case when programs.tuition_fee_minor is not null then 1 else 0 end'
        .' + case when programs.tuition_currency is not null then 1 else 0 end'
        .' + case when programs.study_area is not null then 1 else 0 end'
        .' + case when programs.discipline_area is not null then 1 else 0 end'
        .' + case when programs.duration_band is not null then 1 else 0 end'
        .' + case when programs.application_fee_minor is not null then 1 else 0 end'
        .' + case when programs.job_demand_band is not null then 1 else 0 end'
        .' + case when institutions.city is not null then 1 else 0 end'
        .' + case when institutions.website is not null then 1 else 0 end'
        .' + case when institutions.logo_key is not null then 1 else 0 end'
        .' + (select count(*) from program_intakes where program_intakes.program_id = programs.id)'
        .' + (select count(*) from program_requirements where program_requirements.program_id = programs.id))';

    /** Cached global fallback, so 41k programmes are not re-scored per application. */
    private ?object $fallbackTarget = null;

    private bool $fallbackResolved = false;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Refusing to delete anything without explicit consent.');
            $this->line('  --dry-run  show the plan and change nothing');
            $this->line('  --force    carry the plan out');

            return self::FAILURE;
        }
        if ($dryRun && $force) {
            // Safer reading of a contradictory instruction: report, do not delete.
            $this->warn('--dry-run overrides --force: nothing will be deleted.');
            $force = false;
        }

        $before = $this->countsBySource();
        $programIds = Program::where('source', self::DEMO_SOURCE)->orderBy('id')->pluck('id')->all();
        $plan = $this->buildPlan($programIds);

        if (array_sum($plan['delete']) === 0 && $plan['citations'] === []) {
            $this->info('No '.self::DEMO_SOURCE.' rows left in the catalogue — nothing to do.');
            $this->newLine();
            $this->reportCounts($before, $before);

            return self::SUCCESS;
        }

        $this->printPlan($plan);
        $this->warnAboutPreExistingOrphans($programIds);

        if ($plan['citations'] !== [] && ! $this->option('relink')) {
            $this->newLine();
            $this->error(count($plan['citations']).' application(s) still cite a '.self::DEMO_SOURCE
                .' programme or university. Deleting now would dangle them — neither applications.program_id '
                .'nor applications.institution_id has a foreign key.');
            $this->table(
                ['application', 'agency', 'status', 'cites', 'would become'],
                array_map(fn (array $c) => [
                    '#'.$c['id'], $c['agency_id'], $c['status'],
                    $c['cites_program'] ? 'programme #'.$c['program_id'] : 'university #'.$c['institution_id'],
                    $this->replacementLabel($c),
                ], $plan['citations'])
            );
            $this->line('Re-run with --relink to repoint them (audited) before the delete.');

            return self::FAILURE;
        }

        foreach ($plan['citations'] as $citation) {
            if ($citation['cites_program'] && $citation['target'] === null) {
                $this->error('Cannot relink application #'.$citation['id']
                    .': the catalogue holds no real programme to point it at. Ingest a real feed first.');

                return self::FAILURE;
            }
        }

        if (! $force) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $deleted = [];
        // One transaction over the relinks AND the deletes: a failed cascade
        // assertion must not leave a case pointing at a programme that is gone.
        DB::transaction(function () use ($plan, $programIds, &$deleted) {
            foreach ($plan['citations'] as $citation) {
                $this->relink($citation);
            }

            $deleted['program_search'] = $this->purgeSearchRows($programIds);
            $deleted['program_requirements'] = $this->deleteChildren('program_requirements', $programIds);
            $deleted['program_intakes'] = $this->deleteChildren('program_intakes', $programIds);
            $deleted['programs'] = $this->deletePrograms($programIds);
            $this->assertNoDangling($programIds);
            $deleted['institutions'] = $this->deleteEmptyDemoInstitutions();
        });

        $this->newLine();
        foreach ($deleted as $table => $n) {
            $this->line(sprintf('  deleted  %7d  %s', $n, $table));
        }
        if ($plan['citations'] !== []) {
            $this->line(sprintf('  relinked %7d  applications (audited on content_audit_log)', count($plan['citations'])));
        }

        $this->newLine();
        $this->reportCounts($before, $this->countsBySource());
        $this->info('Demo catalogue purged.');

        return self::SUCCESS;
    }

    /**
     * What a destructive run would do. Built before anything is touched, so
     * --dry-run and the real run describe the same work.
     *
     * @param  list<int>  $programIds
     * @return array{delete: array<string,int>, citations: list<array<string,mixed>>}
     */
    private function buildPlan(array $programIds): array
    {
        $institutionIds = $this->demoInstitutionIdsToDelete();
        $delete = [
            'program_search' => $this->countSearchRows($programIds),
            'program_requirements' => $this->countChildren('program_requirements', $programIds),
            'program_intakes' => $this->countChildren('program_intakes', $programIds),
            'program_shortlists (cascade)' => $this->countShortlists($programIds),
            'programs' => count($programIds),
            'institutions' => count($institutionIds),
        ];

        return ['delete' => $delete, 'citations' => $this->citations($programIds, $institutionIds)];
    }

    /**
     * The fabricated universities this purge will remove. A seed institution goes
     * only once nothing real hangs off it, because an ingest can legitimately
     * attach a genuine programme to a row a seed run created.
     *
     * @return list<int>
     */
    private function demoInstitutionIdsToDelete(): array
    {
        return Institution::where('source', self::DEMO_SOURCE)
            ->whereDoesntHave('programs', fn ($q) => $q->where('source', '<>', self::DEMO_SOURCE))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Applications citing anything this purge removes, across every tenant.
     *
     * `applications` carries RLS FORCE and this command runs with no tenant bound,
     * so on Postgres the read returns zero rows without the bypass — and SQLite,
     * which has no RLS at all, would never show that up.
     *
     * BOTH bare columns count. `institution_id` is as unconstrained as
     * `program_id`, and the two are independently nullable (see
     * PartnerApplicationController::store), so a case filed against a fabricated
     * university before a programme was chosen dangles just as silently when its
     * university goes — with nothing in the output to say so.
     *
     * @param  list<int>  $programIds
     * @param  list<int>  $institutionIds
     * @return list<array<string,mixed>>
     */
    private function citations(array $programIds, array $institutionIds): array
    {
        if ($programIds === [] && $institutionIds === []) {
            return [];
        }

        $rows = RlsBypass::run(function () use ($programIds, $institutionIds) {
            $found = collect();
            foreach (array_chunk($programIds, 500) as $chunk) {
                $found = $found->concat($this->citingApplications('program_id', $chunk));
            }
            foreach (array_chunk($institutionIds, 500) as $chunk) {
                $found = $found->concat($this->citingApplications('institution_id', $chunk));
            }

            // A case citing a doomed programme AND a doomed university is one
            // citation, not two.
            return $found->keyBy('id')->sortKeys()->values();
        });

        // The replacement is resolved here, while the seed programme (and so its
        // country) still exists.
        return $rows->map(function (Application $app) use ($programIds) {
            $citesProgram = $app->program_id !== null && in_array((int) $app->program_id, $programIds, true);

            return [
                'id' => (int) $app->id,
                'agency_id' => (int) $app->agency_id,
                'status' => $app->status->value,
                'program_id' => $app->program_id !== null ? (int) $app->program_id : null,
                'institution_id' => $app->institution_id !== null ? (int) $app->institution_id : null,
                'cites_program' => $citesProgram,
                'target' => $citesProgram
                    ? $this->targetFor(...array_values($this->subjectOfProgram((int) $app->program_id)))
                    : null,
                // A university-only citation keeps the programme it already names,
                // so the honest replacement is that programme's real university —
                // and there is none to infer when no programme was chosen.
                'institution_target' => $citesProgram ? null : $this->survivingInstitutionOf($app->program_id),
            ];
        })->all();
    }

    /** @param  list<int>  $ids */
    private function citingApplications(string $column, array $ids): Collection
    {
        return Application::withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereIn($column, $ids)
            ->orderBy('id')
            ->get(['id', 'agency_id', 'student_id', 'program_id', 'institution_id', 'status']);
    }

    /** The university behind a programme this purge is keeping, if the case names one. */
    private function survivingInstitutionOf(?int $programId): ?int
    {
        if ($programId === null) {
            return null;
        }

        $id = DB::table('programs')->where('id', $programId)
            ->where('source', '<>', self::DEMO_SOURCE)->value('institution_id');

        return $id !== null ? (int) $id : null;
    }

    private function countryOfProgram(int $programId): ?string
    {
        return DB::table('programs')
            ->join('institutions', 'institutions.id', '=', 'programs.institution_id')
            ->where('programs.id', $programId)
            ->value('institutions.country');
    }

    /**
     * Country plus subject for the doomed programme, read while it still exists.
     *
     * @return array{country:?string,study_area:?string,discipline_area:?string}
     */
    private function subjectOfProgram(int $programId): array
    {
        $row = DB::table('programs')
            ->join('institutions', 'institutions.id', '=', 'programs.institution_id')
            ->where('programs.id', $programId)
            ->select('institutions.country', 'programs.study_area', 'programs.discipline_area')
            ->first();

        return [
            'country' => $row->country ?? null,
            'study_area' => $row->study_area ?? null,
            'discipline_area' => $row->discipline_area ?? null,
        ];
    }

    /**
     * The programme a dangling application should point at instead.
     *
     * SUBJECT comes before geography. The first version of this preferred the
     * same country and then simply the most complete row anywhere, which on live
     * data repointed an MSc Software Engineering case at "Natural Resources
     * Conservation And Research" — every demo programme is in New Zealand, the
     * real catalogue is US and German, so the country preference never matched
     * and everything collapsed onto one global winner. A case that reads as
     * nonsense is not a preserved record; the agency has to recognise its own
     * application afterwards.
     *
     * Order: same study area AND country -> same study area -> same discipline
     * area -> same country -> most complete anywhere. Deterministic throughout,
     * ties breaking on the lowest id, so a re-run picks the same target.
     */
    private function targetFor(?string $country, ?string $studyArea = null, ?string $disciplineArea = null): ?object
    {
        foreach ([
            [$country, $studyArea, null],
            [null, $studyArea, null],
            [null, null, $disciplineArea],
            [$country, null, null],
        ] as [$c, $sa, $da]) {
            if ($sa === null && $da === null && $c === null) {
                continue;   // that is the global fallback below, not a preference
            }
            if (($hit = $this->bestRealProgram($c, $sa, $da)) !== null) {
                return $hit;
            }
        }

        if (! $this->fallbackResolved) {
            $this->fallbackTarget = $this->bestRealProgram(null);
            $this->fallbackResolved = true;
        }

        return $this->fallbackTarget;
    }

    private function bestRealProgram(?string $country, ?string $studyArea = null, ?string $disciplineArea = null): ?object
    {
        $query = DB::table('programs')
            ->join('institutions', 'institutions.id', '=', 'programs.institution_id')
            ->where('programs.source', '<>', self::DEMO_SOURCE)
            ->where('institutions.source', '<>', self::DEMO_SOURCE)
            ->select([
                'programs.id', 'programs.title', 'programs.institution_id', 'programs.source',
                'institutions.name as university_name', 'institutions.country',
            ])
            ->selectRaw(self::COMPLETENESS.' as completeness')
            // scorecard first per the purge brief, then whichever row carries the
            // most real data, then the lowest id so a re-run picks the same one.
            ->orderByRaw('case when programs.source = ? then 0 else 1 end', ['scorecard'])
            ->orderByDesc('completeness')
            ->orderBy('programs.id');

        if ($country !== null) {
            $query->where('institutions.country', $country);
        }
        if ($studyArea !== null) {
            $query->where('programs.study_area', $studyArea);
        }
        if ($disciplineArea !== null) {
            $query->where('programs.discipline_area', $disciplineArea);
        }

        return $query->first();
    }

    /** How the refusal table and the console line describe a citation's replacement. */
    private function replacementLabel(array $citation): string
    {
        if (! $citation['cites_program']) {
            return $citation['institution_target'] === null
                ? 'university cleared (no programme to infer one from)'
                : 'university #'.$citation['institution_target'];
        }

        return $citation['target']
            ? '#'.$citation['target']->id.' '.$citation['target']->title
            : 'NO REAL PROGRAMME AVAILABLE';
    }

    /** @param  array<string,mixed>  $citation */
    private function relink(array $citation): void
    {
        [$action, $changes, $after, $note] = $citation['cites_program']
            ? $this->programMove($citation)
            : $this->universityMove($citation);

        // applications / application_status_events are RLS FORCE tables whose
        // WITH CHECK carries no bypass by design, and this command holds no
        // tenant. So the write adopts the owning agency, exactly as a staff write
        // does (App\Services\ApplicationReviewService) — without it the UPDATE
        // matches zero rows on Postgres and the event INSERT is rejected
        // outright, neither of which SQLite can show us.
        TenantScope::runAs($citation['agency_id'], function () use ($citation, $changes, $after, $action, $note) {
            // Queried through the model so the BelongsToAgency scope stays ON:
            // the row must belong to the agency we just adopted.
            $affected = Application::query()->whereKey($citation['id'])->update($changes);

            if ($affected !== 1) {
                throw new RuntimeException('Could not repoint application #'.$citation['id']
                    .' (agency '.$citation['agency_id'].'): '.$affected.' rows matched. Rolling back.');
            }

            ApplicationStatusEvent::create([
                'application_id' => $citation['id'],
                'agency_id' => $citation['agency_id'],
                'from_status' => $citation['status'],
                'to_status' => $citation['status'],   // an annotation, not a pipeline move
                'occurred_at' => now(),
                'actor_type' => ActorType::System,
                'note' => $note,
            ]);

            ContentAuditLog::record(
                $action,
                'application',
                (string) $citation['id'],
                ['program_id' => $citation['program_id'], 'institution_id' => $citation['institution_id']],
                $after,
            );
        });

        $this->line('  relinked application #'.$citation['id'].' -> '.$this->replacementLabel($citation));
    }

    /**
     * A case citing a fabricated programme moves to a real one — and its
     * `institution_id` has to travel with it, or the university reference dangles
     * on exactly the row we just repaired.
     *
     * @param  array<string,mixed>  $citation
     * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}
     */
    private function programMove(array $citation): array
    {
        $target = $citation['target'];

        return [
            self::AUDIT_ACTION,
            ['program_id' => $target->id, 'institution_id' => $target->institution_id],
            [
                'program_id' => (int) $target->id,
                'institution_id' => (int) $target->institution_id,
                'program_source' => $target->source,
                'country' => $target->country,
            ],
            'Programme reference migrated during the demo-catalogue purge: fabricated programme #'
                .$citation['program_id'].' was removed, so this case now cites programme #'.$target->id
                .' — '.$target->title.' at '.$target->university_name.'. The declared intake was left as it was.',
        ];
    }

    /**
     * A case citing only a fabricated university keeps its programme; the
     * university follows that programme, or is cleared when the case names none.
     * Clearing beats inventing: the column is nullable, and picking some
     * unrelated real university would put a claim on the case nobody made.
     *
     * @param  array<string,mixed>  $citation
     * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}
     */
    private function universityMove(array $citation): array
    {
        $target = $citation['institution_target'];

        return [
            self::AUDIT_ACTION_INSTITUTION,
            ['institution_id' => $target],
            ['program_id' => $citation['program_id'], 'institution_id' => $target],
            'University reference migrated during the demo-catalogue purge: fabricated university #'
                .$citation['institution_id'].' was removed, so this case now cites '
                .($target === null
                    ? 'no university — it names no programme to infer a real one from.'
                    : 'university #'.$target.', the one behind the programme it already cites.'),
        ];
    }

    /** @param  list<int>  $programIds */
    private function countSearchRows(array $programIds): int
    {
        return $this->searchRowQuery($programIds)->count();
    }

    /** @param  list<int>  $programIds */
    private function purgeSearchRows(array $programIds): int
    {
        return $this->searchRowQuery($programIds)->delete();
    }

    /**
     * Index rows are derived, so both definitions of "demo" apply: a row built
     * from a demo programme, and a row still stamped `source = seed` by an older
     * build whose programme has since changed hands.
     *
     * @param  list<int>  $programIds
     */
    private function searchRowQuery(array $programIds): Builder
    {
        return DB::table('program_search')->where(function ($q) use ($programIds) {
            $q->where('source', self::DEMO_SOURCE);
            foreach (array_chunk($programIds, 500) as $chunk) {
                $q->orWhereIn('program_id', $chunk);
            }
        });
    }

    /** @param  list<int>  $programIds */
    private function countChildren(string $table, array $programIds, ?int $agencyId = null): int
    {
        $n = 0;
        foreach (array_chunk($programIds, 500) as $chunk) {
            $n += DB::table($table)->whereIn('program_id', $chunk)
                ->when($agencyId !== null, fn ($q) => $q->where('agency_id', $agencyId))
                ->count();
        }

        return $n;
    }

    /**
     * Shortlists on the doomed programmes — counted one tenant at a time.
     *
     * `program_shortlists` carries RLS FORCE and its policy, unlike the console
     * tables', has NO `app.rls_bypass` branch: 2026_08_18_000002 widened only
     * `applications` and `application_status_events`, deliberately. So RlsBypass
     * cannot see this table at all, and a bypassed read from this tenant-less
     * command returns 0 on Postgres however many rows are really there — which
     * would leave both the plan line and the cascade assertion below quietly
     * vacuous in production, and green on SQLite, which has no RLS to hide
     * anything. Adopting each tenant is the one read the policy admits. The
     * agency filter is explicit rather than left to the policy, so SQLite counts
     * the same rows Postgres does instead of the whole table once per agency.
     *
     * @param  list<int>  $programIds
     */
    private function countShortlists(array $programIds): int
    {
        if ($programIds === []) {
            return 0;
        }

        $n = 0;
        foreach (DB::table('partner_agencies')->orderBy('id')->pluck('id') as $agencyId) {
            $n += TenantScope::runAs(
                (int) $agencyId,
                fn () => $this->countChildren('program_shortlists', $programIds, (int) $agencyId)
            );
        }

        return $n;
    }

    /** @param  list<int>  $programIds */
    private function deleteChildren(string $table, array $programIds): int
    {
        $n = 0;
        foreach (array_chunk($programIds, 500) as $chunk) {
            $n += DB::table($table)->whereIn('program_id', $chunk)->delete();
        }

        return $n;
    }

    /** @param  list<int>  $programIds */
    private function deletePrograms(array $programIds): int
    {
        $n = 0;
        foreach (array_chunk($programIds, 500) as $chunk) {
            $n += Program::whereIn('id', $chunk)->delete();
        }

        return $n;
    }

    /**
     * Prove the cascades actually fired. `program_shortlists` is declared
     * ON DELETE CASCADE, but a shortlist row outliving its programme is the same
     * silent dangle this command exists to prevent, so it is checked rather than
     * assumed — and the throw is what rolls the purge back.
     *
     * @param  list<int>  $programIds
     */
    private function assertNoDangling(array $programIds): void
    {
        foreach (self::PROGRAM_CHILDREN as $table) {
            // Only program_shortlists is RLS-guarded, and RlsBypass is the wrong
            // key for that lock — see countShortlists().
            $left = $table === 'program_shortlists'
                ? $this->countShortlists($programIds)
                : $this->countChildren($table, $programIds);

            if ($left > 0) {
                throw new RuntimeException(
                    "{$table} still holds {$left} row(s) for deleted demo programmes — the expected "
                    .'ON DELETE CASCADE did not fire. Rolling the purge back rather than dangling them.'
                );
            }
        }
    }

    private function deleteEmptyDemoInstitutions(): int
    {
        $ids = Institution::where('source', self::DEMO_SOURCE)->whereDoesntHave('programs')->pluck('id')->all();

        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += Institution::whereIn('id', $chunk)->delete();
        }

        return $n;
    }

    /**
     * A pre-existing dangle (a program_id matching no programme at all) is
     * reported, not fixed: it is not this command's doing, and repointing a case
     * nobody asked about would be a silent edit to live data.
     *
     * @param  list<int>  $programIds
     */
    private function warnAboutPreExistingOrphans(array $programIds): void
    {
        $orphans = RlsBypass::run(fn () => Application::withoutGlobalScope(BelongsToAgencyScope::class)
            ->whereNotNull('program_id')
            ->whereNotIn('program_id', DB::table('programs')->select('id'))
            ->pluck('id')->all());

        $orphans = array_values(array_diff($orphans, $programIds));
        if ($orphans !== []) {
            $this->warn('Note: '.count($orphans).' application(s) already cite a programme that does not exist '
                .'(ids: '.implode(', ', array_slice($orphans, 0, 20)).'). Not this purge — left alone.');
        }
    }

    /** @return array<string, array<string,int>> table => source => count */
    private function countsBySource(): array
    {
        $out = [];

        foreach (self::COUNTED as $table => $sourceOn) {
            if ($table === 'program_shortlists') {
                $out[$table] = $this->shortlistCountsBySource();   // RLS — see countShortlists()

                continue;
            }

            $out[$table] = RlsBypass::run(function () use ($table, $sourceOn) {
                $query = $sourceOn === 'own'
                    ? DB::table($table)->select($table.'.source as source')
                    : DB::table($table)
                        ->join('programs', 'programs.id', '=', $table.'.program_id')
                        ->select('programs.source as source');

                return $query->selectRaw('count(*) as n')->groupBy('source')
                    ->pluck('n', 'source')->map(fn ($n) => (int) $n)->all();
            });
        }

        return $out;
    }

    /**
     * The shortlist leg of the before/after report, per tenant for the reason
     * countShortlists() gives. Without it the report simply omits the table on
     * Postgres and the rows this purge destroys go unmentioned.
     *
     * @return array<string,int>
     */
    private function shortlistCountsBySource(): array
    {
        $totals = [];

        foreach (DB::table('partner_agencies')->orderBy('id')->pluck('id') as $agencyId) {
            $rows = TenantScope::runAs((int) $agencyId, fn () => DB::table('program_shortlists')
                ->join('programs', 'programs.id', '=', 'program_shortlists.program_id')
                ->where('program_shortlists.agency_id', (int) $agencyId)
                ->select('programs.source as source')->selectRaw('count(*) as n')
                ->groupBy('source')->pluck('n', 'source')->all());

            foreach ($rows as $source => $n) {
                $totals[$source] = ($totals[$source] ?? 0) + (int) $n;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, array<string,int>>  $before
     * @param  array<string, array<string,int>>  $after
     */
    private function reportCounts(array $before, array $after): void
    {
        // Union the source labels of BOTH snapshots. Collection::merge() cannot do
        // this: both arrays are keyed by table name, so merging replaces `before`'s
        // per-table map wholesale and every purged source drops off the report.
        $sources = [];
        foreach ([$before, $after] as $snapshot) {
            foreach ($snapshot as $row) {
                $sources += array_fill_keys(array_keys($row), true);
            }
        }
        $sources = array_keys($sources);
        sort($sources);

        $rows = [];
        foreach (array_keys(self::COUNTED) as $table) {
            foreach ($sources as $source) {
                $b = $before[$table][$source] ?? 0;
                $a = $after[$table][$source] ?? 0;
                if ($b === 0 && $a === 0) {
                    continue;
                }
                $rows[] = [$table, $source, $b, $a, $a === $b ? '' : $a - $b];
            }
        }

        $this->table(['table', 'source', 'before', 'after', 'change'], $rows);
    }

    /** @param  array{delete: array<string,int>, citations: list<array<string,mixed>>}  $plan */
    private function printPlan(array $plan): void
    {
        $this->line('Would remove (source = '.self::DEMO_SOURCE.'):');
        foreach ($plan['delete'] as $table => $n) {
            $this->line(sprintf('  %7d  %s', $n, $table));
        }
        if ($plan['citations'] !== []) {
            $this->line(sprintf('  %7d  applications to relink (listed below)', count($plan['citations'])));
        }
    }
}
