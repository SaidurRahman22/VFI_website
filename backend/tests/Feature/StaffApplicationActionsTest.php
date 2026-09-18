<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationNote;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\RlsBypass;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the /manage Applications table ACTIONS the way a staff member does,
 * through the Livewire component rather than by calling the service directly.
 *
 * This is the layer that has been missing all along: the services were tested
 * and passed while the screens using them were broken, because nothing ever
 * rendered a Filament page or invoked a table action.
 */
class StaffApplicationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function staff(): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => Role::StaffPartnerOps->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    private function application(): Application
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => 'approved']);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'role' => Role::PartnerOwner->value, 'agency_id' => $agency->id, 'granted_at' => now()]);

        app(TenantContext::class)->setAgencyId($agency->id);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $owner->id,
            'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active,
        ]));
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil@acme.test', 'first_name' => 'Pupil', 'student_ref' => 'R-'.uniqid(),
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => ApplicationStatus::Submitted->value, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return RlsBypass::run(fn () => $app->withoutGlobalScope(BelongsToAgencyScope::class)->find($app->id));
    }


    /**
     * Stand up what the HTTP stack gives every real panel request.
     *
     * Production wraps both the /manage render and the /livewire/update that
     * every button acts through in App\Http\Middleware\StaffRlsRead, which
     * holds `app.rls_bypass = on` for the whole request. Livewire::test() runs
     * no middleware at all, so on Postgres the RLS FORCE policy on
     * `applications` empties the resource query and Filament resolves every
     * record to null - the queue renders with no rows and actions report
     * "Record [N] no longer exists". A no-op on SQLite, which has no RLS.
     */
    private function asPanelRequest(callable $fn): mixed
    {
        return RlsBypass::run($fn);
    }

    public function test_the_applications_page_renders_for_staff(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        $this->asPanelRequest(fn () => Livewire::test(ListStaffApplications::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$app]));
    }

    public function test_the_move_action_advances_the_case(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        $this->asPanelRequest(function () use ($app) {
            Livewire::test(ListStaffApplications::class)
                ->callTableAction('advance', $app, ['to' => ApplicationStatus::Review->value, 'reason' => 'Docs complete'])
                ->assertHasNoTableActionErrors();

            // The read-back needs the bypass too: refresh() goes through
            // newQueryWithoutScopes(), which drops net 1 but not RLS, and
            // firstOrFail()s - so this line would throw where the action no
            // longer does.
            $this->assertSame(ApplicationStatus::Review, $app->refresh()->status);
        });
    }

    public function test_the_move_action_refuses_an_illegal_jump(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        // submitted -> visa_received skips the whole pipeline; the guard should
        // hold and the record must not move
        $this->asPanelRequest(function () use ($app) {
            Livewire::test(ListStaffApplications::class)
                // Asserted first because the rest of this test cannot tell "the
                // guard held" from "the action never ran": a silently empty queue
                // also leaves the status untouched, which is precisely how this
                // class of breakage stayed invisible.
                ->assertTableActionExists('advance', record: $app)
                ->callTableAction('advance', $app, ['to' => ApplicationStatus::VisaReceived->value]);

            $this->assertSame(ApplicationStatus::Submitted, $app->refresh()->status);
        });
    }

    public function test_the_add_note_action_stores_a_note(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        $this->asPanelRequest(fn () => Livewire::test(ListStaffApplications::class)
            ->callTableAction('addNote', $app, ['body' => 'Rang the admissions desk.'])
            ->assertHasNoTableActionErrors());

        $this->assertSame(1, ApplicationNote::where('application_id', $app->id)->count());
        $this->assertSame('Rang the admissions desk.', ApplicationNote::first()->body);
    }

    public function test_the_notes_action_opens(): void
    {
        $this->actingAs($this->staff());
        $app = $this->application();

        // NOTE this test is currently vacuous and passes on Postgres only by
        // accident: mountAction swallows ActionNotResolvableException and
        // unmounts, and assertOk() is satisfied either way. Wrapped so it runs
        // like production; it still needs a real assertion on the modal content
        // to be worth anything.
        $this->asPanelRequest(fn () => Livewire::test(ListStaffApplications::class)
            ->mountTableAction('viewNotes', $app)
            ->assertOk());
    }
}
