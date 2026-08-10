<?php

namespace Tests\Feature;

use App\Enums\ApplicationReviewStatus;
use App\Enums\UserStatus;
use App\Mail\AccountExistsMail;
use App\Mail\OtpMail;
use App\Models\Partner\PartnerAgency;
use App\Models\Partner\PartnerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'agency' => 'Acme Study Abroad', 'country' => 'Bangladesh', 'city' => 'Dhaka',
            'person' => 'Jane Partner', 'email' => 'jane@acme.test', 'dial' => '+880', 'phone' => '1712345678',
            'password' => 'a-strong-partner-pass', 'password_confirmation' => 'a-strong-partner-pass', 'agree' => true,
        ], $over);
    }

    public function test_registration_creates_application_not_a_tenant(): void
    {
        Mail::fake();

        $res = $this->postJson('/api/partner/register', $this->payload())->assertStatus(201)
            ->assertJsonStructure(['flow_id', 'email_masked']);
        $this->assertStringContainsString('•', $res->json('email_masked'));

        $user = User::where('email', 'jane@acme.test')->firstOrFail();
        $this->assertSame(UserStatus::PendingVerification, $user->status);

        $app = PartnerApplication::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(ApplicationReviewStatus::Pending, $app->review_status);
        $this->assertTrue($app->authorised_signatory_attested);   // stored, not just validated
        $this->assertSame('+880', $app->phone_cc);

        // NO live tenant is created at registration
        $this->assertSame(0, PartnerAgency::count());
        Mail::assertSent(OtpMail::class);
    }

    public function test_country_must_be_in_the_allow_list(): void
    {
        Mail::fake();
        $this->postJson('/api/partner/register', $this->payload(['country' => 'Atlantis']))->assertStatus(422);
    }

    public function test_authority_attestation_is_required(): void
    {
        Mail::fake();
        $this->postJson('/api/partner/register', $this->payload(['agree' => false]))->assertStatus(422);
    }

    public function test_password_confirmation_enforced(): void
    {
        Mail::fake();
        $this->postJson('/api/partner/register', $this->payload(['password_confirmation' => 'different']))->assertStatus(422);
    }

    public function test_existing_email_is_enumeration_safe_decoy(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'taken@acme.test']);

        $this->postJson('/api/partner/register', $this->payload(['email' => 'taken@acme.test']))
            ->assertStatus(201)->assertJsonStructure(['flow_id', 'email_masked']);

        $this->assertSame(1, User::where('email', 'taken@acme.test')->count());   // no second account
        $this->assertSame(0, PartnerApplication::count());                        // no application either
        Mail::assertSent(AccountExistsMail::class);
        Mail::assertNotSent(OtpMail::class);
    }

    public function test_duplicate_agency_is_a_soft_signal_to_staff(): void
    {
        Mail::fake();
        // a first, genuine application for the agency + country (new email)
        $this->postJson('/api/partner/register', $this->payload(['email' => 'first@acme.test']))->assertStatus(201);

        // a second, different applicant for the same agency + country
        $this->postJson('/api/partner/register', $this->payload(['email' => 'second@acme.test']))
            ->assertStatus(201);   // NOT a client-visible error

        $second = PartnerApplication::where('work_email', 'second@acme.test')->firstOrFail();
        $this->assertStringContainsString('duplicate', strtolower((string) $second->review_notes));
    }
}
