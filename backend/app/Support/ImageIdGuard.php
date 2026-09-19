<?php

namespace App\Support;

use Closure;

/**
 * The one allow-list for a stored image id.
 *
 * An image id is written into `background-image: url(...)` or an `<img src>`
 * on a PUBLIC page, so whatever it holds is fetched by every anonymous
 * visitor's browser. That makes it a third-party-request primitive: a value
 * like `https://evil.example/beacon.png` turns each page view into a hit on
 * somebody else's server carrying the visitor's IP, User-Agent and Referer.
 * Nothing is executed — a stylesheet will not run `javascript:` and the
 * frontend assigns the property rather than building markup — so this is a
 * privacy and tracking boundary rather than an XSS one, and it is still not
 * ours to give away.
 *
 * Which is why only two shapes are allowed: a file this site produced through
 * the upload re-encode, and a photo bundled in the repository. Both are
 * same-origin, so neither can reach a third party. A remote URL is refused, an
 * SVG is refused (it can carry script, and the re-encode is what strips such
 * things), and a `?v=` cache-buster is refused too — the console never writes
 * one, so a value carrying one was typed or imported rather than chosen.
 *
 * WHY IT IS A CLASS. It was a private method on AdminContentController, which
 * meant the OTHER two write paths had none: the collection editor validated an
 * image field as `string|max:255`, and the media-slot endpoint validated its
 * `imgId` the same way. Both accepted a remote URL, and the corresponding
 * frontend paths painted it. An allow-list that one of three callers owns is
 * an allow-list the next caller will not find.
 *
 * NOT the same list as js/universities.js `uSafeImg`, on purpose. That one
 * also permits `media/…` paths and plain `https://` addresses, because the
 * ingested university catalogue genuinely stores remote logo URLs and the
 * field's own hint invites them. Merging the two would either blank pictures
 * that work today or widen this list to allow the very thing it exists to
 * refuse. Different data, different policy, written down rather than unified.
 *
 * Mirrored in the browser by js/render.js `safeImgUrl`, which guards the READ
 * side for rows written before this existed and for `content:import`, which
 * copies legacy JSON in without passing through any controller. This one
 * decides what may be stored; that one decides what may be fetched.
 */
final class ImageIdGuard
{
    /** What ImageService::store() returns: sha256 of the re-encoded bytes. */
    private const MANAGED_UPLOAD = '#^/storage/media/[0-9a-f]{64}\.jpg$#';

    /** A photograph shipped in the repository, under assets/img/. */
    private const BUNDLED_ASSET = '#^assets/img/[a-z0-9._-]+\.(?:jpe?g|png|webp|gif)$#';

    /** The refusal an editor reads. It names the two things that do work. */
    public const MESSAGE = 'The website cannot load that image. Upload one, or name a photo already '
        .'bundled with the site (assets/img/…).';

    /**
     * A file on the `public` disk, as Filament's FileUpload stores it.
     *
     * A DIFFERENT shape from isUsable(), and the difference is not cosmetic:
     * this is a disk key (`media/universities/intakes/fall.jpg`), which
     * PublicUniversityController::assetUrl() turns into `/storage/…` on the way
     * out. Feeding it a value that is already a URL produces
     * `/storage/storage/…`, so the two lists are not interchangeable and a
     * field has to declare which stage it holds.
     *
     * Subdirectories are allowed because Filament writes one per field; an
     * absolute URL is not, which is the whole point — this guards
     * universityPage's intake-card images, and they are rendered on
     * university.html for anyone.
     */
    public static function isUsableAssetKey(string $id): bool
    {
        if (str_contains($id, '..')) {
            return false;
        }

        return preg_match('#^media/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp|gif)$#', $id) === 1;
    }

    /** The refusal for an asset key, which is a different field to an id. */
    public const ASSET_MESSAGE = 'That is not a picture stored on this site. Upload one on the University '
        .'defaults screen, which fills this in for you (media/universities/…).';

    /** Is this one of the two id shapes a public page may be pointed at? */
    public static function isUsable(string $id): bool
    {
        // Refused ahead of the patterns rather than trusted to them: the
        // bundled name class allows a dot, so `..` is a traversal attempt and
        // not a filename.
        if (str_contains($id, '..')) {
            return false;
        }

        return preg_match(self::MANAGED_UPLOAD, $id) === 1
            || preg_match(self::BUNDLED_ASSET, $id) === 1;
    }

    /**
     * Validation rules for a request field holding an image id.
     *
     * Trims before judging, because this value can be pasted by hand and an id
     * that fails only on a trailing space is a refusal an editor cannot see the
     * reason for. `api/admin/content*` is exempt from Laravel's TrimStrings
     * (bootstrap/app.php keeps "" meaningful there), so the trim cannot be
     * assumed to have happened already — and a caller that uses these rules
     * must store self::clean(), not the raw input, or it stores the space too.
     *
     * Empty is allowed and means "no image": clearing a picture has to stay
     * possible, and a field that has never been set arrives empty.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'string',
            'max:255',
            function (string $attribute, mixed $value, Closure $fail): void {
                $id = is_string($value) ? trim($value) : '';

                if ($id !== '' && ! self::isUsable($id)) {
                    $fail(self::MESSAGE);
                }
            },
        ];
    }

    /** The value to STORE for an id that has passed rules(): trimmed, or ''. */
    public static function clean(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
