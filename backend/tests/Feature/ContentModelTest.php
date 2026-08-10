<?php

namespace Tests\Feature;

use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\Content\PpNotif;
use App\Models\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_bundle_shape_matches_frontend_keys(): void
    {
        $e = Event::create([
            'legacy_id' => 'e1', 'position' => 0, 'title' => 'UK Spot Day',
            'date' => '2026-08-18', 'time' => '10:00', 'type' => 'Fair', 'city' => 'Dhaka',
            'description' => 'Meet delegates.', 'color' => 'a', 'img_id' => 'assets/img/city-uk.jpg',
        ]);

        $b = $e->toBundle();
        $this->assertSame(
            ['id', 'title', 'date', 'time', 'type', 'city', 'desc', 'color', 'imgId'],
            array_keys($b)
        );
        $this->assertSame('e1', $b['id']);
        $this->assertSame('Meet delegates.', $b['desc']);       // description → desc
        $this->assertSame('assets/img/city-uk.jpg', $b['imgId']); // img_id → imgId
        $this->assertSame('2026-08-18', $b['date']);            // DATE as Y-m-d string
    }

    public function test_blog_and_ppnotif_key_mapping(): void
    {
        $blog = Blog::create(['legacy_id' => 'b1', 'position' => 0, 'title' => 'X', 'read_time' => '6 min', 'body' => 'plain text']);
        $bb = $blog->toBundle();
        $this->assertArrayHasKey('readTime', $bb);
        $this->assertArrayHasKey('imgId', $bb);
        $this->assertSame('6 min', $bb['readTime']);

        $n = PpNotif::create(['legacy_id' => 'n1', 'position' => 0, 'title' => 'Hi', 'message' => 'body here']);
        $this->assertSame('body here', $n->toBundle()['text']);  // message → text
    }

    public function test_new_item_defaults_to_front(): void
    {
        Event::create(['legacy_id' => 'e1', 'position' => 5, 'title' => 'A']);
        $front = Event::create(['legacy_id' => 'e2', 'title' => 'B']); // no position → front
        $this->assertLessThan(5, $front->position);

        $ordered = Event::ordered()->pluck('legacy_id')->all();
        $this->assertSame(['e2', 'e1'], $ordered);
    }

    public function test_site_content_roundtrips_empty_faithfully(): void
    {
        // "empty means fall through" — "" and [] must NOT become null/defaults
        SiteContent::create(['key' => 'servicesPage', 'value' => []]);
        SiteContent::create(['key' => 'heroNote', 'value' => '']);

        $this->assertSame([], SiteContent::value('servicesPage'));
        $this->assertSame('', SiteContent::value('heroNote'));
        $this->assertNull(SiteContent::value('missing', null));
    }
}
