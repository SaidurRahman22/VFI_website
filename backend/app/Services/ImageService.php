<?php

namespace App\Services;

use App\Models\Content\Blog;
use App\Models\Content\Event;
use App\Models\Content\NewsItem;
use App\Models\Content\Photo;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3F — server-side image pipeline (docs §5). R2 is deferred, so images
 * land on the local `public` disk (served same-origin at /storage/media/…).
 * Pipeline: magic-byte validation (NOT the client extension) → GD re-encode +
 * downscale → JPEG (flattens transparency to white, strips EXIF) →
 * content-hashed immutable name. Deletion is reference-counted; bundled
 * path-style ids (assets/img/*.jpg) are never touched.
 */
class ImageService
{
    /** Disk-relative prefix + the public URL prefix for managed uploads. */
    private const DIR = 'media';

    private const URL_PREFIX = '/storage/media/';

    /** Models that carry an img_id (for reference counting). */
    private const IMG_MODELS = [Event::class, Blog::class, NewsItem::class, Photo::class];

    public function __construct(private readonly ImageOptimiser $optimiser) {}

    /**
     * Validate, re-encode/downscale, store; return the new managed image id
     * (a path-style URL the frontend getImage() passes through unchanged).
     */
    public function store(UploadedFile $file, int $maxWidth = 1400, int $quality = 82): string
    {
        // Magic-byte validation: decode the ACTUAL bytes, not the extension.
        $raw = file_get_contents($file->getRealPath());

        /*
         * Dimensions BEFORE the decode, or the decode is the attack.
         * `max:6144` on the request bounds the bytes on the wire, not the
         * bitmap they expand to: a ~100 KB 20000x20000 single-colour PNG is a
         * legitimate-looking upload that then asks gd for ~1.6 GB. The gate is
         * ImageOptimiser's, called rather than copied so there is one limit.
         */
        $this->optimiser->assertSafeToDecode($raw);

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new \RuntimeException('Not a valid image.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxWidth / max(1, $w));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten alpha to white (as today)
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, $quality);        // re-encode → strips EXIF/metadata
        $bytes = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $name = hash('sha256', $bytes).'.jpg';   // content-hashed, immutable
        Storage::disk('public')->put(self::DIR.'/'.$name, $bytes);

        $id = self::URL_PREFIX.$name;
        ContentAuditLog::record('create', 'media', $id, null, ['bytes' => strlen($bytes), 'w' => $nw, 'h' => $nh]);

        return $id;
    }

    /** A managed upload id (vs a bundled path-style asset or an external URL). */
    public function isManaged(?string $id): bool
    {
        return is_string($id) && str_starts_with($id, self::URL_PREFIX);
    }

    /** How many collection rows / media slots reference this image id. */
    public function referenceCount(string $id): int
    {
        $n = 0;
        foreach (self::IMG_MODELS as $model) {
            $n += $model::query()->withTrashed()->where('img_id', $id)->count();
        }
        foreach ((array) SiteContent::value('media', []) as $v) {
            if ($v === $id) {
                $n++;
            }
        }

        /*
         * The JSON singletons hold image ids too, and missing them deletes a
         * picture out from under a live page.
         *
         * An upload is named after the sha256 of its re-encoded bytes, so the
         * SAME photograph uploaded twice gets the SAME id. Put a campus photo on
         * a blog row and also on a country's university card, then erase the
         * blog: referenceCount saw only the four img_id models and the flat
         * `media` map, returned 0, and deleted the file - taking the country
         * page's logo with it. Country images made that reachable; regions and
         * servicesPage have had the same hole since they gained image fields.
         *
         * Recursive because the shape differs per key: countries nest
         * slug -> list -> row -> field, regions nest bands with img1/img2/img3,
         * servicesPage is a flat list of blocks. An exact string match over
         * every scalar is both simpler and safer than teaching this method each
         * of those shapes - a shape it does not know about would silently count
         * zero, which is the failure being fixed.
         *
         * Only on the delete path, so four extra reads cost nothing.
         */
        foreach (self::JSON_IMAGE_KEYS as $key) {
            $n += self::countInTree(SiteContent::value($key, []), $id);
        }

        return $n;
    }

    /** Singletons whose stored JSON can hold an image id anywhere inside it. */
    private const JSON_IMAGE_KEYS = ['countries', 'regions', 'servicesPage', 'universityPage'];

    /** Every scalar in the tree that equals this id, however deeply nested. */
    private static function countInTree(mixed $node, string $id): int
    {
        if (is_string($node)) {
            return $node === $id ? 1 : 0;
        }
        if (! is_array($node)) {
            return 0;
        }

        $n = 0;
        foreach ($node as $child) {
            $n += self::countInTree($child, $id);
        }

        return $n;
    }

    /** Delete the file ONLY if it's a managed upload and nothing references it. */
    public function deleteIfUnreferenced(?string $id): void
    {
        if (! $this->isManaged($id)) {
            return;   // bundled/external id → never touch a static file
        }
        if ($this->referenceCount($id) > 0) {
            return;
        }
        Storage::disk('public')->delete(self::DIR.'/'.basename($id));
    }

    /**
     * Set/clear a media slot; reference-counted delete of the previous image.
     *
     * Every slot lives in the one JSONB row that this rewrites whole, so a
     * caller editing on a person's behalf passes the version it read and gets
     * null back if the row has moved since — its save is refused rather than
     * flattening the slot someone else just changed. The check sits here and
     * not in the caller because the read it compares against is the same read
     * the write is built from; there is no window in between. Passing null
     * skips it, which is what a caller with nothing stale behind it wants.
     */
    public function setMedia(string $key, ?string $imgId, ?int $expectVersion = null): ?array
    {
        $row = SiteContent::query()->where('key', 'media')->first();
        $version = (int) ($row->version ?? 0);

        if ($expectVersion !== null && $expectVersion !== $version) {
            return null;
        }

        $media = (array) ($row?->value ?? []);
        $old = $media[$key] ?? null;

        if ($imgId === null || $imgId === '') {
            unset($media[$key]);
        } else {
            $media[$key] = $imgId;
        }

        SiteContent::query()->updateOrCreate(
            ['key' => 'media'],
            ['value' => $media, 'version' => $version + 1],
        );

        if ($old && $old !== $imgId) {
            $this->deleteIfUnreferenced($old);
        }

        return $media;
    }
}
