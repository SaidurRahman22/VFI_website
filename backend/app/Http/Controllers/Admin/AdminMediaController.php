<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteContent;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3F — admin image upload + media-slot registry. content_editor/owner
 * only. Uploads are magic-byte-validated, re-encoded, content-hashed.
 *
 * The slots are the pictures in the page sections that are built into the
 * markup rather than stored as rows — the hero, the four service orbs, the
 * Multi Country collage, the two partner-page visuals. Only the image id lives
 * in the database; the surrounding layout is in the HTML, which is why the list
 * of slots has to be declared somewhere rather than derived from data.
 */
class AdminMediaController extends Controller
{
    /**
     * Every image slot, in the order the console shows them.
     *
     * Declared server-side for the same reason the content and singleton
     * schemas are: the screen is built from this, so the slots are written down
     * once. js/admin.js held three separate copies of this list, one per tab,
     * and they had already drifted — its Multi Country cards are labelled
     * "portrait 7:6" for a box that is wider than it is tall.
     *
     * `where` is a sentence and not a section id because it answers the
     * question the client asked of every editable thing: which part of the
     * site is this? `size` is the pixel size that fills the section's box on a
     * high-density screen, carried over from the page this replaces. None of
     * it is a limit — the upload pipeline downscales to 1400px wide whatever
     * arrives — so it reads as a recommendation on screen.
     */
    private const SLOTS = [
        'hero' => [
            'label' => 'Hero visual',
            'where' => 'The large round photo in the blue hero at the top of the home page.',
            'size' => '900 × 900 px',
            'fallback' => null,
        ],
        'students' => [
            'label' => 'For Students',
            'where' => 'The round photo in the pink "For Students" band on the home page.',
            'size' => '800 × 800 px',
            'fallback' => null,
        ],
        'partners' => [
            'label' => 'For Partners',
            'where' => 'The round photo in the peach "For Partners" band on the home page.',
            'size' => '800 × 800 px',
            'fallback' => null,
        ],
        'franchisees' => [
            'label' => 'For Franchisees',
            'where' => 'The round photo in the blue "For Franchisees" band on the home page.',
            'size' => '800 × 800 px',
            'fallback' => null,
        ],
        'universities' => [
            'label' => 'For Universities',
            'where' => 'The round photo in the lavender "For Universities" band on the home page.',
            'size' => '800 × 800 px',
            'fallback' => null,
        ],
        'collage1' => [
            'label' => 'Multi Country — left card',
            'where' => 'The tall card on the left of the collage beside "VFI\'s Multi Country Advantage" on the home page.',
            'size' => '600 × 520 px',
            'fallback' => null,
        ],
        'collage2' => [
            'label' => 'Multi Country — top card',
            'where' => 'The upper card of the collage beside "VFI\'s Multi Country Advantage" on the home page.',
            'size' => '600 × 460 px',
            'fallback' => null,
        ],
        'collage3' => [
            'label' => 'Multi Country — bottom card',
            'where' => 'The lower card of the collage beside "VFI\'s Multi Country Advantage" on the home page.',
            'size' => '600 × 440 px',
            'fallback' => null,
        ],
        'partnerHero' => [
            'label' => 'Hero platform screenshot',
            // Not "behind": style.css hides .phero__mock once .has-img lands on
            // the wrapper, so a picture here takes the drawing's place.
            'where' => 'The large image in the hero of the public partner page, vfi-partner.html. '
                .'It replaces the drawn dashboard mock-up.',
            // width:100%; height:auto with no fixed ratio, in a box ~550px wide
            // (620px on narrower screens), so 1200 covers a 2x screen.
            'size' => 'About 1200 px wide, landscape',
            'fallback' => 'the drawn mock-up of the platform screens',
        ],
        'partnerApp' => [
            'label' => 'Mobile app visual',
            'where' => 'The image beside "Download the VFI Partner app" on the public partner page, '
                .'vfi-partner.html. It replaces the three drawn phone mock-ups.',
            // Box is ~635px wide, same width:100%/height:auto, so the legacy
            // editor's 900px hint was short of 2x.
            'size' => 'About 1280 px wide, landscape',
            'fallback' => 'the drawn mock-up of the phone screens',
        ],
    ];

    public function __construct(private readonly ImageService $images) {}

    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $request->validate([
            // server-side mime check, SVG excluded (script-carrying), ~6 MB raw cap.
            // The real magic-byte defense is ImageService (GD decode) below.
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:6144'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'quality' => ['nullable', 'integer', 'min:40', 'max:95'],
        ]);

        try {
            $id = $this->images->store(
                $request->file('file'),
                (int) $request->input('max_width', 1400),
                (int) $request->input('quality', 82),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'That file is not a valid image.'], 422);
        }

        return response()->json(['imgId' => $id], 201)->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/admin/media/slots — the slots, each with whatever is stored in
     * it. Until this existed the media map could be written but never read
     * back, so no screen could show an editor what a slot currently holds.
     */
    public function slots(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $row = SiteContent::query()->where('key', 'media')->first();
        $media = (array) ($row->value ?? []);

        $out = [];
        foreach (self::SLOTS as $key => $meta) {
            // The map records a cleared slot as null, and an import may have
            // left "" behind; both mean empty to the screen.
            $stored = $media[$key] ?? null;

            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'where' => $meta['where'],
                'size' => $meta['size'],

                // What a visitor sees when this slot is empty. null means the
                // page has a photograph of its own built in; a sentence means
                // the space shows a DRAWING instead, and a picture set here
                // takes its place. The screen has to say which, and it must not
                // hold its own list of slot names to do it.
                'fallback' => $meta['fallback'],
                'imgId' => is_string($stored) && $stored !== '' ? $stored : null,
            ];
        }

        return response()->json([
            'slots' => $out,
            // One version for the ten slots, because one row holds all ten. A
            // save quotes it back, so it has to travel with the read the way
            // the singleton editor's does.
            'version' => (int) ($row->version ?? 0),
        ])->header('Cache-Control', 'no-store');
    }

    public function setSlot(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $data = $request->validate([
            // Required, not optional. An omitted version would read as "no
            // opinion" and be waved through, which is the write this endpoint
            // is being fixed for — and the only caller is one screen, so the
            // cost of demanding it is a screen change rather than a silently
            // unguarded path that survives forever.
            'version' => ['required', 'integer', 'min:0'],
            'imgId' => ['nullable', 'string', 'max:255'],
        ]);

        $imgId = $data['imgId'] ?? null;
        $clearing = $imgId === null || $imgId === '';

        $row = SiteContent::query()->where('key', 'media')->first();
        $currentVersion = (int) ($row->version ?? 0);
        $stored = (array) ($row->value ?? []);

        // The key is a URL segment, so it is allow-listed before anything is
        // stored. Without this any key at all landed in the media map: no page
        // would read an invented one, but it counted as a reference to the
        // image it named, which is enough to keep an orphaned upload on disk
        // for good.
        //
        // Clearing is the exception, and only for a key the map already holds,
        // because not every undeclared key is invented: the static pages read
        // about 140 more through data-media (country_uk_hero, svc_visa), which
        // the editor this console replaces could write and content:import
        // copies in verbatim. Refusing to clear those as well would leave a
        // write nothing can undo, pinning its upload for good. A clear cannot
        // create a key, and render.js skips a key with no id, so what comes
        // back is the picture built into the markup.
        abort_unless(
            array_key_exists($key, self::SLOTS) || ($clearing && array_key_exists($key, $stored)),
            422,
            'Unknown image slot.',
        );

        // Optimistic concurrency, the same bargain the singleton editor makes:
        // setMedia rewrites all ten slots at once, so a save decided from a map
        // read before someone else's save would put their slot back to the
        // picture it used to hold. A stale save loses; it never clobbers.
        $media = $this->images->setMedia($key, $imgId, (int) $data['version']);

        if ($media === null) {
            return response()->json([
                'message' => 'This content was changed by someone else. Reload and reapply your edits.',
                'currentVersion' => $currentVersion,
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'media' => $media,
            'version' => (int) $data['version'] + 1,
        ])->header('Cache-Control', 'no-store');
    }
}
