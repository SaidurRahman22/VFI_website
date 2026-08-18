<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SeatRole;
use App\Enums\StudentSource;
use App\Mail\OtpMail;
use App\Models\Partner\AgencyReferralLink;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerAgencyMember;
use App\Models\Partner\ReferralSignup;
use App\Models\Student\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerReferralTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:PartnerAgency,1:User} */
    private function agencyOwner(string $name): array
    {
        $agency = PartnerAgency::create(['legal_name' => $name, 'country' => 'Bangladesh']);
        $user = User::factory()->create();
        UserRole::create(['user_id' => $user->id, 'role' => Role::PartnerOwner, 'agency_id' => $agency->id, 'granted_at' => now()]);
        // Bound first, exactly as production does: the members table carries
        // RLS FORCE, so a cold INSERT is refused on Postgres.
        TenantScope::runAs((int) $agency->id, fn () => PartnerAgencyMember::create(['agency_id' => $agency->id, 'user_id' => $user->id, 'seat_role' => SeatRole::Owner, 'status' => MemberStatus::Active]));

        return [$agency, $user->fresh()];
    }

    private function asPartner(User $user, int $agencyId): self
    {
        return $this->actingAs($user)->withSession(['active_scope' => 'partner', 'active_partner_agency_id' => $agencyId]);
    }

    /** Register a student carrying a ref, verify the OTP, return nothing. */
    private function registerAndVerify(string $email, ?string $ref): void
    {
        $body = ['name' => 'Q R', 'email' => $email, 'password' => 'a-strong-live-pass', 'cc' => '+880', 'phone' => '1712345678', 'agree' => true];
        if ($ref !== null) {
            $body['ref'] = $ref;
        }
        $flow = $this->postJson('/api/register', $body)->json('flow_id');
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->postJson('/api/verify', ['flow_id' => $flow, 'code' => $code])->assertJsonPath('ok', true);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_referral_link_is_opaque_revocable_and_has_a_real_qr(): void
    {
        [$agency, $user] = $this->agencyOwner('Acme');

        $res = $this->asPartner($user, $agency->id)->getJson('/api/partner/referral-link')->assertStatus(200);
        $slug = $res->json('slug');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $slug);           // opaque, unguessable
        $this->assertStringContainsString('ref='.$slug, $res->json('url'));
        $this->assertStringContainsString('<svg', $res->json('qr_svg'));            // a real QR image

        // regenerate revokes the old slug
        $new = $this->asPartner($user, $agency->id)->postJson('/api/partner/referral-link/regenerate', [])->json('slug');
        $this->assertNotSame($slug, $new);
        $this->getJson('/api/referral/'.$slug)->assertStatus(404);                  // old revoked
        $this->getJson('/api/referral/'.$new)->assertStatus(200)->assertJsonPath('agency_name', 'Acme');
    }

    public function test_guessing_or_altering_a_slug_fails(): void
    {
        $this->getJson('/api/referral/does-not-exist')->assertStatus(404);
    }

    public function test_new_signup_via_qr_is_attributed_only_after_verification(): void
    {
        Mail::fake();
        [$agency, $user] = $this->agencyOwner('Acme');
        $slug = $this->asPartner($user, $agency->id)->getJson('/api/partner/referral-link')->json('slug');

        // register with the ref — a pending signup exists, but NOT yet converted
        $flow = $this->postJson('/api/register', ['name' => 'New Q', 'email' => 'newq@x.test', 'password' => 'a-strong-live-pass', 'cc' => '+880', 'phone' => '1712345678', 'agree' => true, 'ref' => $slug])->json('flow_id');
        $signup = ReferralSignup::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($signup->converted_at);                                   // not counted before verify
        $this->assertNull(Student::where('email', 'newq@x.test')->value('agency_id'));   // unowned pre-verify

        // capture the code + verify → attribution counts
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->postJson('/api/verify', ['flow_id' => $flow, 'code' => $code])->assertJsonPath('ok', true);

        $this->assertNotNull($signup->fresh()->converted_at);                       // counted
        $s = Student::where('email', 'newq@x.test')->firstOrFail();
        $this->assertSame($agency->id, $s->agency_id);                             // now owned by the QR agency
        $this->assertSame(StudentSource::QrLink, $s->source);
        $this->assertSame(1, AgencyReferralLink::withoutGlobalScopes()->where('slug', $slug)->value('uses_count'));
    }

    public function test_qr_never_re_parents_a_student_owned_by_another_agency(): void
    {
        Mail::fake();
        [$agencyA, $userA] = $this->agencyOwner('A');
        [$agencyB] = $this->agencyOwner('B');
        // B already owns a lead with this email (no login yet)
        Student::create(['agency_id' => $agencyB->id, 'source' => 'partner_modal', 'email' => 'owned@x.test', 'first_name' => 'Owned', 'student_ref' => 'RB']);
        $slug = $this->asPartner($userA, $agencyA->id)->getJson('/api/partner/referral-link')->json('slug');

        // The person self-registers via A's QR → resolveFor adopts B's lead row;
        // A must NOT be able to claim it.
        $this->registerAndVerify('owned@x.test', $slug);

        $s = Student::where('email', 'owned@x.test')->firstOrFail();
        $this->assertSame($agencyB->id, $s->agency_id);                            // still B's — never re-parented
        $this->assertSame(0, ReferralSignup::withoutGlobalScopes()->count());      // no attribution to A
    }

    public function test_qr_claims_an_unowned_self_signup(): void
    {
        Mail::fake();
        [$agency, $user] = $this->agencyOwner('Acme');
        // a prior self-signup (unowned)
        $this->registerAndVerify('selfsignup@x.test', null);
        $this->assertNull(Student::where('email', 'selfsignup@x.test')->value('agency_id'));

        // now they arrive via the QR and re-verify → claimed
        $slug = $this->asPartner($user, $agency->id)->getJson('/api/partner/referral-link')->json('slug');
        $this->registerAndVerify('selfsignup@x.test', $slug);

        $s = Student::where('email', 'selfsignup@x.test')->firstOrFail();
        $this->assertSame($agency->id, $s->agency_id);
        $this->assertSame(1, ReferralSignup::withoutGlobalScopes()->whereNotNull('converted_at')->count());
    }

    public function test_revoked_link_before_verification_does_not_attribute(): void
    {
        Mail::fake();
        [$agency, $user] = $this->agencyOwner('Acme');
        $slug = $this->asPartner($user, $agency->id)->getJson('/api/partner/referral-link')->json('slug');

        $flow = $this->postJson('/api/register', ['name' => 'R', 'email' => 'r@x.test', 'password' => 'a-strong-live-pass', 'cc' => '+880', 'phone' => '1712345678', 'agree' => true, 'ref' => $slug])->json('flow_id');
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        // link revoked between register and verify
        AgencyReferralLink::withoutGlobalScopes()->where('slug', $slug)->update(['revoked_at' => now()]);
        $this->postJson('/api/verify', ['flow_id' => $flow, 'code' => $code])->assertJsonPath('ok', true);

        $this->assertNull(Student::where('email', 'r@x.test')->value('agency_id'));   // not attributed
    }
}
