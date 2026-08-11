<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Models\Catalogue\Program;
use App\Models\Catalogue\ProgramShortlist;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerShortlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-15');
        config([
            'catalogue.seed.universities_per_country' => 2,
            'catalogue.seed.programs_per_university' => 3,
            'catalogue.seed.base_year' => 2026,
        ]);
        $this->artisan('programs:ingest', ['--source' => 'seed'])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]);

        return [$agency, $user->fresh()];
    }

    private function student(int $agencyId, string $email): Student
    {
        return Student::create([
            'agency_id' => $agencyId, 'source' => 'partner_modal',
            'email' => $email, 'first_name' => 'Test', 'student_ref' => 'R'.$agencyId.substr(md5($email), 0, 6),
        ]);
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    public function test_add_and_list_shortlist_for_a_student(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');
        $pid = Program::query()->value('id');

        $this->asPartner($user, $agency->id)
            ->postJson("/api/partner/students/{$s->id}/shortlist", ['program_id' => $pid, 'note' => 'good fit'])
            ->assertStatus(201)
            ->assertJsonPath('shortlist.program_id', $pid)
            ->assertJsonPath('shortlist.note', 'good fit');

        $row = ProgramShortlist::firstOrFail();
        $this->assertSame($agency->id, $row->agency_id);   // from session
        $this->assertSame($user->id, $row->created_by_user_id);

        $this->asPartner($user, $agency->id)->getJson("/api/partner/students/{$s->id}/shortlist")
            ->assertStatus(200)->assertJsonPath('data.0.program_id', $pid)->assertJsonCount(1, 'data');
    }

    public function test_duplicate_add_updates_note_not_duplicates(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');
        $pid = Program::query()->value('id');

        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/shortlist", ['program_id' => $pid])->assertStatus(201);
        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/shortlist", ['program_id' => $pid, 'note' => 'updated'])
            ->assertStatus(200)->assertJsonPath('shortlist.note', 'updated');

        $this->assertSame(1, ProgramShortlist::count());
    }

    public function test_remove_from_shortlist(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $s = $this->student($agency->id, 'stud@a.test');
        $pid = Program::query()->value('id');
        $this->asPartner($user, $agency->id)->postJson("/api/partner/students/{$s->id}/shortlist", ['program_id' => $pid])->assertStatus(201);

        $this->asPartner($user, $agency->id)->deleteJson("/api/partner/students/{$s->id}/shortlist/{$pid}")
            ->assertStatus(200)->assertJsonPath('removed', true);
        $this->assertSame(0, ProgramShortlist::count());
    }

    public function test_cannot_touch_another_agencys_student(): void
    {
        [$agencyA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $studentA = $this->student($agencyA->id, 'a@a.test');
        $pid = Program::query()->value('id');

        // B tries to shortlist for A's student → 404 (never leaks existence)
        $this->asPartner($userB, $agencyB->id)
            ->postJson("/api/partner/students/{$studentA->id}/shortlist", ['program_id' => $pid])
            ->assertStatus(404);
        $this->asPartner($userB, $agencyB->id)
            ->getJson("/api/partner/students/{$studentA->id}/shortlist")
            ->assertStatus(404);

        $this->assertSame(0, ProgramShortlist::count());
    }

    public function test_shortlist_list_is_tenant_scoped(): void
    {
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB, $userB] = $this->agencyOwner('B');
        $sA = $this->student($agencyA->id, 'a@a.test');
        $sB = $this->student($agencyB->id, 'b@b.test');
        $pid = Program::query()->value('id');

        $this->asPartner($userA, $agencyA->id)->postJson("/api/partner/students/{$sA->id}/shortlist", ['program_id' => $pid])->assertStatus(201);
        $this->asPartner($userB, $agencyB->id)->postJson("/api/partner/students/{$sB->id}/shortlist", ['program_id' => $pid])->assertStatus(201);

        // same program shortlisted by both agencies (different students) — no clash
        $this->assertSame(2, ProgramShortlist::withoutGlobalScopes()->count());

        $this->asPartner($userA, $agencyA->id)->getJson("/api/partner/students/{$sA->id}/shortlist")
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_compare_returns_programs_in_order(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $ids = Program::query()->orderBy('id')->limit(3)->pluck('id');

        $res = $this->asPartner($user, $agency->id)
            ->getJson('/api/partner/programs/compare?ids='.$ids->implode(','))
            ->assertStatus(200)->assertJsonCount(3, 'data');
        $this->assertSame($ids->all(), collect($res->json('data'))->pluck('id')->all());
    }

    public function test_compare_requires_ids(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');
        $this->asPartner($user, $agency->id)->getJson('/api/partner/programs/compare')->assertStatus(422);
    }
}
