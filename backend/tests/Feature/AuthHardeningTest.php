<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Jamie', 'email' => 'jamie@example.com', 'password' => 'a-fresh-passphrase-42',
            'cc' => '+880', 'phone' => '171234567', 'agree' => true,
        ], $over);
    }

    // ---- HIBP breach check ----

    public function test_breached_password_is_rejected_on_register(): void
    {
        Mail::fake();
        config(['auth.breach_check' => true]);
        $pw = 'password123';
        $suffix = substr(strtoupper(sha1($pw)), 5);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response($suffix.":1337\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:2", 200)]);

        $this->postJson('/api/register', $this->payload(['password' => $pw]))
            ->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_breach_check_fails_open_when_hibp_unreachable(): void
    {
        Mail::fake();
        config(['auth.breach_check' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('service down', 500)]);

        // A "breached" password still goes through because the check failed open.
        $this->postJson('/api/register', $this->payload(['password' => 'password123']))
            ->assertStatus(201);
    }

    public function test_no_breach_call_when_disabled(): void
    {
        Mail::fake();
        Http::fake();   // AUTH_BREACH_CHECK=false in phpunit env
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        Http::assertNothingSent();
    }

    // ---- Turnstile ----

    public function test_turnstile_disabled_allows_register(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->payload())->assertStatus(201);   // default: off
    }

    public function test_turnstile_enabled_blocks_missing_token(): void
    {
        Mail::fake();
        config(['turnstile.enabled' => true, 'turnstile.secret' => 'test-secret']);

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(422)->assertJsonPath('message', 'Verification failed. Please try again.');
    }

    public function test_turnstile_enabled_passes_with_valid_token(): void
    {
        Mail::fake();
        config(['turnstile.enabled' => true, 'turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true], 200)]);

        $this->postJson('/api/register', $this->payload(['cf-turnstile-response' => 'tok']))
            ->assertStatus(201);
    }

    public function test_turnstile_enabled_rejects_invalid_token(): void
    {
        Mail::fake();
        config(['turnstile.enabled' => true, 'turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);

        $this->postJson('/api/register', $this->payload(['cf-turnstile-response' => 'bad']))
            ->assertStatus(422);
    }
}
