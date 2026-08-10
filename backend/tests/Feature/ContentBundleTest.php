<?php

namespace Tests\Feature;

use App\Models\Content\Event;
use App\Models\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_bundle_has_arrays_and_objects_faithfully(): void
    {
        $res = $this->getJson('/api/content/bundle')->assertStatus(200);
        $json = $res->getContent();

        // empty collections are [] (not {} or null); empty singletons are {} (not [])
        $this->assertStringContainsString('"events":[]', $json);
        $this->assertStringContainsString('"ppNotifs":[]', $json);
        $this->assertStringContainsString('"settings":{}', $json);
        $this->assertStringContainsString('"servicesPage":{}', $json);
        $this->assertStringContainsString('"pages":{}', $json);
    }

    public function test_populated_bundle_shape_and_order(): void
    {
        Event::create(['legacy_id' => 'e1', 'position' => 1, 'title' => 'Older']);
        Event::create(['legacy_id' => 'e2', 'position' => 0, 'title' => 'Newer']);
        SiteContent::create(['key' => 'settings', 'value' => ['brand' => 'VFI', 'email' => 'x@vfi.test']]);

        $res = $this->getJson('/api/content/bundle')->assertStatus(200);

        // order is position (new-to-front): e2 before e1
        $res->assertJsonPath('events.0.id', 'e2')
            ->assertJsonPath('events.1.id', 'e1')
            ->assertJsonPath('settings.brand', 'VFI');
    }

    public function test_empty_override_falls_through_as_object(): void
    {
        SiteContent::create(['key' => 'servicesPage', 'value' => []]);  // cleared → {}
        $json = $this->getJson('/api/content/bundle')->getContent();
        $this->assertStringContainsString('"servicesPage":{}', $json);
    }

    public function test_etag_conditional_get_returns_304(): void
    {
        $r1 = $this->getJson('/api/content/bundle')->assertStatus(200);
        $etag = $r1->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/content/bundle')
            ->assertStatus(304);
    }

    public function test_public_get_sets_no_cookie(): void
    {
        $res = $this->getJson('/api/content/bundle')->assertStatus(200);
        $this->assertNull($res->headers->get('Set-Cookie'));
        $cc = $res->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cc);
        $this->assertStringContainsString('max-age=60', $cc);
    }
}
