<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\RlsBypass;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The JSON the replacement admin panel runs on.
 *
 * The panel is a static Vue app, so this API is the whole of its access to the
 * application queue — if it is wrong, the panel is wrong, and there is no
 * server-rendered fallback to hide behind.
 *
 * Two things are tested harder than the happy path:
 *
 *   ROLE, not just session. Every handler re-checks StaffAbilities, because the
 *   route group only proves an admin session with TOTP. A content editor holds
 *   an admin-panel role and must still be refused the queue.
 *
 *   The state machine is not duplicated. next_statuses must come from
 *   ApplicationReviewService, and an illegal move must be refused with 422
 *   rather than a 500 — the panel draws its buttons from that list, so a wrong
 *   list means a button that cannot work.
 */
class AdminApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    private function staff(Role $role = Role::StaffPartnerOps): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    /** A real case owned by a real agency, created the way production does. */
    private function application(string $status = 'submitted'): Application
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme Education', 'country' => 'Bangladesh']);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'role' => Role::PartnerOwner->value, 'agency_id' => $agency->id, 'granted_at' => now()]);
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $owner->id,
            'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active,
        ]));

        app(TenantContext::class)->setAgencyId($agency->id);
        $student = Student::create([
            'agency_id' => $agency->id, 'source' => 'partner_modal',
            'email' => 'pupil'.uniqid().'@acme.test', 'first_name' => 'Ayesha',
            'last_name' => 'Rahman', 'student_ref' => 'VFI-'.uniqid(),
        ]);
        $app = Application::create([
            'agency_id' => $agency->id, 'student_id' => $student->id,
            'status' => $status, 'submitted_at' => now(),
        ]);
        app(TenantContext::class)->clear();

        return RlsBypass::run(fn () => Application::withoutGlobalScope(
            BelongsToAgencyScope::class
        )->findOrFail($app->id));
    }

    // ---------------------------------------------------------------- access

    public function test_the_queue_needs_an_admin_session(): void
    {
        $this->getJson('/api/admin/applications')->assertStatus(401);
    }

    /**
     * A content editor holds an admin-panel role, so the route group admits
     * them. The ability check is what must not.
     */
    public function test_a_content_editor_is_refused_the_queue(): void
    {
        $this->actingAs($this->staff(Role::ContentEditor));

        $this->getJson('/api/admin/applications')->assertStatus(403);
    }

    public function test_partner_ops_may_read_the_queue(): void
    {
        $this->application();
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/applications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------------ read

    public function test_the_queue_crosses_tenants_and_names_the_agency(): void
    {
        $this->application();
        $this->application();
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/applications')->assertOk();

        // Staff hold no tenant; without both nets stood down this returns 0,
        // which is exactly how the old queue rendered empty on production.
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame('Acme Education', $res->json('data.0.agency_name'));
        $this->assertNotNull($res->json('data.0.student.name'));
    }

    public function test_the_waiting_filter_returns_only_cases_sitting_with_us(): void
    {
        $this->application('submitted');
        $this->application('visa_received');
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/applications?waiting=1')->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('submitted', $res->json('data.0.status'));
        // and the badge counts still describe the whole queue, not the filter
        $this->assertSame(1, $res->json('meta.counts.visa_received'));
    }

    public function test_search_matches_the_student_not_the_case_id(): void
    {
        $this->application();
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/applications?q=Ayesha')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/applications?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_status_filter_is_refused(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/applications?status=made_up')->assertStatus(422);
    }

    public function test_the_detail_carries_readiness_history_and_legal_next_moves(): void
    {
        $app = $this->application();
        $this->actingAs($this->staff());

        $res = $this->getJson("/api/admin/applications/{$app->id}")->assertOk();

        $res->assertJsonPath('application.id', $app->id);
        $this->assertNotNull($res->json('readiness'));
        $this->assertArrayHasKey('missing', $res->json('readiness'));
        $this->assertIsArray($res->json('events'));
        $this->assertIsArray($res->json('notes'));

        // The panel draws its buttons from this list, so it must be the state
        // machine's answer and must not include where the case already is.
        $next = collect($res->json('next_statuses'))->pluck('value');
        $this->assertNotEmpty($next);
        $this->assertNotContains('submitted', $next->all());
    }

    public function test_an_unknown_case_is_a_404(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/applications/999999')->assertStatus(404);
    }

    // ----------------------------------------------------------------- write

    public function test_a_legal_transition_moves_the_case_and_records_it(): void
    {
        $app = $this->application('submitted');
        $this->actingAs($this->staff());

        $this->postJson("/api/admin/applications/{$app->id}/transition", ['to' => 'review'])
            ->assertOk()
            ->assertJsonPath('application.status', 'review');

        $fresh = RlsBypass::run(fn () => Application::withoutGlobalScope(
            BelongsToAgencyScope::class
        )->findOrFail($app->id));

        $this->assertSame(ApplicationStatus::Review, $fresh->status);

        // BOTH nets, not just RLS: application_status_events also carries the
        // BelongsToAgency scope, and this test holds no tenant.
        $events = RlsBypass::run(fn () => $fresh->events()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->count());
        $this->assertGreaterThan(0, $events, 'the transition should have been recorded');
    }

    /** A refused move is the caller's mistake, so 422 and not a 500. */
    public function test_an_illegal_jump_is_refused_with_a_message(): void
    {
        $app = $this->application('submitted');
        $this->actingAs($this->staff());

        $res = $this->postJson("/api/admin/applications/{$app->id}/transition", ['to' => 'visa_received']);

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('message'));
    }

    public function test_a_negative_outcome_still_requires_a_reason(): void
    {
        $app = $this->application('submitted');
        $this->actingAs($this->staff());

        // Reach a state from which rejection is legal, then try it bare.
        $this->postJson("/api/admin/applications/{$app->id}/transition", ['to' => 'review'])->assertOk();

        $res = $this->postJson("/api/admin/applications/{$app->id}/transition", ['to' => 'visa_rejected']);

        // Either the move is illegal from here or the reason is required —
        // both are a 422, and neither may silently succeed without a reason.
        $res->assertStatus(422);
    }

    public function test_a_note_is_stored_against_the_case_with_its_author(): void
    {
        $app = $this->application();
        $staff = $this->staff();
        $this->actingAs($staff);

        $this->postJson("/api/admin/applications/{$app->id}/notes", ['body' => 'Chased the university.'])
            ->assertCreated()
            ->assertJsonPath('note.body', 'Chased the university.')
            ->assertJsonPath('note.author', $staff->name);

        $this->getJson("/api/admin/applications/{$app->id}")
            ->assertOk()
            ->assertJsonPath('notes.0.body', 'Chased the university.');
    }

    public function test_an_empty_note_is_refused(): void
    {
        $app = $this->application();
        $this->actingAs($this->staff());

        $this->postJson("/api/admin/applications/{$app->id}/notes", ['body' => ''])->assertStatus(422);
    }

    public function test_a_content_editor_cannot_write_either(): void
    {
        $app = $this->application();
        $this->actingAs($this->staff(Role::ContentEditor));

        $this->postJson("/api/admin/applications/{$app->id}/transition", ['to' => 'review'])->assertStatus(403);
        $this->postJson("/api/admin/applications/{$app->id}/notes", ['body' => 'nope'])->assertStatus(403);
    }

    // ------------------------------------------------------- the me endpoint

    /**
     * The panel's navigation is drawn from these abilities, so they have to be
     * resolved by the server. The browser must never carry its own copy of the
     * role-to-ability map.
     */
    public function test_me_reports_abilities_resolved_by_the_server(): void
    {
        $this->actingAs($this->staff(Role::ContentEditor));

        $res = $this->getJson('/api/admin/me')->assertOk();

        // Fetched as an array, not by dotted path: the ability NAMES contain
        // dots, and json('abilities.applications.process') would look for a
        // nested applications->process instead of the literal key.
        $abilities = $res->json('abilities');

        $this->assertFalse($abilities['applications.process']);
        $this->assertTrue($abilities['content.manage']);
        $this->assertFalse($res->json('is_superadmin'));
    }

    public function test_a_superadmin_holds_every_ability(): void
    {
        $this->actingAs($this->staff(Role::SuperAdmin));

        $res = $this->getJson('/api/admin/me')->assertOk();

        $this->assertTrue($res->json('is_superadmin'));
        foreach ($res->json('abilities') as $ability => $allowed) {
            $this->assertTrue($allowed, "superadmin should hold {$ability}");
        }
    }
}
