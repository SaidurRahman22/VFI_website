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
use App\Models\ContentAuditLog;
use App\Services\ImageService;
use App\Support\StaffAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * REMOVING IS NOT ERASING, AND THE SCREEN NOW SAYS SO TRUTHFULLY
 * destroy() soft-deletes, and the console's confirmation has always told the
 * person the item can be restored. Nothing could act on that promise until
 * trashed() and restore() existed: the generated panel that carried the only
 * restore UI there has ever been — a trashed filter and a restore bulk action —
 * is gone, so the sentence was true of the database and false of anyone without
 * a psql prompt. restore() writes its own audit row, because LogsContentAudit
 * hooks created/updated/deleted and not SoftDeletes' `restored`; putting a page
 * back on the public site is a write to the public site, and would otherwise be
 * the only such write nobody could answer for afterwards.
 *
 * AND ERASING IS A SEPARATE, OWNER-ONLY ACT
 * Soft-deleted rows were accumulating with no interface anywhere that could
 * clear them: the generated panel had no force-delete either, so the only way
 * to reclaim a row — or the image it pins in storage — was a psql prompt.
 * forceDestroy() adds one, and deliberately makes it harder to reach than the
 * removal it follows: owner-only rather than content.manage, and it refuses a
 * row that is still live. Erasing therefore always takes two decisions through
 * two routes, and the soft delete in between is the window in which a mistake
 * is still recoverable.
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
     * How many ids one bulk call may carry.
     *
     * Not a performance number: it is the size of the mistake a single
     * mis-click can make. A hundred rows is more than any of these collections
     * holds in practice, and a request asking to remove more than that is far
     * likelier to be a runaway loop than a person.
     */
    private const BULK_MAX = 100;

    /**
     * The two sentences the single-row handlers and the bulk one both say.
     *
     * Written once because they are user-visible: a bulk restore reporting a
     * row differently from the restore button that failed on the same row is
     * how a person concludes the two did different things.
     */
    private const GONE = 'That item no longer exists.';

    private const NOT_REMOVED = 'That one has not been removed — it is still on the website.';

    /**
     * Where a collection's content comes out, as a slug the console routes on
     * plus the words a person reads. Two groups rather than one list of ten:
     * these fill two different products for two different audiences, and the
     * console gives each its own sidebar entry.
     */
    private const GROUPS = [
        'public-website' => 'Public website',
        'partner-console' => 'Partner console',
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
     * marketing site, six fill the partner console that agencies sign in to. The
     * console gives each group its own sidebar entry and its own URL, so ten
     * collections never share one tab strip — ten tabs overflow a 1500px screen
     * and put six of them behind a scroll arrow. It also answers, on screen, the
     * question the client asked of every field: where does this text appear.
     */
    private const SCHEMA = [
        'events' => [
            'group' => 'public-website',
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
            'group' => 'public-website',
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
            'group' => 'public-website',
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
            'group' => 'public-website',
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
            'group' => 'partner-console',
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
            'group' => 'partner-console',
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
            'group' => 'partner-console',
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
            'group' => 'partner-console',
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
            'group' => 'partner-console',
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
            'group' => 'partner-console',
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

    public function __construct(private readonly ImageService $images) {}

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

    /**
     * The stricter gate, for the one action nothing can undo.
     *
     * Same mechanism the page-visibility toggle and the backup restore use, so
     * "owner" means one thing across the admin API rather than being decided
     * again per screen. A content editor may put anything on the website and
     * take it off again; only the account that answers for the site may destroy
     * the copy that removal kept.
     */
    private function authoriseOwner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isSuperAdmin(), 403, 'Owner only.');
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
                'group_label' => self::GROUPS[self::SCHEMA[$slug]['group']],
                'label' => self::SCHEMA[$slug]['label'],
                'count' => $model::query()->count(),
            ];
        }

        return $this->fresh(['data' => $out]);
    }

    /**
     * GET /api/admin/content/{collection} — rows, plus the schema to edit them.
     *
     * Sorting is optional and the list is in `position` order without it,
     * because position IS the order the public page renders in and that is what
     * an editor is usually looking at. A sort is for finding something in a
     * long collection — the oldest blog post, everything by one author — not
     * for changing the site, so it never writes `position` back.
     *
     * `sort` is allow-listed against the columns this collection actually
     * declares, which is the same reason the slug goes through modelFor():
     * request input must not be able to name a database object. Rejected rather
     * than ignored, so a console asking for a column that has since been
     * renamed is told, instead of quietly showing a differently-ordered list
     * under a highlighted column header.
     */
    public function index(Request $request, string $collection): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);
        $meta = self::SCHEMA[$collection];

        $params = $request->validate([
            // required_with rather than sometimes: a direction with nothing to
            // sort BY would come back in plain display order, which on screen
            // is indistinguishable from a sort that silently failed.
            // `filled` is load-bearing, not decoration. Rule::in is a
            // NON-implicit rule, so the validator skips it for a value it
            // considers empty - and these routes are the ones with the
            // empty-string-to-null middleware disabled, so `?sort=` arrives as
            // '' rather than null. Without `filled` the allow-list is skipped,
            // '' is not null so the guard below lets it through, and orderBy('')
            // reaches the database. `filled` is implicit, so it runs.
            'sort' => ['nullable', 'filled', 'required_with:direction', Rule::in($this->sortable($collection))],
            'direction' => ['nullable', 'filled', Rule::in(['asc', 'desc'])],
        ]);

        // validate() returns only the keys that were sent, so both are read
        // through a default rather than indexed into.
        $sort = $params['sort'] ?? null;
        $direction = $sort === null ? null : ($params['direction'] ?? 'asc');

        $query = $model::query();

        if ($sort !== null) {
            // Rule::in has already reduced this to one of the literals declared
            // in SCHEMA, so nothing from the request reaches the SQL. `id` last
            // because most of these columns tie — a page of equal dates would
            // otherwise come back in a different order on every request.
            $query->orderBy($sort, $direction)->orderBy('id');
        } else {
            $query->ordered();
        }

        $rows = $query->get()
            ->map(fn (ContentItem $m) => $this->row($collection, $m))
            ->values();

        return $this->fresh([
            'collection' => $collection,
            'group' => $meta['group'],
            'group_label' => self::GROUPS[$meta['group']],
            'label' => $meta['label'],
            'singular' => $meta['singular'],
            'title_key' => $meta['title_key'],
            'meta_keys' => $meta['meta_keys'],
            'fields' => $meta['fields'],
            // Which headers the console may make clickable. Sent rather than
            // derived in the browser, for the reason the group labels are:
            // a second copy of this list would drift the first time a column
            // was renamed, and the drifted copy would produce a 422 on a
            // header the person can see.
            'sortable' => $this->sortable($collection),
            'sort' => $sort,
            'direction' => $direction,
            'data' => $rows,
        ]);
    }

    /**
     * The columns this collection may be sorted by.
     *
     * Every key in SCHEMA is a real column on that table — that is what the
     * whole controller is built on — so the field list doubles as the
     * allow-list, and a column can only enter a query by being written in this
     * file. The four added here are the shared prelude every content table
     * carries and which no SCHEMA lists, because they are not editable.
     *
     * One honest limitation, not a bug: the pp_* `date` columns are varchar
     * display strings, so sorting them sorts the text. "12 Aug 2026" lands
     * before "3 Sep 2026" the way it would in a dictionary, not on a calendar.
     * The alternative — hiding the sort — would be worse, because those cards
     * are usually filed by hand in an order the text already reflects.
     *
     * @return list<string>
     */
    private function sortable(string $collection): array
    {
        return array_merge(
            array_column(self::SCHEMA[$collection]['fields'], 'key'),
            ['position', 'updated_at', 'created_at', 'id'],
        );
    }

    /**
     * GET /api/admin/content/{collection}/trashed — what has been taken off.
     *
     * A route of its own rather than a `?trashed=1` on index(), for three
     * reasons. index() answers "what is on the site" and carries the field
     * schema and the group labels the whole screen is drawn from, none of which
     * this needs — the console asks for this only when someone opens the
     * dialog, and by then it holds the schema already. The two also disagree
     * about order: a removed row's `position` describes a list it is not in, so
     * these come back most-recently-removed first. And a handler that cannot be
     * switched into another mode by request input cannot be switched into the
     * wrong one.
     *
     * Unpaginated, like index(): a collection accumulates far more live rows
     * than removed ones, and a capped list would have to either drop an older
     * deletion silently or admit on screen that it cannot reach it.
     */
    public function trashed(string $collection): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);
        $meta = self::SCHEMA[$collection];

        $rows = $model::query()->onlyTrashed()
            ->orderByDesc('deleted_at')->orderByDesc('id')->get()
            ->map(fn (ContentItem $m) => $this->row($collection, $m) + ['removed_at' => $this->removedAt($m)])
            ->values();

        return $this->fresh([
            'collection' => $collection,
            'label' => $meta['label'],
            'singular' => $meta['singular'],
            'title_key' => $meta['title_key'],
            'meta_keys' => $meta['meta_keys'],
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
        // recoverable through restore() below and the audit row stands either
        // way.
        // The image is left in place deliberately — ids are content-hashed and
        // therefore shared, so deleting the file would blank the picture on
        // every other row using it.
        $this->find($collection, $id)->delete();

        return $this->fresh(['deleted' => $id]);
    }

    /**
     * POST /api/admin/content/{collection}/{id}/restore — one item back.
     *
     * This is the other half of the confirmation dialog's promise, so it has to
     * refuse rather than report success it did not achieve. An id nobody ever
     * created is the same 404 it is on every other handler; an id that is
     * simply still on the site is a different fact and gets its own sentence,
     * because answering "restored" there would tell someone their content had
     * been brought back when nothing happened at all.
     *
     * The slug still goes through modelFor(): a restore route must not become
     * the one place a caller can name a class.
     */
    public function restore(string $collection, int $id): JsonResponse
    {
        $this->authorise();

        $item = $this->trashable($collection, $id);

        if (! $item->trashed()) {
            return response()->json(['message' => self::NOT_REMOVED], 422);
        }

        $item->restore();
        $this->recordRestore($item);

        return $this->fresh(['item' => $this->row($collection, $item)]);
    }

    /**
     * DELETE /api/admin/content/{collection}/{id}/force — erase it for good.
     *
     * The gap this fills: nothing in any interface could permanently remove a
     * content row, so every removal since the project began is still in the
     * table, and every image any of them referenced is still on the disk that
     * the reference count keeps it on.
     *
     * Two refusals stand in front of it, and both are the point rather than
     * ceremony. It is OWNER-ONLY, a step above the content.manage that put the
     * row on the site in the first place. And it acts only on a row that is
     * ALREADY removed: erasing a live page in one call would make a single
     * mis-click on a list of live content unrecoverable, where the soft delete
     * in between gives the person a list they can read, think about, and put
     * back from.
     */
    public function forceDestroy(Request $request, string $collection, int $id): JsonResponse
    {
        $this->authorise();
        $this->authoriseOwner($request);

        $item = $this->trashable($collection, $id);

        if (! $item->trashed()) {
            return response()->json([
                'message' => 'Remove this from the website first. Erasing only applies to something already '
                    .'removed, and unlike removing it, it cannot be undone.',
            ], 422);
        }

        $image = $this->imageIdOf($collection, $item);

        DB::transaction(function () use ($item) {
            /*
             * Before the erasure, because after it there is nothing left to
             * describe: this row is the only surviving copy of what the page
             * said. The model's audit trait does fire its own `delete` row here
             * too — forceDelete() still raises `deleted` — but that row is
             * indistinguishable from the recoverable removal that preceded it,
             * and the difference between "taken off the site" and "gone" is the
             * whole question someone reads an audit log to answer.
             *
             * In one transaction with the delete so the two cannot come apart.
             * An audit row for an erasure that did not happen would be a lie
             * about the site's history; an erasure with no audit row is one
             * with nobody's name on it.
             */
            ContentAuditLog::record(
                'force_delete', $item->getTable(), $item->legacy_id, $item->getAttributes(), null
            );

            $item->forceDelete();
        });

        /*
         * After the row is gone, never before. ImageService counts trashed rows
         * as references — correctly, since a restore would need the picture
         * back — so a release attempted first would always find the row being
         * erased still holding its own image, and the file would stay on disk
         * forever. It is reference-counted either way: ids are content-hashed
         * and therefore shared, and this deletes the file only when nothing
         * else, live or removed or a media slot, still points at it.
         */
        $this->images->deleteIfUnreferenced($image);

        return $this->fresh(['erased' => $id, 'legacy_id' => $item->legacy_id]);
    }

    /**
     * POST /api/admin/content/{collection}/bulk — remove or restore many.
     *
     * Reports per id, and that shape is the feature. Clearing a tidied-up
     * gallery is the case this exists for, and in it some of the ids are
     * routinely stale — another editor got there first, or the tab has been
     * open since yesterday. A single ok/failed for the batch would either
     * refuse all ninety-nine good ones over one stale id, or claim the whole
     * selection worked. Both are worse than saying which.
     *
     * Ids that cannot be acted on are results, not exceptions, so they do not
     * roll their neighbours back. A real database failure still aborts the
     * whole batch, which is why the loop runs inside a transaction: a caller
     * that asked for one action over a list must never be left with half of it
     * applied and no record of which half.
     */
    public function bulk(Request $request, string $collection): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);

        $data = $request->validate([
            'action' => ['required', Rule::in(['delete', 'restore'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        // Deduplicated rather than rejected for duplicates: a selection built
        // from a list the person scrolled can honestly contain the same row
        // twice, and refusing the batch over it helps nobody. Each id is then
        // answered exactly once, so the counts below add up.
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $restoring = $data['action'] === 'restore';

        // One query for the batch, not one per id: a hundred round trips inside
        // a transaction is a hundred rows held locked for no reason.
        $found = $model::query()->withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        $results = DB::transaction(function () use ($ids, $found, $restoring) {
            $out = [];
            foreach ($ids as $id) {
                $out[] = $this->applyBulk($found->get($id), $id, $restoring);
            }

            return $out;
        });

        $succeeded = count(array_filter($results, fn (array $r) => $r['ok']));

        return $this->fresh([
            'action' => $data['action'],
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed' => count($results) - $succeeded,
            'results' => $results,
        ]);
    }

    /**
     * One id of a bulk batch, and the sentence to show if it did not happen.
     *
     * The refusals mirror the single-row handlers exactly — same conditions,
     * same words — because the console offers both routes to the same act, and
     * a row that a bulk restore skipped must not read differently from the same
     * row's own restore button failing.
     *
     * @return array{id:int, ok:bool, message:?string}
     */
    private function applyBulk(?ContentItem $item, int $id, bool $restoring): array
    {
        if ($item === null) {
            return ['id' => $id, 'ok' => false, 'message' => self::GONE];
        }

        if ($restoring) {
            if (! $item->trashed()) {
                return ['id' => $id, 'ok' => false, 'message' => self::NOT_REMOVED];
            }

            $item->restore();
            $this->recordRestore($item);

            return ['id' => $id, 'ok' => true, 'message' => null];
        }

        if ($item->trashed()) {
            /*
             * The single-row destroy() answers 404 here, because find() cannot
             * see a trashed row at all and an id it cannot find is genuinely
             * unknown to it. In a batch the row IS known — it was on the list
             * the person selected from — and "someone removed this before you
             * did" is both true and the thing they need to know. Calling
             * delete() again would also move deleted_at, quietly resetting how
             * long the row has been in the removed list.
             */
            return ['id' => $id, 'ok' => false, 'message' => 'That one had already been removed.'];
        }

        $item->delete();

        return ['id' => $id, 'ok' => true, 'message' => null];
    }

    /**
     * The audit row a restore leaves, wherever the restore came from.
     *
     * A restore leaves TWO audit rows, and that is deliberate. Laravel's
     * SoftDeletes::restore() calls save(), which fires `updated`, and the audit
     * trait hooks `updated` — so the before/after of clearing deleted_at is
     * already recorded. This row exists so the undo is findable BY NAME: nobody
     * searching an audit log for what happened to a page will think to look for
     * an `update` whose only difference is a nulled timestamp. Same entity and
     * id shape as the trait's, so a delete and its undo come back in one query.
     */
    private function recordRestore(ContentItem $item): void
    {
        ContentAuditLog::record(
            'restore', $item->getTable(), $item->legacy_id, null, $item->getAttributes()
        );
    }

    /**
     * A row including the removed ones, for the handlers whose whole subject is
     * a removal. An id nobody ever created is the same 404 it is everywhere
     * else; whether the row is trashed is a different question, and each caller
     * answers it in its own words.
     */
    private function trashable(string $collection, int $id): ContentItem
    {
        $item = $this->modelFor($collection)::query()->withTrashed()->find($id);
        abort_if($item === null, 404, self::GONE);

        return $item;
    }

    /**
     * The uploaded image this row points at, if its collection has one.
     *
     * Read out of SCHEMA rather than by probing for an `img_id` attribute: six
     * of the ten collections have no image column, and a missing attribute and
     * an empty one are worth telling apart when the answer decides whether a
     * file is deleted.
     */
    private function imageIdOf(string $collection, ContentItem $m): ?string
    {
        foreach (self::SCHEMA[$collection]['fields'] as $f) {
            if ($f['type'] === 'image') {
                $v = $m->getAttribute($f['key']);

                return is_string($v) && $v !== '' ? $v : null;
            }
        }

        return null;
    }

    /**
     * PUT /api/admin/content/{collection}/{id}/move — up, down, top or bottom.
     *
     * Order is what the public site renders, so it has to be editable. Swapping
     * positions with the immediate neighbour leaves every other row untouched,
     * so two editors reordering different parts of a long list do not overwrite
     * each other the way a renumber-the-whole-list save would.
     *
     * `top` and `bottom` are here because one step per request was the only
     * move the screen could make: a photo uploaded to the end of a thirty-item
     * gallery took twenty-nine clicks and twenty-nine round trips to reach the
     * front of it. Drag-to-reorder, which the generated panel did have, is not
     * replaced by this.
     */
    public function move(Request $request, string $collection, int $id): JsonResponse
    {
        $this->authorise();
        $model = $this->modelFor($collection);

        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down', 'top', 'bottom'])],
        ])['direction'];

        // `ordered()` is position ASC, so "up" and "top" both mean lower.
        $up = $direction === 'up' || $direction === 'top';
        $item = $this->find($collection, $id);

        if ($direction === 'top' || $direction === 'bottom') {
            return $this->moveToEnd($model, $item, $up);
        }

        /*
         * The neighbour has to be found by the SAME key the list is ordered by,
         * which is (position, id) — not by position alone. Positions can tie:
         * moveToEnd() takes min-1, so two people each sending a different row to
         * the top inside one read window land on the same number, and
         * ContentItem does the same thing to every new row. With a
         * position-only lookup the row that is visibly second would find no
         * neighbour above it and "one step up" would refuse — a button that
         * does nothing, on a row the person can see is not first.
         */
        $p = $item->position;
        $neighbour = $model::query()
            ->when($up,
                fn ($q) => $q
                    ->where(fn ($w) => $w->where('position', '<', $p)
                        ->orWhere(fn ($t) => $t->where('position', $p)->where('id', '<', $item->id)))
                    ->orderByDesc('position')->orderByDesc('id'),
                fn ($q) => $q
                    ->where(fn ($w) => $w->where('position', '>', $p)
                        ->orWhere(fn ($t) => $t->where('position', $p)->where('id', '>', $item->id)))
                    ->orderBy('position')->orderBy('id'))
            ->first();

        if ($neighbour === null) {
            return $this->alreadyAtTheEnd($up);
        }

        // forceFill: `position` is kept out of the request-facing path on
        // purpose, but this IS the reorder, so it writes it directly.
        if ($neighbour->position === $p) {
            /*
             * Tied, so the two are separated only by id and swapping the numbers
             * would change nothing. Step the moving row past the neighbour
             * instead. One row written, and it may tie with something else at
             * that number — which is fine, because it still sorts on the right
             * side of the neighbour.
             */
            $to = $up ? $p - 1 : $p + 1;
            $item->forceFill(['position' => $to])->save();

            return $this->fresh(['moved' => $id, 'position' => $to]);
        }

        [$a, $b] = [$p, $neighbour->position];

        $item->forceFill(['position' => $b])->save();
        $neighbour->forceFill(['position' => $a])->save();

        return $this->fresh(['moved' => $id, 'position' => $b]);
    }

    /**
     * The far end of the list in one request.
     *
     * Past the current extreme rather than renumbering everything in between,
     * so this writes exactly one row — the same property that makes the
     * neighbour swap safe while someone else is reordering another part of the
     * list. `min - 1` is also precisely where ContentItem puts a brand-new row,
     * so "move to top" lands a row where a fresh one would have landed. Two
     * people sending different rows to the same end can land on the same
     * number, and are then separated by id: an ambiguity between those two
     * rows, where renumbering would have rewritten the whole list under
     * whichever of them saved second.
     *
     * @param  class-string<ContentItem>  $model
     */
    private function moveToEnd(string $model, ContentItem $item, bool $up): JsonResponse
    {
        // Which row is at the end is settled by identity, not by position:
        // `ordered()` breaks a tie on position with id, and ties do happen,
        // since every new row takes min - 1 off the same list.
        $edge = $up
            ? $model::query()->ordered()->first()
            : $model::query()->orderByDesc('position')->orderByDesc('id')->first();

        if ($edge?->getKey() === $item->getKey()) {
            return $this->alreadyAtTheEnd($up);
        }

        $position = $up
            ? (int) $model::query()->min('position') - 1
            : (int) $model::query()->max('position') + 1;

        // forceFill, for the reason move() gives: `position` is deliberately
        // kept off the request-facing path, and this is the reorder itself.
        $item->forceFill(['position' => $position])->save();

        return $this->fresh(['moved' => $item->id, 'position' => $position]);
    }

    /** Said by both reorder paths. It is information, not a failure. */
    private function alreadyAtTheEnd(bool $up): JsonResponse
    {
        return response()->json(
            ['message' => 'This is already '.($up ? 'first' : 'last').' in the list.'], 422
        );
    }

    private function find(string $collection, int $id): ContentItem
    {
        $item = $this->modelFor($collection)::query()->find($id);
        abort_if($item === null, 404, self::GONE);

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
     * `updated_at` is not editable and has no field, but it goes out anyway:
     * "what did I change last" is the question an editor opens this screen
     * with, and without the column here the console could not offer that sort
     * at all — the one sort every list of edited things is expected to have.
     *
     * @return array<string, mixed>
     */
    private function row(string $collection, ContentItem $m): array
    {
        $out = [
            'id' => $m->id,
            'legacy_id' => $m->legacy_id,
            'position' => $m->position,
            'updated_at' => $this->iso($m->getAttribute('updated_at')),
        ];

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

    /** When an item was taken off the site, as ISO-8601. */
    private function removedAt(ContentItem $m): ?string
    {
        return $this->iso($m->getAttribute('deleted_at'));
    }

    /**
     * A timestamp for the browser, or nothing at all.
     *
     * A time only reads honestly in the reader's own timezone, and converting
     * it is the browser's job rather than this one's. Eloquent casts both
     * timestamp columns, so this is a Carbon; anything else and the screen
     * shows a blank instead of printing a guess at what the value meant.
     */
    private function iso(mixed $v): ?string
    {
        return $v instanceof \DateTimeInterface ? $v->format(\DateTimeInterface::ATOM) : null;
    }

    /** @param  array<string, mixed>  $body */
    private function fresh(array $body, int $status = 200): JsonResponse
    {
        // Staff content behind a session: never let a proxy or the browser keep
        // a copy of it.
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
