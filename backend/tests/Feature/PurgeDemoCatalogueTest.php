<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Catalogue\Institution;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramShortlist;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\ContentAuditLog;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
use App\Models\Partner\PartnerAgency;
use App\Models\Student\Student;
use App\Services\SearchIndexer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `catalogue:purge-demo` deletes fabricated catalogue rows next to 41,000 real
 * ones, and `applications.program_id` has no foreign key to stop it dangling a
 * live case. So the guards are the feature, and each one gets its own test:
 * consent, the citation stop, the audited relink, and idempotency.
 *
 * The harness mirrors PartnerProgramSearchTest — the real `programs:ingest
 * --source=seed` builds the fabricated half, so the tests purge exactly what
 * production has to purge, not a hand-rolled imitation of it. The real half is
 * built with Eloquent because there is no offline fixture for the licensed feeds.
 */
class PurgeDemoCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Program $realUs;

    private Program $realDe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-15');
        config([
            'catalogue.seed.universities_per_country' => 1,
            'catalogue.seed.programs_per_university' => 2,
            'catalogue.seed.base_year' => 2026,
        ]);

        // 5 countries x 1 university x 2 programmes = 10 fabricated programmes.
        $this->artisan('programs:ingest', ['--source' => 'seed', '--no-index' => true])->assertSuccessful();

        $this->realUs = $this->realProgram('scorecard', 'United States', 'Arizona State University', rich: true);
        $this->realDe = $this->realProgram('daad', 'Germany', 'TU Munich', rich: false);

        app(SearchIndexer::class)->rebuild();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- harness

    /** A real (licensed-feed) institution + programme. `rich` decides completeness. */
    private function realProgram(string $source, string $country, string $name, bool $rich): Program
    {
        $inst = Institution::create([
            'name' => $name,
            'country' => $country,
            'city' => $rich ? 'Tempe' : null,
            'website' => $rich ? 'https://example.test' : null,
            'source' => $source,
            'external_ref' => $source.':'.md5($name),
        ]);

        $program = Program::create([
            'institution_id' => $inst->id,
            'title' => $name.' MSc Computer Science',
            'level' => 'master',
            'study_area' => $rich ? 'it_computing' : null,
            'discipline_area' => $rich ? 'software' : null,
            'duration_band' => $rich ? '2yr' : null,
            'tuition_fee_minor' => $rich ? 3313900 : null,
            'tuition_currency' => $rich ? 'USD' : null,
            'job_demand_band' => $rich ? 'high' : null,
            'source' => $source,
            'external_ref' => $source.':p:'.md5($name),
        ]);

        $program->intakes()->create([
            'intake_month' => 9, 'intake_year' => 2027, 'season_label' => 'fall',
            'application_deadline_at' => '2027-05-01', 'status' => 'open',
        ]);
        if ($rich) {
            $program->intakes()->create([
                'intake_month' => 1, 'intake_year' => 2028, 'season_label' => 'spring',
                'application_deadline_at' => '2027-10-01', 'status' => 'open',
            ]);
            $program->requirements()->create(['test' => 'ielts', 'min_overall' => 6.5, 'is_required' => true]);
        }

        return $program->fresh();
    }

    /**
     * A real programme built to out-score $realUs on the completeness expression
     * the relink target is chosen by: every scored column filled, more intakes,
     * more requirements. Its id is always HIGHER than $realUs', so a test that
     * expects this row back cannot be satisfied by "lowest id" ordering.
     */
    private function denseProgram(string $source, string $country, string $name): Program
    {
        $inst = Institution::create([
            'name' => $name, 'country' => $country, 'city' => 'Somewhere',
            'website' => 'https://dense.test', 'logo_key' => 'logos/dense.png',
            'source' => $source, 'external_ref' => $source.':'.md5($name),
        ]);

        $program = Program::create([
            'institution_id' => $inst->id, 'title' => $name.' MSc Data Science', 'level' => 'master',
            'study_area' => 'it_computing', 'discipline_area' => 'software', 'duration_band' => '2yr',
            'tuition_fee_minor' => 2500000, 'tuition_currency' => 'EUR', 'application_fee_minor' => 9000,
            'job_demand_band' => 'high', 'source' => $source, 'external_ref' => $source.':p:'.md5($name),
        ]);

        foreach ([[9, 2027], [1, 2028], [5, 2028]] as [$month, $year]) {
            $program->intakes()->create([
                'intake_month' => $month, 'intake_year' => $year, 'season_label' => 'fall', 'status' => 'open',
            ]);
        }
        $program->requirements()->create(['test' => 'ielts', 'min_overall' => 6.5, 'is_required' => true]);
        $program->requirements()->create(['test' => 'gre', 'min_overall' => 310, 'is_required' => false]);

        return $program->fresh();
    }

    private function agency(): PartnerAgency
    {
        return PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => 'approved']);
    }

    /** An application citing $program, created as its owning tenant. */
    private function applicationCiting(Program $program): Application
    {
        $agency = $this->agency();

        app(TenantContext::class)->setAgencyId($agency->id);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil'.$program->id.'@acme.test', 'first_name' => 'Pupil',
            'student_ref' => 'R-'.$program->id,
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'program_id' => $program->id, 'institution_id' => $program->institution_id,
            'status' => ApplicationStatus::Submitted->value, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return $this->unscoped($app->id);
    }

    /**
     * A case filed against a university with no programme chosen. Reachable in
     * production: PartnerApplicationController::store takes `program_id` and
     * `institution_id` as independent nullables.
     */
    private function applicationCitingOnly(?Program $program, int $institutionId, string $ref): Application
    {
        $agency = $this->agency();

        app(TenantContext::class)->setAgencyId($agency->id);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil'.$ref.'@acme.test', 'first_name' => 'Pupil', 'student_ref' => 'R-'.$ref,
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'program_id' => $program?->id, 'institution_id' => $institutionId,
            'status' => ApplicationStatus::Submitted->value, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return $this->unscoped($app->id);
    }

    private function seedInstitutionIn(string $country): Institution
    {
        return Institution::where('source', 'seed')->where('country', $country)->orderBy('id')->firstOrFail();
    }

    private function unscoped(int $id): Application
    {
        return Application::withoutGlobalScope(BelongsToAgencyScope::class)->findOrFail($id);
    }

    private function seedProgramIn(string $country): Program
    {
        return Program::where('source', 'seed')
            ->whereHas('institution', fn ($q) => $q->where('country', $country))
            ->orderBy('id')->firstOrFail();
    }

    /** @return array<string,int> table => count of `seed`-sourced rows */
    private function seedCounts(): array
    {
        return [
            'institutions' => Institution::where('source', 'seed')->count(),
            'programs' => Program::where('source', 'seed')->count(),
            'program_search' => DB::table('program_search')->where('source', 'seed')->count(),
            'program_intakes' => DB::table('program_intakes')
                ->join('programs', 'programs.id', '=', 'program_intakes.program_id')
                ->where('programs.source', 'seed')->count(),
            'program_requirements' => DB::table('program_requirements')
                ->join('programs', 'programs.id', '=', 'program_requirements.program_id')
                ->where('programs.source', 'seed')->count(),
        ];
    }

    /** @return array<string,int> the real catalogue, which must survive every run */
    private function realCounts(): array
    {
        return [
            'institutions' => Institution::whereIn('source', ['scorecard', 'daad'])->count(),
            'programs' => Program::whereIn('source', ['scorecard', 'daad'])->count(),
            'program_search' => DB::table('program_search')->whereIn('source', ['scorecard', 'daad'])->count(),
            'program_intakes' => DB::table('program_intakes')
                ->join('programs', 'programs.id', '=', 'program_intakes.program_id')
                ->whereIn('programs.source', ['scorecard', 'daad'])->count(),
            'program_requirements' => DB::table('program_requirements')
                ->join('programs', 'programs.id', '=', 'program_requirements.program_id')
                ->whereIn('programs.source', ['scorecard', 'daad'])->count(),
        ];
    }

    // ------------------------------------------------------------ the harness

    public function test_the_harness_holds_a_mix_of_fabricated_and_real_rows(): void
    {
        $this->assertSame(
            ['institutions' => 5, 'programs' => 10, 'program_search' => 30, 'program_intakes' => 30, 'program_requirements' => 10],
            $this->seedCounts()
        );
        $this->assertSame(
            ['institutions' => 2, 'programs' => 2, 'program_search' => 3, 'program_intakes' => 3, 'program_requirements' => 1],
            $this->realCounts()
        );
    }

    // ----------------------------------------------------------------- guards

    public function test_it_refuses_to_run_with_neither_dry_run_nor_force(): void
    {
        $before = $this->seedCounts();

        $this->artisan('catalogue:purge-demo')
            ->expectsOutputToContain('Refusing to delete anything without explicit consent.')
            ->assertFailed();

        $this->assertSame($before, $this->seedCounts(), 'a bare run must not touch a single row');
    }

    public function test_dry_run_reports_the_plan_and_changes_nothing(): void
    {
        $seed = $this->seedCounts();
        $real = $this->realCounts();

        $this->artisan('catalogue:purge-demo', ['--dry-run' => true])
            ->expectsOutputToContain('Would remove (source = seed):')
            ->expectsOutputToContain('Dry run — nothing was changed.')
            ->assertSuccessful();

        $this->assertSame($seed, $this->seedCounts());
        $this->assertSame($real, $this->realCounts());
    }

    /** --dry-run alongside --force is contradictory; the safe reading must win. */
    public function test_dry_run_wins_over_force(): void
    {
        $seed = $this->seedCounts();

        $this->artisan('catalogue:purge-demo', ['--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('--dry-run overrides --force')
            ->assertSuccessful();

        $this->assertSame($seed, $this->seedCounts());
    }

    // ------------------------------------------------------ the orphan problem

    public function test_it_refuses_to_delete_while_an_application_cites_a_seed_programme(): void
    {
        $seedProgram = $this->seedProgramIn('United Kingdom');
        $app = $this->applicationCiting($seedProgram);
        $before = $this->seedCounts();

        $this->artisan('catalogue:purge-demo', ['--force' => true])
            ->expectsOutputToContain('1 application(s) still cite a seed programme')
            ->expectsOutputToContain('Re-run with --relink to repoint them (audited) before the delete.')
            ->assertFailed();

        $this->assertSame($before, $this->seedCounts(), 'the refusal must be total, not partial');
        $this->assertSame($seedProgram->id, $this->unscoped($app->id)->program_id);
    }

    /** The same stop applies to a dry run: a plan that cannot be carried out is not a pass. */
    public function test_a_dry_run_also_reports_the_citation_stop(): void
    {
        $this->applicationCiting($this->seedProgramIn('Canada'));

        $this->artisan('catalogue:purge-demo', ['--dry-run' => true])
            ->expectsOutputToContain('1 application(s) still cite a seed programme')
            ->assertFailed();

        $this->assertSame(10, Program::where('source', 'seed')->count());
    }

    public function test_relink_prefers_a_real_programme_in_the_same_country(): void
    {
        $realUk = $this->realProgram('scorecard', 'United Kingdom', 'University of Leeds', rich: false);
        $seedProgram = $this->seedProgramIn('United Kingdom');
        $app = $this->applicationCiting($seedProgram);

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $moved = $this->unscoped($app->id);
        $this->assertSame($realUk->id, $moved->program_id, 'a UK case should stay in the UK');
        $this->assertSame($realUk->institution_id, $moved->institution_id,
            'institution_id points at a deleted seed university unless it moves too');
    }

    /**
     * Nothing real in Canada, so the fallback runs. It must prefer `scorecard`
     * even against a DAAD row carrying more data — otherwise completeness alone
     * decides and the brief's "the scorecard programme with the most complete
     * data" is only half honoured.
     */
    public function test_relink_prefers_scorecard_over_a_more_complete_daad_row(): void
    {
        $denseDaad = $this->denseProgram('daad', 'Germany', 'Heidelberg University');
        $app = $this->applicationCiting($this->seedProgramIn('Canada'));

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $moved = $this->unscoped($app->id);
        $this->assertSame($this->realUs->id, $moved->program_id, 'a scorecard row must win on source, not on data volume');
        $this->assertNotSame($denseDaad->id, $moved->program_id);
    }

    /**
     * And among scorecard rows the fullest one wins. The richer row is created
     * after $realUs, so passing this needs the completeness ordering — lowest-id
     * ordering would hand back $realUs.
     */
    public function test_relink_picks_the_most_complete_scorecard_programme_not_the_first(): void
    {
        $denser = $this->denseProgram('scorecard', 'United States', 'Purdue University');
        $app = $this->applicationCiting($this->seedProgramIn('Canada'));

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $moved = $this->unscoped($app->id);
        $this->assertGreaterThan($this->realUs->id, $denser->id, 'the fuller row must be the later one for this to prove anything');
        $this->assertSame($denser->id, $moved->program_id);
    }

    /**
     * `institution_id` is the same kind of bare, unconstrained column as
     * `program_id`. A case naming a fabricated university but no programme is
     * dangled just as silently when its university goes, so it is the same hard
     * stop — the purge must not exit 0 having quietly broken it.
     */
    public function test_it_refuses_while_an_application_cites_only_a_seed_university(): void
    {
        $seedInst = $this->seedInstitutionIn('Ireland');
        $app = $this->applicationCitingOnly(null, $seedInst->id, 'IEONLY');
        $before = $this->seedCounts();

        $this->artisan('catalogue:purge-demo', ['--force' => true])
            ->expectsOutputToContain('1 application(s) still cite a seed programme or university')
            ->assertFailed();

        $this->assertSame($before, $this->seedCounts(), 'the refusal must be total, not partial');
        $this->assertSame($seedInst->id, $this->unscoped($app->id)->institution_id);
    }

    /** With a real programme on the case, its university is the honest replacement. */
    public function test_relink_moves_a_university_only_citation_to_its_programmes_university(): void
    {
        $app = $this->applicationCitingOnly($this->realUs, $this->seedInstitutionIn('Australia')->id, 'AUONLY');

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $moved = $this->unscoped($app->id);
        $this->assertSame($this->realUs->institution_id, $moved->institution_id);
        $this->assertSame($this->realUs->id, $moved->program_id, 'the programme it already cites must not move');

        $audit = ContentAuditLog::where('action', 'demo_purge_university_relink')
            ->where('entity_id', (string) $app->id)->sole();
        $this->assertSame($this->realUs->institution_id, $audit->after['institution_id']);
        $this->assertLessThanOrEqual(64, strlen($audit->action), 'content_audit_log.action is varchar(64)');
    }

    /** With no programme to infer one from, the reference is cleared, not invented. */
    public function test_relink_clears_a_university_only_citation_that_names_no_programme(): void
    {
        $app = $this->applicationCitingOnly(null, $this->seedInstitutionIn('New Zealand')->id, 'NZONLY');

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $moved = $this->unscoped($app->id);
        $this->assertNull($moved->institution_id);
        $this->assertNull($moved->program_id);
        $this->assertSame(0, DB::table('applications')
            ->whereNotNull('institution_id')
            ->whereNotIn('institution_id', DB::table('institutions')->select('id'))->count(),
            'no case may be left pointing at a university that no longer exists');
    }

    public function test_relink_writes_an_audit_row_and_a_status_event(): void
    {
        $seedProgram = $this->seedProgramIn('Australia');
        $app = $this->applicationCiting($seedProgram);

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $audit = ContentAuditLog::where('action', 'demo_purge_program_relink')
            ->where('entity', 'application')->where('entity_id', (string) $app->id)->sole();
        $this->assertSame($seedProgram->id, $audit->before['program_id']);
        $this->assertSame($this->realUs->id, $audit->after['program_id']);
        $this->assertSame('scorecard', $audit->after['program_source']);
        $this->assertLessThanOrEqual(64, strlen($audit->action), 'content_audit_log.action is varchar(64)');

        $event = ApplicationStatusEvent::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('application_id', $app->id)->sole();
        $this->assertSame('system', $event->actor_type->value);
        $this->assertSame('submitted', $event->to_status, 'the status itself must not move');
        $this->assertStringContainsString('demo-catalogue purge', $event->note);
        $this->assertStringContainsString('#'.$seedProgram->id, $event->note);
    }

    // --------------------------------------------------------------- the purge

    public function test_it_removes_every_seed_row_in_fk_safe_order(): void
    {
        $this->artisan('catalogue:purge-demo', ['--force' => true])->assertSuccessful();

        $this->assertSame(
            ['institutions' => 0, 'programs' => 0, 'program_search' => 0, 'program_intakes' => 0, 'program_requirements' => 0],
            $this->seedCounts()
        );
    }

    public function test_real_rows_are_never_deleted(): void
    {
        $real = $this->realCounts();

        $this->artisan('catalogue:purge-demo', ['--force' => true])->assertSuccessful();

        $this->assertSame($real, $this->realCounts());
        $this->assertNotNull(Program::find($this->realUs->id));
        $this->assertNotNull(Program::find($this->realDe->id));
        $this->assertSame(2, Institution::count(), 'only the two real universities should be left');
    }

    public function test_the_search_index_holds_no_seed_rows_afterwards(): void
    {
        $this->artisan('catalogue:purge-demo', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('program_search')->where('source', 'seed')->count());
        // and no index row may outlive its programme, whatever its source says
        $this->assertSame(0, DB::table('program_search')
            ->whereNotIn('program_id', DB::table('programs')->select('id'))->count());
    }

    /** program_shortlists is declared ON DELETE CASCADE; the command asserts it rather than assuming. */
    public function test_shortlists_on_seed_programmes_cascade_away(): void
    {
        $agency = $this->agency();
        app(TenantContext::class)->setAgencyId($agency->id);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'shortlist@acme.test', 'first_name' => 'Pupil', 'student_ref' => 'R-SL',
        ]);
        $seedShortlist = ProgramShortlist::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'program_id' => $this->seedProgramIn('Ireland')->id,
        ]);
        $keptShortlist = ProgramShortlist::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'program_id' => $this->realUs->id,
        ]);

        // A second tenant with no shortlists: the per-tenant count must visit both
        // and still report one row, not one per agency.
        $this->agency();

        $this->assertSame(0, Artisan::call('catalogue:purge-demo', ['--force' => true]));

        // The plan has to SAY so too: on Postgres this table is RLS FORCE with no
        // bypass in its policy, so a tenant-less read of it returns 0 whatever is
        // there, and a report that silently omits destroyed tenant rows is worse
        // than no report. SQLite cannot fail that way — this pins the counting
        // path the production driver needs.
        $this->assertMatchesRegularExpression('/\s1\s+program_shortlists \(cascade\)/', Artisan::output());

        $this->assertNull(ProgramShortlist::find($seedShortlist->id));
        $this->assertNotNull(ProgramShortlist::find($keptShortlist->id));
    }

    /**
     * Requirement: one transaction, so a failure leaves nothing half-done. The
     * dangerous half-state is a committed DELETE beside a rolled-back relink —
     * exactly the dangling case this command exists to prevent — so the fault is
     * injected AFTER the programmes are gone and the whole unit must come back.
     */
    public function test_a_failure_part_way_through_rolls_back_the_deletes_and_the_relink(): void
    {
        $seedProgram = $this->seedProgramIn('United Kingdom');
        $app = $this->applicationCiting($seedProgram);
        $before = $this->seedCounts();

        $fired = false;
        DB::listen(function ($query) use (&$fired) {
            if (! $fired && str_contains($query->sql, 'delete from "programs"')) {
                $fired = true;
                throw new \RuntimeException('injected mid-purge failure');
            }
        });

        $threw = false;
        try {
            $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->run();
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'injected mid-purge failure');
        }

        $this->assertTrue($fired && $threw, 'the fault injection did not reach the programmes delete');
        $this->assertSame($before, $this->seedCounts(), 'a failed purge must delete nothing at all');
        $this->assertSame($seedProgram->id, $this->unscoped($app->id)->program_id, 'the relink must roll back with it');
        $this->assertSame(0, ContentAuditLog::where('action', 'demo_purge_program_relink')->count());
        $this->assertSame(0, ApplicationStatusEvent::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('application_id', $app->id)->count());
    }

    public function test_a_second_run_is_a_no_op(): void
    {
        $this->artisan('catalogue:purge-demo', ['--force' => true])->assertSuccessful();
        $real = $this->realCounts();

        $this->artisan('catalogue:purge-demo', ['--force' => true])
            ->expectsOutputToContain('No seed rows left in the catalogue — nothing to do.')
            ->assertSuccessful();

        $this->assertSame($real, $this->realCounts());
    }

    /** Idempotent for --relink too: the second pass finds nothing left to repoint. */
    public function test_a_second_relink_run_does_not_move_the_case_again(): void
    {
        $app = $this->applicationCiting($this->seedProgramIn('New Zealand'));

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();
        $movedTo = $this->unscoped($app->id)->program_id;

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])
            ->expectsOutputToContain('nothing to do')
            ->assertSuccessful();

        $this->assertSame($movedTo, $this->unscoped($app->id)->program_id);
        $this->assertSame(1, ContentAuditLog::where('action', 'demo_purge_program_relink')->count());
    }

    /**
     * The before/after breakdown is the reviewable part — the whole reason to run
     * this on 41,000 rows and believe the result. Asserted on the rendered table
     * because the first version of it silently dropped every purged source: both
     * snapshots are keyed by table name, so merging them to collect the source
     * labels replaced `before` wholesale and the report showed only survivors.
     */
    public function test_it_reports_counts_by_source_before_and_after(): void
    {
        $this->assertSame(0, Artisan::call('catalogue:purge-demo', ['--force' => true]));
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/institutions\s*\|\s*seed\s*\|\s*5\s*\|\s*0\s*\|\s*-5\s*\|/', $out);
        $this->assertMatchesRegularExpression('/programs\s*\|\s*seed\s*\|\s*10\s*\|\s*0\s*\|\s*-10\s*\|/', $out);
        $this->assertMatchesRegularExpression('/program_search\s*\|\s*seed\s*\|\s*30\s*\|\s*0\s*\|\s*-30\s*\|/', $out);
        $this->assertMatchesRegularExpression('/programs\s*\|\s*scorecard\s*\|\s*1\s*\|\s*1\s*\|\s*\|/', $out);
        $this->assertMatchesRegularExpression('/programs\s*\|\s*daad\s*\|\s*1\s*\|\s*1\s*\|\s*\|/', $out);
    }

    /**
     * Live regression. Every fabricated programme sits in one of the five
     * unlicensed destinations and the real catalogue is US + German, so the old
     * country-first preference never matched and all five citing applications
     * collapsed onto one global winner: an MSc Software Engineering case was
     * repointed at "Natural Resources Conservation And Research". A case that
     * reads as nonsense is not a preserved record - the agency has to recognise
     * its own application afterwards. Subject must outrank both geography and
     * raw completeness.
     */
    public function test_relink_prefers_the_same_subject_over_the_most_complete_row(): void
    {
        // The subject match is deliberately the SPARSEST real row in the
        // catalogue, and denseProgram() outscores everything on completeness
        // while sitting in a different subject. Without the subject preference
        // the dense row wins, which is the bug.
        $sparseMatch = $this->realProgramInArea('scorecard', 'United States', 'Sparse Business School', 'business', 'finance');
        $this->denseProgram('daad', 'Germany', 'Dense Unrelated');

        $demo = Program::where('source', 'seed')->where('study_area', 'business')->orderBy('id')->firstOrFail();
        $application = $this->applicationCiting($demo);

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $this->assertSame(
            $sparseMatch->id,
            (int) $this->unscoped($application->id)->program_id,
            'the case should land in its own subject, not on the most complete row'
        );
        $this->assertSame(
            $sparseMatch->institution_id,
            (int) $this->unscoped($application->id)->institution_id,
            'the university must travel with the programme'
        );
    }

    /** No subject match anywhere: it still falls back rather than leaving a dangle. */
    public function test_relink_still_falls_back_when_no_subject_match_exists(): void
    {
        // Chosen by exclusion rather than by name: setUp only fabricates 10
        // programmes, so no single study area is guaranteed to be present. The
        // only real subjects in this test are realUs' (it_computing / software),
        // so anything outside both has no match at either preference level.
        $demo = Program::where('source', 'seed')
            ->where('study_area', '<>', 'it_computing')
            ->where('discipline_area', '<>', 'software')
            ->orderBy('id')
            ->firstOrFail();
        $application = $this->applicationCiting($demo);

        $this->artisan('catalogue:purge-demo', ['--force' => true, '--relink' => true])->assertSuccessful();

        $landed = (int) $this->unscoped($application->id)->program_id;
        $this->assertContains($landed, [$this->realUs->id, $this->realDe->id],
            'with no subject match it must still land on a real programme');
        $this->assertSame('scorecard', Program::find($landed)->source,
            'the global fallback is scorecard-first');
    }

    /** A real institution + programme in a NAMED subject, deliberately sparse. */
    private function realProgramInArea(string $source, string $country, string $name,
        string $studyArea, string $disciplineArea): Program
    {
        $inst = Institution::create([
            'name' => $name, 'country' => $country,
            'source' => $source, 'external_ref' => $source.':'.md5($name),
        ]);

        $program = Program::create([
            'institution_id' => $inst->id,
            'title' => $name.' programme',
            'level' => 'master',
            'study_area' => $studyArea,
            'discipline_area' => $disciplineArea,
            'source' => $source,
            'external_ref' => $source.':p:'.md5($name),
        ]);

        $program->intakes()->create([
            'intake_month' => 9, 'intake_year' => 2027, 'season_label' => 'fall',
            'application_deadline_at' => '2027-05-01', 'status' => 'open',
        ]);

        return $program->fresh();
    }
}
