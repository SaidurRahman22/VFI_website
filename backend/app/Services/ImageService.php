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

    /**
     * Validate, re-encode/downscale, store; return the new managed image id
     * (a path-style URL the frontend getImage() passes through unchanged).
     */
    public function store(UploadedFile $file, int $maxWidth = 1400, int $quality = 82): string
    {
        // Magic-byte validation: decode the ACTUAL bytes, not the extension.
        $raw = file_get_contents($file->getRealPath());
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

    /** Set/clear a media slot; reference-counted delete of the previous image. */
    public function setMedia(string $key, ?string $imgId): array
    {
        $row = SiteContent::query()->where('key', 'media')->first();
        $media = (array) ($row?->value ?? []);
        $old = $media[$key] ?? null;

        if ($imgId === null || $imgId === '') {
            unset($media[$key]);
        } else {
            $media[$key] = $imgId;
        }

        SiteContent::query()->updateOrCreate(
            ['key' => 'media'],
            ['value' => $media, 'version' => ($row->version ?? 0) + 1],
        );

        if ($old && $old !== $imgId) {
            $this->deleteIfUnreferenced($old);
        }

        return $media;
    }
}
