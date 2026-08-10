<?php

namespace Tests\Feature;

use App\Models\ContactEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = [
        'fname' => 'Rafiul Karim',
        'phone' => '+880 1700-000000',
        'email' => 'Rafiul@Example.com',
        'dest' => 'Canada',
        'msg' => 'I want to study in Canada for Fall 2027.',
    ];

    public function test_valid_enquiry_persists_and_returns_202(): void
    {
        $this->postJson('/api/contact', $this->valid)
            ->assertStatus(202)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('contact_enquiries', [
            'fname' => 'Rafiul Karim',
            'email' => 'rafiul@example.com',   // lowercased
            'dest' => 'Canada',
            'status' => 'new',
        ]);
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->postJson('/api/contact', ['msg' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fname', 'phone', 'email']);
    }

    public function test_invalid_destination_is_rejected(): void
    {
        $this->postJson('/api/contact', array_merge($this->valid, ['dest' => 'Mars']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dest']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/contact', array_merge($this->valid, ['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_control_characters_are_stripped(): void
    {
        $this->postJson('/api/contact', array_merge($this->valid, [
            'fname' => "Rafiul\x00\x07Karim",
        ]))->assertStatus(202);

        $this->assertSame('RafiulKarim', ContactEnquiry::first()->fname);
    }

    public function test_per_email_rate_limit_blocks_flooding(): void
    {
        // per-email limit is 3 / 10 min → the 4th (same email) is throttled
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/contact', $this->valid)->assertStatus(202);
        }
        $this->postJson('/api/contact', $this->valid)->assertStatus(429);
    }
}
