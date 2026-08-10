<?php

namespace Tests\Feature;

use App\Models\Content\PpDoc;
use App\Models\Content\PpQuicklink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_js_sets_global_and_is_javascript(): void
    {
        $res = $this->get('/api/content/bootstrap.js')->assertStatus(200);

        $this->assertStringContainsString('javascript', strtolower($res->headers->get('Content-Type')));
        $body = $res->getContent();
        $this->assertStringStartsWith('window.VFI_BOOTSTRAP = ', $body);
        $this->assertStringEndsWith(';', $body);

        // the payload is valid JSON with the expected shape
        $json = substr($body, strlen('window.VFI_BOOTSTRAP = '), -1);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('settings', $data);
    }

    public function test_bootstrap_supports_conditional_get(): void
    {
        $r1 = $this->get('/api/content/bootstrap.js')->assertStatus(200);
        $this->withHeader('If-None-Match', $r1->headers->get('ETag'))
            ->get('/api/content/bootstrap.js')->assertStatus(304);
    }

    public function test_url_scheme_allowlist_drops_dangerous_urls(): void
    {
        PpQuicklink::create(['legacy_id' => 'q1', 'position' => 0, 'label' => 'Evil', 'url' => 'javascript:alert(1)']);
        PpQuicklink::create(['legacy_id' => 'q2', 'position' => 1, 'label' => 'Ok', 'url' => 'https://vfi-edu.com']);
        PpQuicklink::create(['legacy_id' => 'q3', 'position' => 2, 'label' => 'Rel', 'url' => '/partner-resources.html']);
        PpQuicklink::create(['legacy_id' => 'q4', 'position' => 3, 'label' => 'Data', 'url' => 'data:text/html,<script>']);
        PpDoc::create(['legacy_id' => 'd1', 'position' => 0, 'title' => 'Doc', 'url' => 'javascript:evil()']);

        $res = $this->getJson('/api/content/bundle')->assertStatus(200);

        $res->assertJsonPath('ppQuicklinks.0.url', '')                      // javascript: dropped
            ->assertJsonPath('ppQuicklinks.1.url', 'https://vfi-edu.com')   // https kept
            ->assertJsonPath('ppQuicklinks.2.url', '/partner-resources.html') // relative kept
            ->assertJsonPath('ppQuicklinks.3.url', '')                      // data: dropped
            ->assertJsonPath('ppDocs.0.url', '');                            // javascript: dropped
    }
}
