<?php

namespace Tests\Feature;

use App\Models\Content\Event;
use App\Models\Content\PpNotif;
use App\Models\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ImportContentTest extends TestCase
{
    use RefreshDatabase;

    private function writeFixture(array $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vfi_import_').'.json';
        file_put_contents($path, json_encode(['content' => $content]));

        return $path;
    }

    private array $content = [
        'events' => [
            ['id' => 'e1', 'title' => 'Front', 'date' => '2026-08-18', 'desc' => 'first', 'imgId' => 'assets/img/x.jpg'],
            ['id' => 'e2', 'title' => 'Second'],
        ],
        'settings' => ['brand' => 'VFI', 'email' => 'x@vfi.test'],
        'servicesPage' => [],
        'ppNotifs' => [['id' => 'n1', 'title' => 'Hi', 'text' => 'body here']],
    ];

    public function test_import_upserts_with_position_and_mapping(): void
    {
        $path = $this->writeFixture($this->content);
        Artisan::call('content:import', ['file' => $path]);

        $this->assertSame(2, Event::count());
        $e1 = Event::where('legacy_id', 'e1')->first();
        $this->assertSame(0, $e1->position);                 // array index 0 = front
        $this->assertSame('first', $e1->description);        // desc → description
        $this->assertSame('assets/img/x.jpg', $e1->img_id);  // imgId → img_id
        $this->assertSame(1, Event::where('legacy_id', 'e2')->first()->position);

        $this->assertSame('VFI', SiteContent::value('settings')['brand']);
        $this->assertSame([], SiteContent::value('servicesPage'));  // empty round-trips
        $this->assertSame('body here', PpNotif::where('legacy_id', 'n1')->first()->message);

        @unlink($path);
    }

    public function test_import_is_idempotent_no_duplicates(): void
    {
        $path = $this->writeFixture($this->content);
        Artisan::call('content:import', ['file' => $path]);
        Artisan::call('content:import', ['file' => $path]);   // run twice

        $this->assertSame(2, Event::count());                 // no duplication
        $this->assertSame(1, Event::where('legacy_id', 'e1')->count());
        @unlink($path);
    }

    public function test_bundle_reflects_imported_content(): void
    {
        $path = $this->writeFixture($this->content);
        Artisan::call('content:import', ['file' => $path]);

        $this->getJson('/api/content/bundle')
            ->assertStatus(200)
            ->assertJsonPath('events.0.id', 'e1')
            ->assertJsonPath('events.0.desc', 'first')
            ->assertJsonPath('settings.brand', 'VFI');
        @unlink($path);
    }
}
