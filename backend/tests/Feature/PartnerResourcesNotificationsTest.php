<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Content\PpDoc;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\PartnerNotification;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PipelineService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerResourcesNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_resources_are_filtered_by_the_server_not_dumped(): void
    {
        [$agency, $user] = $this->agencyOwner('A');
        PpDoc::create(['legacy_id' => 'd1', 'position' => 0, 'country' => 'UK', 'category' => 'Visa', 'title' => 'UK visa guide', 'url' => 'https://x.test/a.pdf']);
        PpDoc::create(['legacy_id' => 'd2', 'position' => 1, 'country' => 'Canada', 'category' => 'Finance', 'title' => 'Canada funding', 'url' => 'https://x.test/b.pdf']);
        PpDoc::create(['legacy_id' => 'd3', 'position' => 2, 'country' => 'UK', 'category' => 'Finance', 'title' => 'UK loans', 'url' => 'https://x.test/c.pdf']);

        // country filter → only UK's two
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?country=UK')
            ->assertStatus(200)->assertJsonCount(2, 'data');
        // country + category
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?country=UK&category=Visa')
            ->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'UK visa guide');
        // keyword
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources?q=funding')
            ->assertStatus(200)->assertJsonCount(1, 'data');
        // facet lists come back for the panels
        $this->asPartner($user, $agency->id)->getJson('/api/partner/resources')
            ->assertJsonCount(3, 'data')->assertJsonPath('countries', ['Canada', 'UK']);
    }

    public function test_notifications_are_tenant_scoped_with_read_state(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        app(TenantContext::class)->setAgencyId($agencyA->id);
        $sA = Student::create(['agency_id' => $agencyA->id, 'source' => 'partner_modal', 'email' => 'a@x.test', 'first_name' => 'A', 'student_ref' => 'RA']);
        app(PipelineService::class)->create($sA, [], $userA->id);   // creates a notification
        app(TenantContext::class)->setAgencyId($agencyB->id);
        $sB = Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'b@x.test', 'first_name' => 'B', 'student_ref' => 'RB']);
        app(PipelineService::class)->create($sB, [], $userB->id);

        // A sees only its own notification, unread
        $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/notifications')
            ->assertStatus(200)->assertJsonPath('meta.total', 1)->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.title', 'Application submitted');

        // mark all read
        $this->asPartner($userA, $agencyA->id)->postJson('/api/partner/notifications/read', [])
            ->assertStatus(200)->assertJsonPath('unread_count', 0);

        // B's notification is untouched (tenant isolation)
        app(TenantContext::class)->setAgencyId($agencyB->id);
        $this->assertSame(1, PartnerNotification::whereNull('read_at')->count());
    }
}
