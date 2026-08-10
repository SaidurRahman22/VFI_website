<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Mail\AccountExistsMail;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Jamie Student', 'email' => 'jamie@example.com',
            'password' => 'correct horse battery', 'cc' => '+880', 'phone' => '171234567',
            'agree' => true,
        ], $over);
    }

    public function test_new_registration_creates_pending_student_and_sends_otp(): void
    {
        Mail::fake();

        $res = $this->postJson('/api/register', $this->payload())->assertStatus(201)
            ->assertJsonStructure(['flow_id', 'email_masked']);

        $this->assertStringContainsString('•', $res->json('email_masked'));   // masked, not raw

        $user = User::where('email', 'jamie@example.com')->firstOrFail();
        $this->assertSame(UserStatus::PendingVerification, $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('+880171234567', $user->phone);
        $this->assertTrue($user->hasRole(Role::Student));
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $user->id, 'document' => 'terms']);
        Mail::assertSent(OtpMail::class);
        Mail::assertNotSent(AccountExistsMail::class);
    }

    public function test_agree_checkbox_is_required(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->payload(['agree' => false]))->assertStatus(422);
    }

    public function test_existing_verified_email_is_enumeration_safe_decoy(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'taken@example.com']);   // verified + active

        $res = $this->postJson('/api/register', $this->payload(['email' => 'taken@example.com']))
            ->assertStatus(201)
            ->assertJsonStructure(['flow_id', 'email_masked']);   // identical shape to a fresh signup

        $this->assertNotNull($res->json('flow_id'));
        $this->assertSame(1, User::where('email', 'taken@example.com')->count());  // no second account
        Mail::assertSent(AccountExistsMail::class);                                 // owner gets a notice…
        Mail::assertNotSent(OtpMail::class);                                        // …not a usable code
    }

    public function test_existing_pending_email_resumes_with_a_fresh_code(): void
    {
        Mail::fake();
        User::factory()->unverified()->create([
            'email' => 'pending@example.com', 'status' => UserStatus::PendingVerification,
        ]);

        $this->postJson('/api/register', $this->payload(['email' => 'pending@example.com']))->assertStatus(201);

        $this->assertSame(1, User::where('email', 'pending@example.com')->count());
        Mail::assertSent(OtpMail::class);   // it's their own unverified account → real code
    }

    public function test_register_then_verify_activates_the_account(): void
    {
        Mail::fake();
        $res = $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $flow = $res->json('flow_id');

        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;
            return true;
        });

        // wrong code first — rejected without activating
        $this->postJson('/api/verify', ['flow_id' => $flow, 'code' => '000000'])
            ->assertStatus(200)->assertJsonPath('ok', false);

        // correct code activates
        $this->postJson('/api/verify', ['flow_id' => $flow, 'code' => $code])
            ->assertStatus(200)->assertJsonPath('ok', true);

        $user = User::where('email', 'jamie@example.com')->firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verify_context_returns_masked_email_only(): void
    {
        Mail::fake();
        $flow = $this->postJson('/api/register', $this->payload())->json('flow_id');

        $this->getJson('/api/verify/context?flow_id='.$flow)
            ->assertStatus(200)
            ->assertJsonPath('purpose', 'signup_student')
            ->assertJsonMissingPath('email');   // never the raw address
    }
}
