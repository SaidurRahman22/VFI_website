<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content\Blog;
use App\Models\Content\ContentItem;
use App\Models\Content\Event;
use App\Models\Content\NewsItem;
use App\Models\Content\Photo;
use App\Models\Content\PpDoc;
use App\Models\Content\PpEmail;
use App\Models\Content\PpManager;
use App\Models\Content\PpNotif;
use App\Models\Content\PpQuicklink;
use App\Models\Content\PpUpdate;
use App\Support\StaffAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for the ten website-content collections, for the new admin console.
 *
 * WHY ONE CONTROLLER AND NOT TEN
 * The panel this replaces gave each collection its own generated screen, which
 * is how its sidebar reached 21 entries. These ten differ only in their FIELDS,
 * so the shape is declared once in SCHEMA below and served to the client, which
 * renders one screen with tabs. Adding a collection is a registry line plus a
 * schema entry, not a new page.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * Nothing here re-implements the content contract. App\Models\Content\ContentItem
 * still owns the append-only audit row on every write (LogsContentAudit), soft
 * deletes, `position` ordering with new-items-to-front, and the auto-minted
 * immutable legacy_id. Blog additionally strips HTML from its body on save — a
 * stored-XSS contract — and that survives untouched because every write goes
 * through the model, never through a raw query.
 *
 * SAFETY OF THE DYNAMIC MODEL LOOKUP
 * The collection slug arrives in the URL, so it is resolved through a private
 * allow-list and an unknown slug is a 404 before anything else happens. A class
 * name is never built from request input. Only keys present in SCHEMA are
 * validated and only those reach the model, so a caller cannot set `position`
 * or `legacy_id` by adding them to the payload — for blogs, legacy_id IS the
 * public article URL key, and letting a client choose it would let it take over
 * an already-published URL.
 */
class AdminContentCollectionController extends Controller
{
    /** slug => model. The ONLY route by which a slug becomes a class. */
    private const COLLECTIONS = [
        'events' => Event::class,
        'blogs' => Blog::class,
        'news' => NewsItem::class,
        'photos' => Photo::class,
        'pp-managers' => PpManager::class,
        'pp-updates' => PpUpdate::class,
        'pp-quicklinks' => PpQuicklink::class,
        'pp-docs' => PpDoc::class,
        'pp-emails' => PpEmail::class,
        'pp-notifs' => PpNotif::class,
    ];

    /**
     * The three card gradients the stylesheets define (`blog__media--a` …
     * `news__media--b`), used when a card has no image. Stored in a varchar(8),
     * hence the single-letter keys.
     */
    private const COLORS = [
        ['value' => 'a', 'label' => 'Blue → Violet'],
        ['value' => 'b', 'label' => 'Coral → Gold'],
        ['value' => 'c', 'label' => 'Green → Blue'],
    ];

    /**
     * How each collection is edited. `type` drives BOTH the input the console
     * renders and the validation applied, so the form and the rules cannot
     * drift apart. Labels and hints carry over from the panel this replaces so
     * the screens stay recognisable to whoever has been using it.
     *
     * Two corrections against that old field table, both deliberate:
     *
     *  - `date` is a real date input ONLY for events and blogs, whose columns
     *    are DATE. The five pp_* `date` columns are varchar display strings
     *    ("12 Aug 2026", "Aug 2026") that the old panel wrongly offered as date
     *    pickers — a picker cannot display a value it cannot parse, so it
     *    renders blank and the next save silently destroys the stored text.
     *    They are text fields here, with a hint saying the value prints verbatim.
     *
     *  - photos drops the old `title` field, which had no column at all and so
     *    was discarded on every save, and offers `alt` instead, which does have
     *    one and is what a screen reader reads out in place of the photo.
     *
     * `group` says WHERE the collection comes out: four of these fill the public
     * marketing site, six fill the partner console that agencies sign in to. Ten
     * tabs in one strip overflow a 1500px screen, which put the six partner ones
     * permanently behind a scroll arrow; grouped, each strip fits, and the
     * heading answers the question the client asked — where does this text
     * actually appear.
     */
    private const SCHEMA = [
        'events' => [
            'group' => 'Public website',
            'label' => 'Events',
            'singular' => 'Event',
            'title_key' => 'title',
            'meta_keys' => ['date', 'city', 'type'],
            'fields' => [
                ['key' => 'title', 'label' => 'Event title', 'type' => 'text', 'required' => true,
                    'placeholder' => 'UK University Spot Admissions Day'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'half' => true],
                ['key' => 'time', 'label' => 'Time', 'type' => 'text', 'half' => true,
                    'placeholder' => '10:00 am – 5:00 pm'],
                ['key' => 'type', 'label' => 'Event type', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Spot Assessment'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Dhaka / Online'],
                ['key' => 'description', 'label' => 'Short description', 'type' => 'textarea',
                    'placeholder' => 'What happens at this event?'],
                ['key' => 'color', 'label' => 'Card colour', 'type' => 'select', 'options' => self::COLORS,
                    'hint' => 'Used only when the card has no image.'],
                ['key' => 'img_id', 'label' => 'Cover image', 'type' => 'image',
                    'hint' => '1200 × 600 px (landscape 2:1)'],
            ],
        ],
        'blogs' => [
            'group' => 'Public website',
            'label' => 'Blog posts',
            'singular' => 'Blog post',
            'title_key' => 'title',
            'meta_keys' => ['category', 'date', 'author'],
            'fields' => [
                ['key' => 'title', 'label' => 'Post title', 'type' => 'text', 'required' => true,
                    'placeholder' => '10 scholarships students often miss'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Scholarships'],
                ['key' => 'date', 'label' => 'Publish date', 'type' => 'date', 'half' => true],
                ['key' => 'excerpt', 'label' => 'Excerpt', 'type' => 'textarea',
                    'placeholder' => 'One or two lines shown on the card.'],
                ['key' => 'body', 'label' => 'Body', 'type' => 'textarea', 'rows' => 14,
                    'hint' => 'Plain text. Leave a blank line between paragraphs. Start a line with "## " for a '
                        .'subheading, "- " for a bullet, "> " for a pull quote. HTML is removed when you save.'],
                ['key' => 'author', 'label' => 'Author', 'type' => 'text', 'half' => true,
                    'placeholder' => 'VFI Editorial Team'],
                ['key' => 'read_time', 'label' => 'Read time', 'type' => 'text', 'half' => true,
                    'placeholder' => '6 min read', 'hint' => 'Leave blank and the article page counts the words.'],
                ['key' => 'color', 'label' => 'Card colour', 'type' => 'select', 'options' => self::COLORS,
                    'hint' => 'Used only when the card has no image.'],
                ['key' => 'img_id', 'label' => 'Cover image', 'type' => 'image',
                    'hint' => '1200 × 600 px (landscape 2:1)'],
            ],
        ],
        'news' => [
            'group' => 'Public website',
            'label' => 'News & updates',
            'singular' => 'News item',
            'title_key' => 'title',
            'meta_keys' => [],
            'fields' => [
                ['key' => 'title', 'label' => 'Headline', 'type' => 'text', 'required' => true],
                ['key' => 'excerpt', 'label' => 'Summary', 'type' => 'textarea'],
                ['key' => 'color', 'label' => 'Card colour', 'type' => 'select', 'options' => self::COLORS,
                    'hint' => 'Used only when the card has no image.'],
                ['key' => 'img_id', 'label' => 'Image', 'type' => 'image', 'hint' => '1200 × 500 px (wide 12:5)'],
            ],
        ],
        'photos' => [
            'group' => 'Public website',
            'label' => 'Photo gallery',
            'singular' => 'Photo',
            'title_key' => 'caption',
            'meta_keys' => ['alt'],
            'fields' => [
                ['key' => 'img_id', 'label' => 'Photo', 'type' => 'image', 'hint' => '1200 × 900 px (4:3)'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ['key' => 'alt', 'label' => 'Alt text', 'type' => 'text',
                    'hint' => 'Read aloud by screen readers in place of the photo. Describe what is in it.'],
            ],
        ],
        'pp-managers' => [
            'group' => 'Partner console',
            'label' => 'Regional managers',
            'singular' => 'Regional manager',
            'title_key' => 'name',
            'meta_keys' => ['role', 'city', 'phone'],
            'fields' => [
                ['key' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true,
                    'placeholder' => 'Tahmeed Rahman'],
                ['key' => 'role', 'label' => 'Role / title', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Regional Manager'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text', 'half' => true, 'placeholder' => 'Dhaka'],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'half' => true,
                    'placeholder' => '+880 1700-000000'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'half' => true,
                    'placeholder' => 'name@vfi-fc.com'],
            ],
        ],
        'pp-updates' => [
            'group' => 'Partner console',
            'label' => 'Important updates',
            'singular' => 'Update',
            'title_key' => 'title',
            'meta_keys' => ['sub', 'date'],
            'fields' => [
                ['key' => 'title', 'label' => 'Update title', 'type' => 'text', 'required' => true,
                    'placeholder' => 'Applications now open for the May 2027 intake'],
                ['key' => 'flag', 'label' => 'Flag', 'type' => 'text', 'half' => true, 'placeholder' => '🇨🇦',
                    'hint' => 'An emoji flag or a 2-letter code, shown as a pill.'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text', 'half' => true, 'placeholder' => '12 Aug 2026',
                    'hint' => 'Printed on the card exactly as typed.'],
                ['key' => 'sub', 'label' => 'Sub-line', 'type' => 'text',
                    'placeholder' => 'Partner university · Ontario'],
            ],
        ],
        'pp-quicklinks' => [
            'group' => 'Partner console',
            'label' => 'Quick links',
            'singular' => 'Quick link',
            'title_key' => 'label',
            'meta_keys' => ['url'],
            'fields' => [
                ['key' => 'label', 'label' => 'Link label', 'type' => 'text', 'required' => true,
                    'placeholder' => 'VFI Represented Universities'],
                ['key' => 'url', 'label' => 'Link URL', 'type' => 'url',
                    'placeholder' => 'partner-resources.html',
                    'hint' => 'A page on this site, or a full https:// address.'],
            ],
        ],
        'pp-docs' => [
            'group' => 'Partner console',
            'label' => 'Learning documents',
            'singular' => 'Document',
            'title_key' => 'title',
            'meta_keys' => ['category', 'country', 'size', 'date'],
            'fields' => [
                ['key' => 'title', 'label' => 'Document title', 'type' => 'text', 'required' => true,
                    'placeholder' => 'Student Enquiry Form'],
                ['key' => 'country', 'label' => 'Country', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Australia'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'half' => true,
                    'placeholder' => 'Enquiry Form'],
                ['key' => 'size', 'label' => 'File size', 'type' => 'text', 'half' => true,
                    'placeholder' => '0.16 MB', 'hint' => 'Shown on the card exactly as typed.'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text', 'half' => true,
                    'placeholder' => '12 Aug 2026', 'hint' => 'Printed on the card exactly as typed.'],
                ['key' => 'url', 'label' => 'Download URL', 'type' => 'url', 'placeholder' => '#'],
            ],
        ],
        'pp-emails' => [
            'group' => 'Partner console',
            'label' => 'Email updates',
            'singular' => 'Email update',
            'title_key' => 'subject',
            'meta_keys' => ['date'],
            'fields' => [
                ['key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true,
                    'placeholder' => 'Upcoming UK deadlines for the Fall 2026 intake'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text', 'placeholder' => '12 Aug 2026',
                    'hint' => 'Printed exactly as typed.'],
            ],
        ],
        'pp-notifs' => [
            'group' => 'Partner console',
            'label' => 'Notifications',
            'singular' => 'Notification',
            'title_key' => 'title',
            'meta_keys' => ['date'],
            'fields' => [
                ['key' => 'title', 'label' => 'Notification title', 'type' => 'text', 'required' => true,
                    'placeholder' => "Your student's application was submitted"],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text', 'half' => true,
                    'placeholder' => '12 Aug 2026', 'hint' => 'Printed exactly as typed.'],
                ['key' => 'message', 'label' => 'Details', 'type' => 'textarea',
                    'placeholder' => 'A short line with more detail.'],
            ],
        ],
    ];

    /**
     * `content.manage` on every handler, read included.
     *
     * The route group this sits in only proves an admin session with TOTP, not
     * which staff role is on the other end — a document reviewer must not be
     * able to rewrite the public website. Re-checked per handler rather than
     * once in a constructor so that adding a method cannot silently opt out.
     */
    private function authorise(): void
    {
        abort_unless(StaffAbilities::current('content.manage'), 403);
    }

    /** @return class-string<ContentItem> */
    private function modelFor(string $collection): string
    {
        // Allow-list, not string interpolation: the slug is request input and
        // must never be able to name a class.
        abort_unless(isset(self::COLLECTIONS[$collection]), 404, 'Unknown collection.');

        return self::COLLECTIONS[$collection];
    }

    /** GET /api/admin/content/collections — the tab strip, with live counts. */
    public function collections(): JsonResponse
    {
        $this->authorise();

        $out = [];
        foreach (self::COLLECTIONS as $slug => $model) {
            $out[] = [
                'slug' => $slug,
                'group' => self::SCHEMA[$slug]['group'],
                'label' => self::SCHEMA[$slug]['label'],
                'count' => $model::query()->count(),
            ];
        }

        return $this->fresh(['data' => $out]);
    }

    /** GET /api/admin/content/{collection} — rows, plus the schema to edit them. */
    public function index(string $collection): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);
        $meta = self::SCHEMA[$collection];

        $rows = $model::query()->ordered()->get()
            ->map(fn (ContentItem $m) => $this->row($collection, $m))
            ->values();

        return $this->fresh([
            'collection' => $collection,
            'group' => $meta['group'],
            'label' => $meta['label'],
            'singular' => $meta['singular'],
            'title_key' => $meta['title_key'],
            'meta_keys' => $meta['meta_keys'],
            'fields' => $meta['fields'],
            'data' => $rows,
        ]);
    }

    /** POST /api/admin/content/{collection} */
    public function store(Request $request, string $collection): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);

        // position and legacy_id are NOT accepted: ContentItem mints the id and
        // puts a new row at the front of the list.
        $item = $model::create($this->validated($request, $collection));

        return $this->fresh(['item' => $this->row($collection, $item)], 201);
    }

    /** PUT /api/admin/content/{collection}/{id} */
    public function update(Request $request, string $collection, int $id): JsonResponse
    {
        $this->authorise();
        $item = $this->find($collection, $id);

        $item->fill($this->validated($request, $collection))->save();

        return $this->fresh(['item' => $this->row($collection, $item->refresh())]);
    }

    /** DELETE /api/admin/content/{collection}/{id} */
    public function destroy(string $collection, int $id): JsonResponse
    {
        $this->authorise();

        // Soft: ContentItem uses SoftDeletes, so a mistaken delete is
        // recoverable from the database and the audit row stands either way.
        // The image is left in place deliberately — ids are content-hashed and
        // therefore shared, so deleting the file would blank the picture on
        // every other row using it.
        $this->find($collection, $id)->delete();

        return $this->fresh(['deleted' => $id]);
    }

    /**
     * PUT /api/admin/content/{collection}/{id}/move — one step up or down.
     *
     * Order is what the public site renders, so it has to be editable. Swapping
     * positions with the immediate neighbour leaves every other row untouched,
     * so two editors reordering different parts of a long list do not overwrite
     * each other the way a renumber-the-whole-list save would.
     */
    public function move(Request $request, string $collection, int $id): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);

        $up = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'] === 'up';
        $item = $this->find($collection, $id);

        // `ordered()` is position ASC, so "up" means the next-lower position.
        $neighbour = $model::query()
            ->when($up,
                fn ($q) => $q->where('position', '<', $item->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $item->position)->orderBy('position'))
            ->first();

        if ($neighbour === null) {
            return response()->json(
                ['message' => 'This is already '.($up ? 'first' : 'last').' in the list.'], 422
            );
        }

        [$a, $b] = [$item->position, $neighbour->position];

        // forceFill: `position` is kept out of the request-facing path on
        // purpose, but this IS the reorder, so it writes it directly.
        $item->forceFill(['position' => $b])->save();
        $neighbour->forceFill(['position' => $a])->save();

        return $this->fresh(['moved' => $id, 'position' => $b]);
    }

    private function find(string $collection, int $id): ContentItem
    {
        $item = $this->modelFor($collection)::query()->find($id);
        abort_if($item === null, 404, 'That item no longer exists.');

        return $item;
    }

    /**
     * Only the keys in SCHEMA, validated by their declared type. Anything else
     * in the payload is dropped rather than trusted.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $collection): array
    {
        $rules = [];
        foreach (self::SCHEMA[$collection]['fields'] as $f) {
            $rule = match ($f['type']) {
                'date' => ['nullable', 'date'],
                'email' => ['nullable', 'email:rfc', 'max:190'],
                'textarea' => ['nullable', 'string', 'max:40000'],
                // A relative page name OR a full URL, so no `url` rule: these
                // are escaped at render and never interpolated into markup.
                'url' => ['nullable', 'string', 'max:500'],
                'image' => ['nullable', 'string', 'max:255'],
                'select' => ['nullable', 'string', Rule::in(array_column($f['options'], 'value'))],
                default => ['nullable', 'string', 'max:500'],
            };

            if (! empty($f['required'])) {
                $rule[0] = 'required';
            }

            $rules[$f['key']] = $rule;
        }

        return $request->validate($rules);
    }

    /**
     * One row, shaped exactly like the form that edits it.
     *
     * Dates go out as Y-m-d because that is the only format an
     * <input type="date"> will display; given anything else it renders empty,
     * and the next save then writes that emptiness over real content.
     *
     * @return array<string, mixed>
     */
    private function row(string $collection, ContentItem $m): array
    {
        $out = ['id' => $m->id, 'legacy_id' => $m->legacy_id, 'position' => $m->position];

        foreach (self::SCHEMA[$collection]['fields'] as $f) {
            $v = $m->getAttribute($f['key']);

            if ($f['type'] === 'date' && $v !== null) {
                // pgsql hands back 'Y-m-d', SQLite 'Y-m-d 00:00:00', and a cast
                // would hand back a Carbon. Normalise all three.
                $v = $v instanceof \DateTimeInterface
                    ? $v->format('Y-m-d')
                    : substr((string) $v, 0, 10);
            }

            $out[$f['key']] = $v;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $body */
    private function fresh(array $body, int $status = 200): JsonResponse
    {
        // Staff content behind a session: never let a proxy or the browser keep
        // a copy of it.
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
