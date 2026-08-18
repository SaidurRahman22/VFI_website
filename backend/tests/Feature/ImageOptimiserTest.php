<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Universities\Pages\CreateUniversity;
use App\Models\Catalogue\Institution;
use App\Models\Student\DocumentFile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ImageOptimiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Covers ImageOptimiser and its wiring into the /manage upload fields, plus the
 * one thing that must NOT happen: a student document being re-encoded.
 */
class ImageOptimiserTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'a-good-long-passphrase';

    private function optimiser(): ImageOptimiser
    {
        return app(ImageOptimiser::class);
    }

    /**
     * A noisy JPEG. The default quality 100 is the bloated thing staff upload; a
     * quality BELOW JPEG_QUALITY is the other case that matters, because that is
     * what sends optimise() down its never-larger fallback.
     */
    private function jpeg(int $width, int $height, int $quality = 100): string
    {
        $im = imagecreatetruecolor($width, $height);
        $this->noise($im, $width, $height);

        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** A PNG whose top-left quadrant is fully transparent. */
    private function transparentPng(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, 20, 90, 200));
        imagefilledrectangle($im, 0, 0, intdiv($width, 2), intdiv($height, 2),
            imagecolorallocatealpha($im, 0, 0, 0, 127));

        ob_start();
        imagepng($im, null, 0);          // deliberately uncompressed, so ours must win
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function noise(\GdImage $im, int $width, int $height): void
    {
        mt_srand(20260818);              // deterministic, so byte-size assertions are stable
        for ($i = 0; $i < 400; $i++) {
            imagefilledrectangle(
                $im,
                mt_rand(0, $width - 1), mt_rand(0, $height - 1),
                mt_rand(0, $width - 1), mt_rand(0, $height - 1),
                imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
            );
        }
    }

    /**
     * A PNG signature plus a hand-built IHDR that CLAIMS $width x $height. Only
     * a few dozen bytes on the wire — the decompression-bomb shape.
     */
    private function pngClaiming(int $width, int $height): string
    {
        $ihdr = pack('N2', $width, $height)."\x08\x06\x00\x00\x00";

        return "\x89PNG\x0D\x0A\x1A\x0A"
            .pack('N', strlen($ihdr)).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    /**
     * An indexed-palette PNG — a logo, in other words. gd can only resample onto a
     * truecolor canvas, so its re-encode is several times the size of this, which
     * makes it the fixture that actually reaches the never-larger fallback.
     */
    private function palettePng(int $width = 300, int $height = 200): string
    {
        $im = imagecreate($width, $height);
        imagecolorallocate($im, 255, 255, 255);
        imagefilledellipse($im, intdiv($width, 2), intdiv($height, 2),
            (int) ($width * 0.7), (int) ($height * 0.7), imagecolorallocate($im, 220, 30, 30));

        ob_start();
        imagepng($im, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** The same PNG with a tEXt chunk spliced in after IHDR — where editors park XMP. */
    private function pngWithMetadata(string $marker): string
    {
        $png = $this->palettePng();
        $data = "Comment\x00".$marker;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));

        return substr($png, 0, 33).$chunk.substr($png, 33);
    }

    /**
     * A JPEG of per-pixel noise, which barely compresses. Saved below
     * JPEG_QUALITY, re-encoding it at 82 can only add bytes, so this is the JPEG
     * shape that reaches the never-larger fallback.
     */
    private function incompressibleJpeg(int $width, int $height, int $quality): string
    {
        $im = imagecreatetruecolor($width, $height);
        mt_srand(20260818);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($im, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
            }
        }

        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** A GIF with a palette entry genuinely nominated as transparent. */
    private function transparentGif(int $width, int $height): string
    {
        $im = imagecreate($width, $height);
        imagecolortransparent($im, imagecolorallocate($im, 255, 255, 255));
        imagefilledellipse($im, intdiv($width, 2), intdiv($height, 2),
            (int) ($width * 0.7), (int) ($height * 0.7), imagecolorallocate($im, 220, 30, 30));

        ob_start();
        imagegif($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * A landscape JPEG plus an APP1 holding nothing but an Orientation tag: a
     * minimal little-endian TIFF IFD0, which is all a phone's portrait shot
     * really amounts to.
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation, int $quality = 100): string
    {
        $im = imagecreatetruecolor($width, $height);
        $this->noise($im, $width, $height);
        ob_start();
        imagejpeg($im, null, $quality);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);

        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function dimensions(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);

        return [(int) $info[0], (int) $info[1]];
    }

    public function test_a_real_image_is_shrunk(): void
    {
        $original = $this->jpeg(1600, 1200);
        $out = $this->optimiser()->optimise($original);

        $this->assertLessThan(strlen($original), strlen($out));
        $this->assertStringStartsWith("\xFF\xD8\xFF", $out);       // still a JPEG
        $this->assertSame([1600, 1200], $this->dimensions($out));  // in cap → not resized
    }

    public function test_a_png_keeps_its_alpha_channel(): void
    {
        // Over the cap, so the output is definitely our re-encode and not the
        // never-larger fallback.
        $out = $this->optimiser()->optimise($this->transparentPng(1200, 1200), 400);
        $this->assertSame([400, 400], $this->dimensions($out));

        $im = imagecreatefromstring($out);
        $alpha = (imagecolorat($im, 10, 10) >> 24) & 0x7F;
        $opaque = (imagecolorat($im, 390, 390) >> 24) & 0x7F;
        imagedestroy($im);

        $this->assertSame(127, $alpha);       // still fully transparent, not a black box
        $this->assertSame(0, $opaque);        // and the rest of the logo is still opaque
    }

    public function test_an_oversize_image_is_capped_to_the_max_dimension(): void
    {
        $out = $this->optimiser()->optimise($this->jpeg(3000, 1500), 2000);

        $this->assertSame([2000, 1000], $this->dimensions($out));   // aspect ratio kept
    }

    public function test_a_small_image_is_never_upscaled(): void
    {
        $out = $this->optimiser()->optimise($this->jpeg(120, 60), ImageOptimiser::MAX_DIMENSION);

        $this->assertSame([120, 60], $this->dimensions($out));
    }

    public function test_an_in_cap_gif_is_passed_through_untouched(): void
    {
        // gd would flatten a multi-frame GIF to its first frame, and GIF carries no
        // EXIF to strip, so there is nothing to gain by re-encoding one.
        $im = imagecreatetruecolor(100, 80);
        imagefilledrectangle($im, 0, 0, 99, 79, imagecolorallocate($im, 10, 200, 90));
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        imagedestroy($im);

        $this->assertSame($gif, $this->optimiser()->optimise($gif, 2000));
        $this->assertSame([50, 40], $this->dimensions($this->optimiser()->optimise($gif, 50)));
    }

    public function test_a_text_file_renamed_jpg_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a JPEG, PNG, GIF or WebP');

        // The name and the declared mime say image/jpeg; the bytes say otherwise.
        $this->optimiser()->optimise('this is not an image, it is a note about a visa');
    }

    public function test_an_svg_is_refused(): void
    {
        // FileUpload::image() accepts all of image/*, so this really does arrive.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->assertFalse($this->optimiser()->accepts($svg));
    }

    public function test_a_jpeg_body_with_a_png_extension_is_stored_under_the_real_format(): void
    {
        $name = $this->optimiser()->storageName('logo.png', $this->optimiser()->extensionFor($this->jpeg(40, 40)));

        $this->assertSame('logo.jpg', $name);
    }

    public function test_the_storage_name_cannot_escape_the_directory(): void
    {
        $this->assertSame('passwd.jpg', $this->optimiser()->storageName('../../../etc/passwd', 'jpg'));
        $this->assertSame('image.png', $this->optimiser()->storageName('../..', 'png'));
    }

    public function test_output_is_never_larger_than_the_input(): void
    {
        $fixtures = [
            'noisy jpeg' => $this->jpeg(900, 700),
            'tiny jpeg' => $this->jpeg(16, 16),
            'transparent png' => $this->transparentPng(64, 64),
            'flat png' => $this->transparentPng(8, 8),
            'jpeg saved below our own quality' => $this->incompressibleJpeg(240, 240, 30),
            'indexed-palette logo' => $this->palettePng(),
        ];

        foreach ($fixtures as $label => $bytes) {
            $out = $this->optimiser()->optimise($bytes);
            $this->assertLessThanOrEqual(strlen($bytes), strlen($out), $label.' grew');
        }

        // And the fallback is genuinely the path taken, not an accident of these
        // fixtures: a palette PNG carries no metadata to strip, so falling back
        // returns it byte for byte. Without this the whole test stayed green with
        // the fallback deleted, i.e. it was not testing the fallback at all.
        $logo = $this->palettePng();
        $this->assertSame($logo, $this->optimiser()->optimise($logo), 'the never-larger fallback was not reached');
    }

    /**
     * The fallback hands back the ORIGINAL pixels, so it is the one path where a
     * GPS block could ride along untouched. It has to be the stripped original.
     */
    public function test_the_never_larger_fallback_still_strips_metadata(): void
    {
        $marker = 'GPS-HOME-ADDRESS-OF-THE-UPLOADER';
        $payload = "Exif\x00\x00".$marker;

        $jpeg = $this->incompressibleJpeg(240, 240, 30);
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);

        $out = $this->optimiser()->optimise($withExif);

        $this->assertLessThanOrEqual(strlen($withExif), strlen($out));
        $this->assertStringNotContainsString($marker, $out, 'the fallback smuggled EXIF back in');

        // The original scan data proves which branch ran: a re-encode would have
        // produced different compressed pixels, so this only holds on the fallback.
        $scan = substr($jpeg, (int) strpos($jpeg, "\xFF\xDA"));
        $this->assertStringContainsString($scan, $out, 'this did not exercise the fallback');

        // Same hole on the PNG side, where the carrier is a text chunk. Here the
        // stripped original must come back byte-identical to the clean PNG.
        $outPng = $this->optimiser()->optimise($this->pngWithMetadata($marker));

        $this->assertStringNotContainsString($marker, $outPng);
        $this->assertSame($this->palettePng(), $outPng);
    }

    /**
     * gd's GIF writer has no alpha channel, so a resized transparent GIF used to
     * come back opaque — the same black-box-behind-the-logo bug the PNG path
     * guards against. GIF is accepted by the sniffer, so it really does arrive.
     */
    public function test_a_transparent_gif_over_the_cap_keeps_its_transparency(): void
    {
        $out = $this->optimiser()->optimise($this->transparentGif(1200, 1200), 400);
        $this->assertSame([400, 400], $this->dimensions($out));

        $im = imagecreatefromstring($out);
        $transparentIndex = imagecolortransparent($im);
        $corner = imagecolorat($im, 3, 3);
        $centre = imagecolorsforindex($im, imagecolorat($im, 200, 200));
        imagedestroy($im);

        $this->assertGreaterThanOrEqual(0, $transparentIndex, 'the resized GIF lost its transparency');
        $this->assertSame($transparentIndex, $corner, 'the background is opaque, i.e. a black box');
        $this->assertGreaterThan(150, $centre['red']);        // and the logo itself survived
        $this->assertLessThan(80, $centre['green']);
    }

    public function test_an_opaque_gif_does_not_gain_a_transparent_colour(): void
    {
        // The mirror of the test above: nominating a transparent index on a GIF
        // that never had one would punch a hole straight through the picture.
        $im = imagecreatetruecolor(1200, 1200);
        imagefilledrectangle($im, 0, 0, 1199, 1199, imagecolorallocate($im, 12, 12, 12));
        imagefilledellipse($im, 600, 600, 800, 800, imagecolorallocate($im, 250, 250, 250));
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        imagedestroy($im);

        $out = imagecreatefromstring($this->optimiser()->optimise($gif, 400));
        $index = imagecolortransparent($out);
        imagedestroy($out);

        $this->assertSame(-1, $index);
    }

    public function test_declared_huge_dimensions_are_rejected_without_exhausting_memory(): void
    {
        $bomb = $this->pngClaiming(20000, 20000);            // 400 MP claimed, ~50 bytes on the wire
        $this->assertLessThan(1024, strlen($bomb));

        $before = memory_get_usage();

        try {
            $this->optimiser()->optimise($bomb);
            $this->fail('A 400 MP image should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('20000x20000', $e->getMessage());
        }

        // The point of the header check: no canvas was ever allocated.
        $this->assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before);
        $this->assertFalse($this->optimiser()->accepts($bomb));
    }

    public function test_exif_is_stripped(): void
    {
        // Splice an APP1 EXIF segment straight after SOI, the way a phone does.
        $jpeg = $this->jpeg(1200, 900);
        $payload = "Exif\x00\x00GPS-HOME-ADDRESS-OF-THE-UPLOADER";
        $withExif = substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($payload) + 2).$payload
            .substr($jpeg, 2);

        $this->assertStringContainsString('GPS-HOME-ADDRESS-OF-THE-UPLOADER', $withExif);

        $out = $this->optimiser()->optimise($withExif);

        $this->assertStringNotContainsString('GPS-HOME-ADDRESS-OF-THE-UPLOADER', $out);
        $this->assertStringNotContainsString("Exif\x00\x00", $out);
    }

    /**
     * A phone shooting in portrait records landscape pixels plus an Orientation
     * tag. That tag does not survive the re-encode, so the turn has to be baked
     * into the pixels — including when the never-larger fallback would otherwise
     * have preferred the original, whose pixels are still on their side.
     */
    public function test_an_exif_orientation_is_baked_into_the_pixels(): void
    {
        $this->assertSame([400, 800], $this->dimensions(
            $this->optimiser()->optimise($this->jpegWithOrientation(800, 400, 6))
        ));

        // Quality 25, so the re-encode is the LARGER of the two: this only holds
        // if the rotation is allowed to override the never-larger fallback.
        $this->assertSame([400, 800], $this->dimensions(
            $this->optimiser()->optimise($this->jpegWithOrientation(800, 400, 6, 25))
        ), 'the fallback handed back the unturned pixels');

        // An already-upright photo is left exactly as it was framed.
        $this->assertSame([800, 400], $this->dimensions(
            $this->optimiser()->optimise($this->jpegWithOrientation(800, 400, 1))
        ));
    }

    public function test_the_university_logo_upload_is_optimised_on_save(): void
    {
        Storage::fake('local');            // livewire-tmp
        Storage::fake('public');

        $original = $this->jpeg(3000, 2000);
        $file = UploadedFile::fake()->createWithContent('camera-roll.jpg', $original);

        $this->actingAs($this->contentEditor());

        Livewire::test(CreateUniversity::class)
            ->fillForm($this->identity('Optimiser Test University', 'United Kingdom'))
            ->set('data.logo_key', [$file])
            ->call('create')
            ->assertHasNoFormErrors();

        $key = Institution::where('name', 'Optimiser Test University')->value('logo_key');
        $this->assertNotNull($key, 'the logo was not stored at all');

        $stored = Storage::disk('public')->get($key);
        $this->assertLessThan(strlen($original), strlen($stored));
        $this->assertSame([800, 533], $this->dimensions($stored));   // capped at the logo cap
    }

    public function test_a_disguised_upload_never_reaches_the_public_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $file = UploadedFile::fake()->createWithContent('logo.jpg', 'GIF-ish text pretending to be art');

        $this->actingAs($this->contentEditor());

        Livewire::test(CreateUniversity::class)
            ->fillForm($this->identity('Disguised Upload University', 'Ireland'))
            ->set('data.logo_key', [$file])
            ->call('create');

        // The row saves; only the file is thrown away, so a null logo_key here is
        // the refusal and not a form that failed validation somewhere else.
        $this->assertDatabaseHas('institutions', ['name' => 'Disguised Upload University']);
        $this->assertNull(Institution::where('name', 'Disguised Upload University')->value('logo_key'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    /**
     * 🔴 The guarantee that matters: a passport scan must come back out of the
     * private disk byte-for-byte, because its sha256 is the dedupe key and the
     * DocumentAccessLog asserts those exact bytes were the ones reviewed.
     */
    public function test_the_document_pipeline_is_untouched(): void
    {
        Storage::fake('documents');

        // A photographed passport page — precisely the upload a global image hook
        // would have eaten, since the document pipeline accepts image/jpeg too.
        $passport = $this->jpeg(2400, 1800);
        $this->assertGreaterThan(0, strlen($passport));
        $sha = hash('sha256', $passport);

        $user = User::factory()->create(['password' => self::PW, 'email_verified_at' => now()]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Student, 'agency_id' => null, 'granted_at' => now()]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PW])->assertStatus(200);

        $this->post('/api/me/documents/passport', [
            'file' => UploadedFile::fake()->createWithContent('passport.jpg', $passport),
        ])->assertStatus(201);

        $file = DocumentFile::query()->firstOrFail();
        $bytes = Storage::disk('documents')->get($file->storage_key);

        $this->assertSame($sha, $file->sha256, 'the recorded hash drifted from the uploaded bytes');
        $this->assertSame($sha, hash('sha256', $bytes), 'the stored blob was re-encoded');
        $this->assertSame($passport, $bytes);
        $this->assertSame(strlen($passport), $file->size);
    }

    /**
     * The minimum a university needs to save. Every repeater on the form ships
     * one empty row by default whose fields are required, so they are cleared
     * here — otherwise the save fails on validation and never reaches the
     * upload path this test is about.
     */
    private function identity(string $name, string $country): array
    {
        return [
            'name' => $name,
            'country' => $country,
            // NOT NULL with a DB default, and Filament posts an explicit null for
            // an untouched Select, so the insert needs a value.
            'tuition_deposit_policy' => 'standard',
            'overview_stats_json' => [], 'rankings_json' => [], 'intakes_json' => [],
            'cost_rows_json' => [], 'scholarships_json' => [], 'admissions_json' => [],
            'recruiters_json' => [], 'jobs_json' => [], 'services_json' => [], 'faqs_json' => [],
        ];
    }

    private function contentEditor(): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => Role::ContentEditor, 'agency_id' => null, 'granted_at' => now()]);
        $u->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(), 'mfa_enrolled_at' => now()])->save();

        return $u->fresh();
    }
}
