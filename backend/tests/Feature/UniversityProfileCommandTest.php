<?php

namespace Tests\Feature;

use App\Console\Commands\UniversityProfile;
use App\Models\Catalogue\Institution;
use App\Models\ContentAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The first fill of a university profile, from a reviewed file.
 *
 * The admin form is the right tool for an editor changing a paragraph and the
 * wrong one for this: ~50 fields across 13 sections, nine of them repeaters.
 * Done by hand there is no record of where each figure came from and no way to
 * do the next university the same way.
 *
 * What is tested harder than the happy path, and why each one has teeth:
 *
 *   AN UNKNOWN KEY IS A FAILURE, NOT A SKIP. A profile file is written by hand
 *   from research. If `livingcost_note` were quietly ignored, someone would
 *   have written a figure that never appears on the page and no way to notice.
 *
 *   FIELDS CANNOT DRIFT FROM THE MODEL. The command's allow-list is checked
 *   against $fillable, so a field renamed in a migration fails here instead of
 *   silently dropping a write.
 *
 *   IMAGES TAKE THE SAME PIPELINE AS AN UPLOAD, NOT A SHORTCUT PAST IT. A file
 *   that is not really an image must be refused on its BYTES, and what lands on
 *   disk must be re-encoded rather than copied.
 *
 *   A PARTIAL FILE TOPS UP, IT DOES NOT BLANK. Filling in rankings later must
 *   not wipe the overview written last month.
 */
class UniversityProfileCommandTest extends TestCase
{
    use RefreshDatabase;

    private function university(array $attributes = []): Institution
    {
        return Institution::query()->create(array_merge([
            'name' => 'Aalen University',
            'country' => 'Germany',
            'city' => 'Aalen',
            'source' => 'test',
        ], $attributes));
    }

    /** A profile file on the real filesystem, since the command reads a path. */
    private function profileFile(array $data): string
    {
        $path = sys_get_temp_dir().'/vfi-profile-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }

    /** A real 4x4 PNG, so the byte sniffing has something honest to accept. */
    private function pngFile(string $dir, string $name): string
    {
        @mkdir($dir, 0777, true);
        $img = imagecreatetruecolor(4, 4);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 60, 120));
        ob_start();
        imagepng($img);
        file_put_contents($dir.'/'.$name, (string) ob_get_clean());
        imagedestroy($img);

        return $dir.'/'.$name;
    }

    // ----------------------------------------------------------------- guards

    public function test_it_refuses_an_id_that_does_not_exist(): void
    {
        $this->artisan('university:profile', ['id' => 999999, '--file' => $this->profileFile(['city' => 'x'])])
            ->assertFailed();
    }

    public function test_it_refuses_a_missing_or_unparseable_file(): void
    {
        $u = $this->university();

        $this->artisan('university:profile', ['id' => $u->id, '--file' => '/nope/nope.json'])->assertFailed();

        $broken = sys_get_temp_dir().'/vfi-broken-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($broken, '{not json');
        $this->artisan('university:profile', ['id' => $u->id, '--file' => $broken])->assertFailed();
    }

    /**
     * The one that matters most for a hand-written file: a key the command does
     * not recognise must stop the run. Skipping it means a researched figure
     * silently never reaches the page.
     */
    public function test_an_unknown_key_fails_the_run_and_writes_nothing(): void
    {
        $u = $this->university(['overview' => 'Untouched.']);

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['livingcost_note' => 'typo in the key', 'overview' => 'new']),
        ])->assertFailed();

        $this->assertSame('Untouched.', $u->fresh()->overview);
    }

    /**
     * Every field the command offers must actually be writable on the model. If
     * this fails, FIELDS has drifted from $fillable and those writes would be
     * dropped without a word.
     */
    public function test_every_offered_field_is_fillable_on_the_model(): void
    {
        $offered = (new \ReflectionClass(UniversityProfile::class))
            ->getConstant('FIELDS');

        $missing = array_diff($offered, (new Institution)->getFillable());

        $this->assertSame([], $missing, 'the command offers fields the model will not accept');
    }

    // ------------------------------------------------------------------ write

    public function test_it_writes_the_text_fields_and_the_repeaters(): void
    {
        $u = $this->university();

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile([
                'tagline' => 'University of applied sciences in Baden-Württemberg',
                'overview' => 'A practice-oriented university.',
                'website' => 'https://www.hs-aalen.de',
                'vfi_represented' => true,
                'affordability_band' => 'medium',
                'overview_stats_json' => [
                    ['value' => '5,800', 'label' => 'Students'],
                    ['value' => '1962', 'label' => 'Founded'],
                ],
                'cost_rows_json' => [
                    ['label' => 'Tuition, non-EU', 'value' => 'EUR 1,500 per semester'],
                ],
                'faqs_json' => [['q' => 'Is German required?', 'a' => 'Not for the English-taught masters.']],
            ]),
        ])->assertSuccessful();

        $fresh = $u->fresh();
        $this->assertSame('https://www.hs-aalen.de', $fresh->website);
        $this->assertTrue($fresh->vfi_represented);
        $this->assertCount(2, $fresh->overview_stats_json);
        $this->assertSame('EUR 1,500 per semester', $fresh->cost_rows_json[0]['value']);
        $this->assertSame('Is German required?', $fresh->faqs_json[0]['q']);
    }

    public function test_a_later_partial_file_tops_up_rather_than_blanking(): void
    {
        $u = $this->university(['overview' => 'Written last month.']);

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['ranking_note' => 'Rankings as published in 2024.']),
        ])->assertSuccessful();

        $fresh = $u->fresh();
        $this->assertSame('Rankings as published in 2024.', $fresh->ranking_note);
        $this->assertSame('Written last month.', $fresh->overview, 'a partial file must not wipe the rest');
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $u = $this->university(['overview' => 'Before.']);

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['overview' => 'After.']),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('Before.', $u->fresh()->overview);
    }

    // ----------------------------------------------------------------- images

    public function test_an_image_is_re_encoded_and_stored_where_the_form_stores_it(): void
    {
        Storage::fake('public');
        $u = $this->university();
        $dir = sys_get_temp_dir().'/vfi-img-'.bin2hex(random_bytes(4));
        $this->pngFile($dir, 'logo.png');

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['images' => ['logo_key' => 'logo.png']]),
            '--images' => $dir,
        ])->assertSuccessful();

        $key = $u->fresh()->logo_key;
        $this->assertNotNull($key);
        $this->assertStringStartsWith('media/universities/', $key,
            'the same directory the admin form uploads a logo to');
        Storage::disk('public')->assertExists($key);
    }

    public function test_a_gallery_stores_a_list_of_paths(): void
    {
        Storage::fake('public');
        $u = $this->university();
        $dir = sys_get_temp_dir().'/vfi-img-'.bin2hex(random_bytes(4));
        $this->pngFile($dir, 'one.png');
        $this->pngFile($dir, 'two.png');

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['images' => ['gallery_json' => ['one.png', 'two.png']]]),
            '--images' => $dir,
        ])->assertSuccessful();

        $gallery = $u->fresh()->gallery_json;
        $this->assertCount(2, $gallery);
        foreach ($gallery as $path) {
            $this->assertStringStartsWith('media/universities/gallery/', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    /**
     * Sniffed from the bytes, not the extension - the same rule the upload
     * endpoint applies, because a .png that is really a script must not reach
     * the public disk.
     */
    public function test_a_file_that_is_not_really_an_image_is_refused(): void
    {
        Storage::fake('public');
        $u = $this->university();
        $dir = sys_get_temp_dir().'/vfi-img-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/logo.png', '<?php echo "not an image";');

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['images' => ['logo_key' => 'logo.png']]),
            '--images' => $dir,
        ])->assertFailed();

        $this->assertNull($u->fresh()->logo_key);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_a_missing_image_file_fails_before_anything_is_written(): void
    {
        Storage::fake('public');
        $u = $this->university(['overview' => 'Before.']);

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile([
                'overview' => 'After.',
                'images' => ['logo_key' => 'not-there.png'],
            ]),
            '--images' => sys_get_temp_dir(),
        ])->assertFailed();

        $this->assertSame('Before.', $u->fresh()->overview,
            'a broken image must not leave half a profile written');
    }

    public function test_an_unknown_image_field_is_refused(): void
    {
        $u = $this->university();

        $this->artisan('university:profile', [
            'id' => $u->id,
            '--file' => $this->profileFile(['images' => ['banner_key' => 'x.png']]),
            '--images' => sys_get_temp_dir(),
        ])->assertFailed();
    }

    // ------------------------------------------------------------------ audit

    /** Somebody rewrote a public page. That has to be answerable afterwards. */
    public function test_the_run_is_recorded_with_the_file_it_came_from(): void
    {
        $u = $this->university();
        $file = $this->profileFile(['overview' => 'Audited.']);

        $this->artisan('university:profile', ['id' => $u->id, '--file' => $file])->assertSuccessful();

        $row = ContentAuditLog::query()->where('entity', 'institutions')
            ->where('entity_id', (string) $u->id)->latest('id')->first();

        $this->assertNotNull($row, 'a profile write with no audit row');
        $this->assertSame('university_profile', $row->action);
        $this->assertSame(basename($file), $row->after['_source_file'] ?? null);
    }
}
