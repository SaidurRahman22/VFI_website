<?php

namespace App\Services;

use Closure;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Phase 8+ — every image an admin uploads through /manage is re-encoded here on
 * the way to the public disk, because nothing on this VPS can do it afterwards
 * (no cwebp/jpegoptim/pngquant/imagemagick is installed; gd is all we have). A
 * staff member pasting a 6 MB phone photo into the "Logo" field used to ship
 * that photo, EXIF GPS and all, straight to every visitor.
 *
 * The bytes are treated as hostile: the format comes from a magic-byte sniff
 * (the declared mime type and the filename extension are both client-supplied),
 * the pixel budget is checked from the image HEADER before a gd canvas is
 * allocated, and anything gd cannot decode is refused rather than stored.
 *
 * WHY THE DOCUMENT PIPELINE IS EXCLUDED — and why this is deliberately opt-in
 * per form field rather than a disk hook, a Storage macro or a model event:
 * student passports and transcripts flow through DocumentStorage onto the
 * PRIVATE disk, where each blob's sha256 is the dedupe key, an append-only
 * DocumentAccessLog asserts those exact bytes were the ones seen, and a visa
 * officer has to read the small print on a scan. Re-encoding one of those blobs
 * would change its hash (breaking dedupe and making the audit trail a lie) and
 * soften text a visa decision depends on. A global hook would have caught them;
 * wiring this onto named public-disk FileUpload fields cannot. Nothing in this
 * class references the documents disk.
 */
class ImageOptimiser
{
    /** Longest edge, in px, for a general content image. Never upscales. */
    public const MAX_DIMENSION = 2000;

    public const JPEG_QUALITY = 82;

    /** Max deflate effort. PNG stays lossless — a logo must not gain artefacts. */
    public const PNG_COMPRESSION = 9;

    /**
     * Decompression-bomb ceiling. A 20000x20000 single-colour PNG is ~100 KB on
     * the wire and 1.6 GB once gd expands it, so the header is measured first and
     * anything past this is refused before a canvas exists. 16 MP still admits a
     * 4896x3264 camera frame and every phone shooting 4032x3024.
     */
    public const MAX_PIXELS = 16_000_000;

    /**
     * Working budget for one decode. gd has no streaming decoder — it holds the
     * whole bitmap at 4 bytes/px — so a legitimate 16 MP photo wants ~64 MB that
     * a 128M FPM pool has not got. MAX_PIXELS is what actually stops a bomb; this
     * bound only stops a real photograph from killing the worker.
     */
    public const DECODE_MEMORY_LIMIT = 192 * 1024 * 1024;

    /** Upload-size backstop, independent of whatever maxSize() a field declares. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /**
     * PNG chunks that carry metadata rather than pixels — eXIf is the GPS block,
     * the text chunks are where editors park XMP. All ancillary, so dropping them
     * is lossless and can only make the file smaller.
     */
    private const PNG_METADATA_CHUNKS = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    /**
     * Re-encode an image: downscale to $maxDimension, strip metadata, keep alpha.
     * Falls back to the original bytes, minus their metadata, when gd cannot beat
     * them on size.
     *
     * @throws \RuntimeException when the bytes are not a decodable raster image,
     *                           or would cost too much memory to decode
     */
    public function optimise(string $bytes, int $maxDimension = self::MAX_DIMENSION): string
    {
        if ($bytes === '') {
            throw new \RuntimeException('The file was empty.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('That image is too large to process.');
        }

        $format = $this->sniff($bytes);
        [$width, $height] = $this->headerDimensions($bytes);

        $maxDimension = max(1, $maxDimension);
        $scale = min(1, $maxDimension / max($width, $height));   // min(1, …) so we never upscale
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // An in-cap GIF is passed through untouched: GIF carries no EXIF, and gd
        // would flatten a multi-frame image down to its first frame.
        if ($format === 'gif' && $scale >= 1) {
            return $bytes;
        }

        // Read the Orientation tag up front: the rotation has to be baked into the
        // pixels, because the tag itself does not survive the re-encode.
        $rotation = $format === 'jpeg' ? $this->exifRotationAngle($bytes) : 0;

        $restoreMemoryLimit = $this->raiseMemoryLimit();

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $restoreMemoryLimit();

            throw new \RuntimeException('That file could not be decoded as an image.');
        }

        $canvas = null;
        $orientationBakedIn = false;

        try {
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($format === 'jpeg') {
                // JPEG has no alpha channel, so flatten onto white.
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            } else {
                // PNG/WebP/GIF may be transparent. Without this a logo arrives on
                // the page sitting in a black box.
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1,
                    imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            // Rotate before the EXIF block is discarded, otherwise a portrait phone
            // photo that relied on the Orientation tag renders on its side.
            if ($rotation !== 0) {
                $turned = @imagerotate($canvas, $rotation, 0);
                if ($turned !== false) {
                    imagedestroy($canvas);
                    $canvas = $turned;
                    $orientationBakedIn = true;
                }
            }

            // gd's GIF writer has no alpha channel, so a resized GIF has to be
            // quantised by hand and then told which palette entry is the
            // see-through one, or a transparent logo comes back in a black box.
            // Only when the source really was transparent: nominating an index on
            // an opaque GIF would punch a hole through it instead.
            if ($format === 'gif' && imagecolortransparent($source) >= 0) {
                imagetruecolortopalette($canvas, false, 255);
                imagecolortransparent($canvas, imagecolorclosestalpha($canvas, 0, 0, 0, 127));
            }

            $out = $this->encode($canvas, $format);
        } finally {
            imagedestroy($source);
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
            $restoreMemoryLimit();
        }

        // Never make a file bigger. Reachable whenever the source was already
        // tighter than gd can manage — an indexed-palette logo, or any JPEG saved
        // below JPEG_QUALITY, which is most of the web. Handing the raw original
        // back on that path would quietly defeat the whole point of the EXIF
        // strip, so what we compare against is the original with its metadata
        // segments removed. Stripping only ever deletes bytes, so the result is
        // still never larger than what was uploaded.
        //
        // One case has to take the re-encode whatever it costs: an Orientation tag
        // we baked into the pixels. The stripped original still holds the unturned
        // pixels and no longer carries the tag that told a browser to turn them,
        // so handing it back would land the photo on its side.
        if ($orientationBakedIn) {
            return $out;
        }

        $stripped = $this->stripMetadata($bytes, $format);

        return strlen($stripped) <= strlen($out) ? $stripped : $out;
    }

    /** True when these bytes really are a raster image this class can handle. */
    public function accepts(string $bytes): bool
    {
        try {
            $this->sniff($bytes);
            $this->headerDimensions($bytes);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /** Canonical extension for the ACTUAL bytes, not for the client filename. */
    public function extensionFor(string $bytes): string
    {
        return match ($this->sniff($bytes)) {
            'jpeg' => 'jpg',
            'gif' => 'gif',
            'webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * The `saveUploadedFileUsing()` callback for a Filament FileUpload: optimise
     * first, then store using the component's own disk/directory/visibility so a
     * field keeps whatever it was configured with.
     *
     * Opt-in on purpose — see the note on the document pipeline above.
     */
    public static function storeOptimised(int $maxDimension = self::MAX_DIMENSION): Closure
    {
        return function (BaseFileUpload $component, TemporaryUploadedFile $file) use ($maxDimension): ?string {
            $optimiser = app(self::class);

            // Read through the Livewire temp DISK, not getRealPath(): a
            // TemporaryUploadedFile's local path is an empty tmpfile() handle and
            // the bytes only ever live on the livewire-tmp disk.
            try {
                if (! $file->exists()) {
                    return null;
                }
                $bytes = (string) $file->get();
            } catch (\Throwable) {
                return null;
            }

            try {
                $optimised = $optimiser->optimise($bytes, $maxDimension);
            } catch (\RuntimeException $e) {
                // Refused: return null so nothing reaches the public disk, but say
                // so — a silently empty image field looks like a saving bug.
                rescue(fn () => Notification::make()->danger()
                    ->title('Image was not saved')->body($e->getMessage())->send(), report: false);

                return null;
            }

            $name = $optimiser->storageName(
                $component->getUploadedFileNameForStorage($file),
                $optimiser->extensionFor($optimised),
            );

            $path = trim(($component->getDirectory() ?? '').'/'.$name, '/');
            $component->getDisk()->put($path, $optimised, $component->getVisibility());

            return $path;
        };
    }

    /**
     * Filename for the blob. The extension follows the sniffed format so a JPEG
     * uploaded as "logo.png" is not later served as image/png; the basename plus
     * character filter is because this string becomes part of a storage path and
     * preserveFilenames() would make it client-controlled.
     */
    public function storageName(string $candidate, string $extension): string
    {
        $stem = pathinfo(basename(str_replace('\\', '/', $candidate)), PATHINFO_FILENAME);
        $stem = trim(substr((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $stem), 0, 100), '.-');

        return ($stem === '' ? 'image' : $stem).'.'.$extension;
    }

    private function sniff(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        // This is also how SVG is turned away: FileUpload::image() accepts the
        // whole of `image/*`, and an SVG on a public disk is a stored-XSS payload
        // rather than a picture.
        throw new \RuntimeException('That file is not a JPEG, PNG, GIF or WebP image.');
    }

    /**
     * Dimensions from the header only — no canvas is allocated yet. This is the
     * decompression-bomb gate, so it runs before imagecreatefromstring().
     *
     * @return array{int, int}
     */
    private function headerDimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('That image header could not be read.');
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException("That image is {$width}x{$height}, too many pixels to process.");
        }

        return [$width, $height];
    }

    /**
     * Lift memory_limit to DECODE_MEMORY_LIMIT for the decode, and hand back a
     * closure that puts it straight back. Deliberately a FIXED bound rather than
     * something derived from the image, so a hostile upload cannot talk the
     * process into an arbitrary allocation; and never lowers a limit that is
     * already higher than ours.
     */
    private function raiseMemoryLimit(): Closure
    {
        $previous = ini_get('memory_limit');
        $current = $this->memoryLimitBytes();

        if ($previous === false || $current <= 0 || $current >= self::DECODE_MEMORY_LIMIT) {
            return static fn () => null;
        }

        @ini_set('memory_limit', (string) self::DECODE_MEMORY_LIMIT);

        return static fn () => @ini_set('memory_limit', $previous);
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** Degrees gd must turn the pixels so the photo is upright once the tag is gone. */
    private function exifRotationAngle(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 0;
        }

        // Read the tag out of the ORIGINAL bytes through a data: stream, never a
        // path, so this can never be pointed at another file on disk.
        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        } catch (\Throwable) {
            return 0;
        }

        return match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 0) : 0)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    /**
     * The original bytes with every metadata carrier removed and every pixel left
     * alone — what the never-larger fallback hands back in place of the raw
     * upload. Anything that cannot be parsed confidently comes back unchanged, in
     * which case the re-encoded copy is almost always the smaller of the two.
     */
    private function stripMetadata(string $bytes, string $format): string
    {
        return match ($format) {
            'jpeg' => $this->stripJpegSegments($bytes),
            'png' => $this->stripPngChunks($bytes),
            default => $bytes,
        };
    }

    /** Drop APP1..APP15 and COM: between them, everywhere a camera writes GPS. */
    private function stripJpegSegments(string $bytes): string
    {
        $length = strlen($bytes);
        $out = substr($bytes, 0, 2);          // SOI
        $offset = 2;

        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xFF) {           // fill byte sitting before the marker
                $offset++;

                continue;
            }
            if ($marker === 0xDA) {           // SOS: scan data runs to EOI, copy it whole
                return $out.substr($bytes, $offset);
            }

            $size = (int) (unpack('n', substr($bytes, $offset + 2, 2))[1] ?? 0);
            if ($size < 2 || $offset + 2 + $size > $length) {
                return $bytes;                // malformed; never risk a truncated image
            }

            if (! ($marker >= 0xE1 && $marker <= 0xEF) && $marker !== 0xFE) {
                $out .= substr($bytes, $offset, 2 + $size);
            }

            $offset += 2 + $size;
        }

        return $bytes;                        // no SOS reached, so this was not parseable
    }

    private function stripPngChunks(string $bytes): string
    {
        $length = strlen($bytes);
        $out = substr($bytes, 0, 8);          // signature
        $offset = 8;

        while ($offset + 12 <= $length) {
            $size = (int) (unpack('N', substr($bytes, $offset, 4))[1] ?? 0);
            $type = substr($bytes, $offset + 4, 4);

            if ($size < 0 || $offset + 12 + $size > $length) {
                return $bytes;
            }

            if (! in_array($type, self::PNG_METADATA_CHUNKS, true)) {
                $out .= substr($bytes, $offset, 12 + $size);
            }

            $offset += 12 + $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return $bytes;
    }

    private function encode(\GdImage $canvas, string $format): string
    {
        $encoder = match ($format) {
            'jpeg' => fn () => imagejpeg($canvas, null, self::JPEG_QUALITY),
            'gif' => fn () => imagegif($canvas),
            'webp' => fn () => function_exists('imagewebp') ? imagewebp($canvas, null, self::JPEG_QUALITY) : false,
            default => fn () => imagepng($canvas, null, self::PNG_COMPRESSION),
        };

        ob_start();
        try {
            // Re-encoding IS the metadata strip: gd writes pixels only, so EXIF —
            // including the GPS block a phone camera adds — never reaches disk.
            $ok = $encoder();
        } finally {
            $out = (string) ob_get_clean();
        }

        if (! $ok || $out === '') {
            throw new \RuntimeException('That image could not be re-encoded.');
        }

        return $out;
    }
}
