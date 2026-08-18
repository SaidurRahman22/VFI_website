<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\PartnerAgency;
use App\Models\Student\Student;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Makes the Postgres CI leg prove it is actually on Postgres, and that row-level
 * security is actually in force.
 *
 * Both halves have a real failure mode:
 *
 *  1. phpunit.xml pins DB_CONNECTION=sqlite. PHPUnit's <env> does not overwrite
 *     a variable the environment already sets, so passing DB_CONNECTION=pgsql
 *     from CI works - but if that ever changes, the "Postgres" job would run on
 *     SQLite and report green. A CI that silently tests the wrong database is
 *     worse than no CI, because it is believed.
 *
 *  2. Postgres exempts SUPERUSERS from row-level security entirely, and exempts
 *     a table's owner unless the table is FORCE'd. Connect CI as `postgres` and
 *     every RLS policy is inert while the tests still pass. Production connects
 *     as vfi_app: not a superuser, no BYPASSRLS, owner of the tables, FORCE set.
 *     CI has to match that or it is not testing the thing that broke.
 *
 * Every Postgres-only bug found on this project - a varchar(20) overflow that
 * 500'd every document upload, two RLS wiring faults that rendered staff screens
 * empty - was invisible to the SQLite suite. This is the guard that makes the
 * Postgres leg trustworthy enough to rely on.
 *
 * Skipped unless CI_EXPECT_DRIVER is set, so local SQLite runs are unaffected.
 */
class DatabaseDriverTest extends TestCase
{
    use RefreshDatabase;

    private function expected(): ?string
    {
        $want = env('CI_EXPECT_DRIVER');

        return $want === null || $want === '' ? null : (string) $want;
    }

    public function test_the_suite_is_running_on_the_database_ci_asked_for(): void
    {
        $want = $this->expected();
        if ($want === null) {
            $this->markTestSkipped('CI_EXPECT_DRIVER not set — local run, nothing to pin.');
        }

        $this->assertSame(
            $want,
            DB::connection()->getDriverName(),
            'CI asked for '.$want.' but the suite connected to something else. '
            .'Check whether phpunit.xml is overriding DB_CONNECTION.'
        );
    }

    public function test_postgres_is_not_reached_as_a_superuser(): void
    {
        if ($this->expected() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only.');
        }

        $superuser = DB::selectOne("select current_setting('is_superuser') as v")->v;
        $this->assertSame('off', $superuser,
            'Connected as a superuser: Postgres exempts those from row-level security, '
            .'so every RLS test would pass while enforcing nothing.');

        $bypass = DB::selectOne('select rolbypassrls as v from pg_roles where rolname = current_user')->v;
        $this->assertFalse((bool) $bypass, 'The CI role has BYPASSRLS, which defeats the point.');
    }

    public function test_the_rls_tables_exist_and_are_forced(): void
    {
        if ($this->expected() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only.');
        }

        $rows = DB::select(
            "select c.relname, c.relforcerowsecurity
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = 'public' and c.relrowsecurity"
        );

        $this->assertNotEmpty($rows, 'No table has RLS enabled — the migrations did not apply it.');

        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->relforcerowsecurity,
                $row->relname.' has RLS enabled but not FORCED, so its own owner bypasses it.');
        }

        // The tables that carry tenant data, by name, so a migration that quietly
        // stops applying RLS to one of them is caught rather than assumed.
        $named = array_column($rows, 'relname');
        foreach (['applications', 'application_status_events', 'program_shortlists', 'partner_agency_members'] as $t) {
            $this->assertContains($t, $named, "{$t} lost its row-level security policy.");
        }
    }

    /**
     * The behaviour itself, not just the catalogue: a tenant-less read must come
     * back empty on Postgres even when the Eloquent scope is stood down. This is
     * precisely the shape of the bug that rendered the staff queue empty while
     * seven applications sat in the table.
     */
    public function test_rls_actually_filters_a_read_that_names_no_tenant(): void
    {
        if ($this->expected() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only.');
        }

        $agency = PartnerAgency::create(['legal_name' => 'RLS Probe', 'country' => 'Bangladesh']);
        app(TenantContext::class)->setAgencyId($agency->id);

        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'rls.probe@example.test', 'first_name' => 'Probe', 'student_ref' => 'RLS-1',
        ]);
        Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);

        // Drop the tenant: the ORM scope is gone AND the GUC is gone.
        app(TenantContext::class)->clear();
        DB::statement("select set_config('app.agency_id', '', true)");

        $visible = Application::withoutGlobalScope(BelongsToAgencyScope::class)->count();

        $this->assertSame(0, $visible,
            'A tenant-less read saw rows. RLS is not enforcing, so this leg of CI '
            .'cannot catch the bugs it exists for.');
    }
}
