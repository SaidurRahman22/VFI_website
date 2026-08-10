<?php

namespace Tests\Feature;

use App\Models\Content\Blog;
use App\Models\Content\PpDoc;
use App\Models\Content\PpQuicklink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3B — content-security rules enforced at the MODEL layer, so they hold
 * regardless of how a write arrives (Filament, import, API).
 */
class ContentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_body_is_stored_as_plain_text(): void
    {
        $b = Blog::create([
            'title' => 'X',
            'body' => "<script>steal()</script>## Real Heading\n- a point\n<img src=x onerror=alert(1)>",
        ]);

        $body = $b->fresh()->body;
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringContainsString('## Real Heading', $body);  // markdown markers survive
        $this->assertStringContainsString('- a point', $body);
    }

    public function test_quicklink_url_is_scheme_allow_listed_on_save(): void
    {
        $this->assertSame('', PpQuicklink::create(['label' => 'a', 'url' => 'javascript:alert(1)'])->fresh()->url);
        $this->assertSame('', PpQuicklink::create(['label' => 'b', 'url' => 'data:text/html,x'])->fresh()->url);
        $this->assertSame('https://vfi-edu.com', PpQuicklink::create(['label' => 'c', 'url' => 'https://vfi-edu.com'])->fresh()->url);
        $this->assertSame('/partner-resources.html', PpQuicklink::create(['label' => 'd', 'url' => '/partner-resources.html'])->fresh()->url);
    }

    public function test_ppdoc_url_is_scheme_allow_listed_on_save(): void
    {
        $this->assertSame('', PpDoc::create(['title' => 'Doc', 'url' => 'javascript:evil()'])->fresh()->url);
        $this->assertSame('https://ok.test/f.pdf', PpDoc::create(['title' => 'Doc2', 'url' => 'https://ok.test/f.pdf'])->fresh()->url);
    }

    public function test_legacy_id_is_auto_minted_when_absent(): void
    {
        $b = Blog::create(['title' => 'No id given']);
        $this->assertNotEmpty($b->legacy_id);
        $this->assertStringStartsWith('B_', $b->legacy_id);   // class-basename prefix
    }
}
