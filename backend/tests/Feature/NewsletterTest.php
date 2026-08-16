<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer subscribe box existed on ~30 public pages and stored nothing.
 * These pin the endpoint that now backs it.
 */
class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_subscriber(): void
    {
        $this->postJson('/api/newsletter', [
            'email' => 'Reader@Example.COM',
            'interest' => 'student',
            'source_page' => 'index.html',
        ])->assertStatus(202)->assertJsonPath('ok', true);

        $row = NewsletterSubscriber::firstOrFail();
        $this->assertSame('reader@example.com', $row->email);   // normalised
        $this->assertSame('student', $row->interest);
        $this->assertTrue($row->isActive());
    }

    public function test_subscribing_twice_does_not_duplicate(): void
    {
        $payload = ['email' => 'twice@example.com'];
        $this->postJson('/api/newsletter', $payload)->assertStatus(202);
        $this->postJson('/api/newsletter', $payload)->assertStatus(202);

        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_resubscribing_clears_a_previous_opt_out(): void
    {
        NewsletterSubscriber::create(['email' => 'back@example.com', 'unsubscribed_at' => now()]);

        $this->postJson('/api/newsletter', ['email' => 'back@example.com'])->assertStatus(202);

        $this->assertTrue(NewsletterSubscriber::firstOrFail()->isActive());
    }

    public function test_a_bad_address_is_refused(): void
    {
        $this->postJson('/api/newsletter', ['email' => 'not-an-address'])->assertStatus(422);
        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_an_unknown_interest_is_refused(): void
    {
        $this->postJson('/api/newsletter', [
            'email' => 'ok@example.com', 'interest' => 'hacker',
        ])->assertStatus(422);
    }
}
