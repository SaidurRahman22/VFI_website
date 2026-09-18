<?php

namespace Tests\Feature;

use App\Enums\ActorType;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\Partner\Application;
use App\Models\Partner\ApplicationStatusEvent;
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
 * The series behind the dashboard graph.
 *
 * The point of this endpoint is that it answers something the dashboard tiles
 * cannot. Those print the current per-status counts; a chart of the same six
 * numbers would be decoration, which is the thing this client has objected to
 * most. So the two series are ARRIVED and DECIDED, and the tests below are
 * mostly about not conflating them:
 *
 *   ARRIVED must come from submitted_at, not from created_at on a row whose
 *   status happens to be 'submitted' — the latter redraws the arrival line and
 *   calls it activity.
 *
 *   DECIDED must come from application_status_events, because an application's
 *   own row remembers only where it ended up. Counting cases by status would
 *   report a decision on the day the case arrived.
 *
 * Also asserted: the series is gap-filled, so the chart never has to interpret
 * a missing day; and it crosses tenants, because a graph that silently showed
 * one agency's work as the whole business would be worse than no graph.
 */
class AdminApplicationTrendTest extends TestCase
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

    /** A real case owned by a real agency, submitted on a given day. */
    private function application(string $submittedAt, ?string $legalName = null): Application
    {
        $agency = PartnerAgency::create([
            'legal_name' => $legalName ?? ('Acme '.uniqid()),
            'country' => 'Bangladesh',
        ]);
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'role' => Role::PartnerOwner->value,
            'agency_id' => $agency->id, 'granted_at' => now()]);
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
            'status' => 'submitted', 'submitted_at' => $submittedAt,
        ]);
        app(TenantContext::class)->clear();

        return RlsBypass::run(fn () => Application::withoutGlobalScope(
            BelongsToAgencyScope::class
        )->findOrFail($app->id));
    }

    // ---------------------------------------------------------------- access

    public function test_the_trend_needs_an_admin_session(): void
    {
        $this->getJson('/api/admin/applications/trend')->assertStatus(401);
    }

    /** A content editor holds an admin session; the ability check must refuse. */
    public function test_a_content_editor_is_refused_the_trend(): void
    {
        $this->actingAs($this->staff(Role::ContentEditor));

        $this->getJson('/api/admin/applications/trend')->assertStatus(403);
    }

    // ----------------------------------------------------------------- shape

    public function test_it_returns_one_gap_filled_point_per_day(): void
    {
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/applications/trend?days=14')->assertOk();

        $this->assertSame(14, $res->json('days'));
        $this->assertCount(14, $res->json('points'), 'a chart must not have to interpret a missing day');

        foreach ($res->json('points') as $p) {
            $this->assertArrayHasKey('date', $p);
            $this->assertIsInt($p['arrived']);
            $this->assertIsInt($p['decided']);
        }

        // Oldest first, so the chart can plot it without sorting.
        $dates = array_column($res->json('points'), 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertSame(end($dates), now()->toDateString(), 'the last point is today');
    }

    public function test_the_default_window_is_thirty_days(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/applications/trend')->assertOk()->assertJsonPath('days', 30);
    }

    public function test_an_absurd_window_is_refused_rather_than_clamped(): void
    {
        $this->actingAs($this->staff());

        // Silently clamping would draw a chart whose axis disagrees with the
        // control that asked for it.
        $this->getJson('/api/admin/applications/trend?days=4000')
            ->assertStatus(422)->assertJsonValidationErrors('days');
        $this->getJson('/api/admin/applications/trend?days=1')
            ->assertStatus(422)->assertJsonValidationErrors('days');
    }

    // ---------------------------------------------------------------- series

    public function test_arrivals_are_counted_on_the_day_they_were_submitted(): void
    {
        $this->application(now()->subDays(3)->setTime(9, 0)->toDateTimeString());
        $this->application(now()->subDays(3)->setTime(17, 30)->toDateTimeString());
        $this->application(now()->subDay()->toDateTimeString());
        $this->actingAs($this->staff());

        $points = collect($this->getJson('/api/admin/applications/trend?days=7')->json('points'))
            ->keyBy('date');

        $this->assertSame(2, $points[now()->subDays(3)->toDateString()]['arrived']);
        $this->assertSame(1, $points[now()->subDay()->toDateString()]['arrived']);
        $this->assertSame(0, $points[now()->toDateString()]['arrived']);
        $this->assertSame(3, $this->getJson('/api/admin/applications/trend?days=7')->json('totals.arrived'));
    }

    /**
     * The conflation this endpoint exists to avoid. An application that arrived
     * and has not moved must NOT appear as a decision — otherwise the two lines
     * are the same line and the graph says nothing.
     */
    public function test_an_untouched_application_is_an_arrival_and_not_a_decision(): void
    {
        $this->application(now()->subDays(2)->toDateTimeString());
        $this->actingAs($this->staff());

        $this->assertSame(1, $this->getJson('/api/admin/applications/trend?days=7')->json('totals.arrived'));
        $this->assertSame(0, $this->getJson('/api/admin/applications/trend?days=7')->json('totals.decided'));
    }

    public function test_a_decision_is_counted_on_the_day_it_was_made(): void
    {
        $app = $this->application(now()->subDays(6)->toDateTimeString());

        // A case decided today, days after it arrived. Written the way the
        // review service does, under the owning tenant.
        TenantScope::runAs((int) $app->agency_id, fn () => ApplicationStatusEvent::create([
            'agency_id' => $app->agency_id,
            'application_id' => $app->id,
            'from_status' => 'submitted',
            'to_status' => 'review',
            'occurred_at' => now(),
            'actor_type' => ActorType::Staff,
        ]));

        $this->actingAs($this->staff());
        $points = collect($this->getJson('/api/admin/applications/trend?days=7')->json('points'))
            ->keyBy('date');

        $this->assertSame(1, $points[now()->subDays(6)->toDateString()]['arrived']);
        $this->assertSame(0, $points[now()->subDays(6)->toDateString()]['decided'],
            'the decision did not happen on the day it arrived');
        $this->assertSame(1, $points[now()->toDateString()]['decided']);
    }

    /**
     * The trap this endpoint walked into once already.
     *
     * PipelineService writes a status event when an application is first
     * SUBMITTED, not only when staff move it - and that event carries
     * from_status = null. Counting every event as a decision therefore puts one
     * on the arrival day of every case in the system, which makes the two lines
     * identical and the graph worthless.
     *
     * The earlier version of this test missed it because the helper above
     * creates the row directly instead of through the service, so no submission
     * event existed to be miscounted. This writes that event explicitly.
     */
    public function test_the_submission_event_is_not_counted_as_a_decision(): void
    {
        $app = $this->application(now()->subDays(2)->toDateTimeString());

        // Exactly what PipelineService::submit writes: no from_status.
        TenantScope::runAs((int) $app->agency_id, fn () => ApplicationStatusEvent::create([
            'agency_id' => $app->agency_id,
            'application_id' => $app->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'occurred_at' => now()->subDays(2),
            'actor_type' => ActorType::Partner,
            'note' => 'Application created',
        ]));

        $this->actingAs($this->staff());
        $res = $this->getJson('/api/admin/applications/trend?days=7')->assertOk();

        $this->assertSame(1, $res->json('totals.arrived'));
        $this->assertSame(0, $res->json('totals.decided'),
            'an arrival is not a decision, however many events it wrote');
    }

    /**
     * Staff hold no tenant and these tables are scoped twice over. A graph that
     * showed one agency's work as the whole business would mislead worse than
     * no graph at all.
     */
    public function test_the_trend_crosses_every_agency(): void
    {
        $this->application(now()->subDay()->toDateTimeString(), 'Agency One');
        $this->application(now()->subDay()->toDateTimeString(), 'Agency Two');
        $this->actingAs($this->staff());

        $this->assertSame(2, $this->getJson('/api/admin/applications/trend?days=7')->json('totals.arrived'));
    }

    public function test_the_window_decides_what_is_counted(): void
    {
        $this->application(now()->subDays(40)->toDateTimeString());
        $this->actingAs($this->staff());

        // Out of range at 7 days, in range at 60 — the same row either way, so
        // this catches a window that is applied to the axis but not the query.
        $this->assertSame(
            0,
            $this->getJson('/api/admin/applications/trend?days=7')->json('totals.arrived')
        );
        $this->assertSame(
            1,
            $this->getJson('/api/admin/applications/trend?days=60')->json('totals.arrived')
        );
    }
}
