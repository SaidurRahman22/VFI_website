<?php

namespace Tests\Feature;

use App\Enums\AgencyStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Mail\ResetMail;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PasswordResetService;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-strong-partner-pass';

    /** A partner owner user of an agency with the given status. */
    private function owner(AgencyStatus $status, array $userOver = []): array
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => $status->value]);
        $user = User::factory()->create(array_merge(['password' => self::PW], $userOver));
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner,
            'contact_person_name' => 'Owner One', 'status' => MemberStatus::Active,
        ]));

        return [$agency, $user->fresh()];
    }

    public function test_approved_agency_owner_signs_in_and_binds_tenant(): void
    {
        [$agency, $user] = $this->owner(AgencyStatus::Approved);

        $this->postJson('/api/partner/signin', ['email' => $user->email, 'password' => self::PW])
            ->assertStatus(200)->assertJsonPath('agency.id', $agency->id);

        // the session now grants the console + resolves the greeting per-agency
        $this->getJson('/api/partner/me')->assertStatus(200)->assertJsonPath('agency.name', 'Acme');
    }

    public function test_pending_suspended_rejected_agencies_cannot_sign_in(): void
    {
        foreach ([AgencyStatus::PendingReview, AgencyStatus::Suspended, AgencyStatus::Rejected] as $status) {
            [, $user] = $this->owner($status, ['email' => 'x'.$status->value.'@acme.test']);
            $this->postJson('/api/partner/signin', ['email' => $user->email, 'password' => self::PW])
                ->assertStatus(403);   // review-gate, not a live tenant
            $this->assertGuest();
        }
    }

    public function test_wrong_password_is_generic_401(): void
    {
        [, $user] = $this->owner(AgencyStatus::Approved);
        $this->postJson('/api/partner/signin', ['email' => $user->email, 'password' => 'nope'])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $this->postJson('/api/partner/signin', ['email' => 'ghost@nowhere.test', 'password' => 'nope'])
            ->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_logout_ends_the_session(): void
    {
        [, $user] = $this->owner(AgencyStatus::Approved);
        $this->postJson('/api/partner/signin', ['email' => $user->email, 'password' => self::PW])->assertStatus(200);
        $this->getJson('/api/partner/me')->assertStatus(200);

        $this->postJson('/api/partner/logout')->assertStatus(200);
        $this->getJson('/api/partner/me')->assertStatus(401);
    }

    public function test_forgot_is_enumeration_safe_and_sends_partner_link(): void
    {
        Mail::fake();
        [, $user] = $this->owner(AgencyStatus::Approved);

        $known = $this->postJson('/api/partner/password/forgot', ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson('/api/partner/password/forgot', ['email' => 'nobody@acme.test'])->assertStatus(202);
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Mail::assertSent(ResetMail::class, fn (ResetMail $m) => str_contains($m->url, 'vfi-partner-reset.html?token='));
    }

    public function test_reset_revokes_every_session_across_agencies(): void
    {
        [, $user] = $this->owner(AgencyStatus::Approved);
        // two live DB sessions for this user (e.g. two agencies / devices)
        DB::table('sessions')->insert([
            ['id' => 's1', 'user_id' => $user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 's2', 'user_id' => $user->id, 'ip_address' => '2.2.2.2', 'user_agent' => 'y', 'payload' => '', 'last_activity' => time()],
        ]);

        $raw = app(PasswordResetService::class)->request($user, null)['token'];
        $this->postJson('/api/partner/password/reset/submit', [
            'token' => $raw, 'password' => 'a-brand-new-partner-pass', 'password_confirmation' => 'a-brand-new-partner-pass',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-partner-pass', $user->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());   // logged out everywhere
    }

    public function test_disabled_seat_cannot_sign_in_even_if_agency_approved(): void
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh', 'status' => AgencyStatus::Approved->value]);
        $user = User::factory()->create(['password' => self::PW]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create([
            'agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner,
            'status' => MemberStatus::Disabled,
        ]));

        $this->postJson('/api/partner/signin', ['email' => $user->email, 'password' => self::PW])->assertStatus(403);
    }
}
