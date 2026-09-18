<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3C — admin editor for the override singletons (docs §2). Admin-gated
 * (routes/web.php behind auth + EnsureAdmin). Optimistic concurrency: a save
 * carries the version it loaded; a stale version is rejected 409, never
 * silently overwritten. Keys are allow-listed. Values round-trip ""/[]
 * faithfully (the api/admin/content* routes have the empty-string middleware
 * disabled — Phase 0 landmine).
 */
class AdminContentController extends Controller
{
    /** Only these singleton keys may be edited here. */
    private const EDITABLE = [
        'settings', 'countries', 'regions', 'servicesPage', 'partnerPage', 'partnerPortal',
    ];

    /**
     * The FLAT singletons, as a form the console can render.
     *
     * Declared server-side for the same reason the collections are: the console
     * builds its inputs from this, so the fields are written down once. The
     * legacy admin page kept its own copy of every field list in js/admin.js,
     * and that second copy is how its photos form came to offer a field with no
     * column behind it.
     *
     * countries, regions and servicesPage are deliberately absent. They hold
     * repeating blocks per slug ({uk: {heroTitle: …, bands: [...]}}), which a
     * key-and-string form cannot express. They remain editable through this
     * endpoint unchanged; they just need a screen of their own.
     *
     * `empty_means` is on screen wherever it applies, because for these keys an
     * empty field is not "blank" - it means the page keeps the wording built
     * into it, which is the opposite of what an empty box usually implies.
     */
    private const SCHEMA = [
        'settings' => [
            'label' => 'Site settings',
            'blurb' => 'The brand, contact details and social links used across every page of the site.',
            'sections' => [
                [
                    'title' => 'Brand',
                    'fields' => [
                        ['key' => 'brand', 'label' => 'Site name', 'type' => 'text', 'half' => true],
                        ['key' => 'tagline', 'label' => 'Tagline', 'type' => 'text', 'half' => true],
                        ['key' => 'about', 'label' => 'About, in one paragraph', 'type' => 'textarea',
                            'hint' => 'Used in the footer and on the about page.'],
                    ],
                ],
                [
                    'title' => 'Contact',
                    'blurb' => 'Shown in the footer of every page and on the contact page.',
                    'fields' => [
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'half' => true],
                        ['key' => 'phone2', 'label' => 'Second phone', 'type' => 'text', 'half' => true],
                        ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'half' => true],
                        ['key' => 'hours', 'label' => 'Opening hours', 'type' => 'text', 'half' => true],
                        ['key' => 'address', 'label' => 'Full address', 'type' => 'textarea'],
                        ['key' => 'addressShort', 'label' => 'Short address', 'type' => 'text',
                            'hint' => 'The one-line version used where there is no room for the full address.'],
                    ],
                ],
                [
                    'title' => 'Social links',
                    'blurb' => 'A full https:// address. Leave "#" to show the icon without linking anywhere.',
                    'fields' => [
                        ['key' => 'facebook', 'label' => 'Facebook', 'type' => 'url', 'half' => true],
                        ['key' => 'instagram', 'label' => 'Instagram', 'type' => 'url', 'half' => true],
                        ['key' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url', 'half' => true],
                        ['key' => 'x', 'label' => 'X', 'type' => 'url', 'half' => true],
                        ['key' => 'youtube', 'label' => 'YouTube', 'type' => 'url', 'half' => true],
                    ],
                ],
            ],
        ],
        'partnerPage' => [
            'label' => 'Partner page',
            'blurb' => 'The wording on the public "become a partner" page.',
            'empty_means' => 'keeps the wording already built into the page',
            'sections' => [
                [
                    'title' => 'Hero',
                    'fields' => [
                        ['key' => 'heroTitle', 'label' => 'Heading', 'type' => 'text'],
                        ['key' => 'heroText', 'label' => 'Intro paragraph', 'type' => 'textarea'],
                        ['key' => 'heroBtn1', 'label' => 'First button', 'type' => 'text', 'half' => true],
                        ['key' => 'heroBtn2', 'label' => 'Second button', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'title' => 'Sections',
                    'fields' => [
                        ['key' => 'appTitle', 'label' => 'Application section heading', 'type' => 'text', 'half' => true],
                        ['key' => 'appText', 'label' => 'Application section text', 'type' => 'textarea'],
                        ['key' => 'featTitle', 'label' => 'Benefits heading', 'type' => 'text', 'half' => true],
                        ['key' => 'featLead', 'label' => 'Benefits intro', 'type' => 'textarea'],
                        ['key' => 'stepsTitle', 'label' => 'How it works heading', 'type' => 'text', 'half' => true],
                        ['key' => 'testTitle', 'label' => 'Testimonials heading', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'title' => 'Call to action',
                    'fields' => [
                        ['key' => 'ctaTitle', 'label' => 'Heading', 'type' => 'text', 'half' => true],
                        ['key' => 'ctaBtn', 'label' => 'Button', 'type' => 'text', 'half' => true],
                    ],
                ],
            ],
        ],
        'partnerPortal' => [
            'label' => 'Partner console text',
            'blurb' => 'Wording inside the console partner agencies sign in to.',
            'empty_means' => 'keeps the wording already built into the console',
            'sections' => [
                [
                    'title' => 'Welcome',
                    'fields' => [
                        ['key' => 'partnerName', 'label' => 'Partner name shown in the header', 'type' => 'text', 'half' => true],
                        ['key' => 'tierName', 'label' => 'Tier name', 'type' => 'text', 'half' => true],
                        ['key' => 'welcome', 'label' => 'Welcome message', 'type' => 'textarea'],
                        ['key' => 'benefits', 'label' => 'Benefits summary', 'type' => 'textarea'],
                    ],
                ],
                [
                    'title' => 'Service blurbs',
                    'fields' => [
                        ['key' => 'loanText', 'label' => 'Student loans', 'type' => 'textarea'],
                        ['key' => 'accomText', 'label' => 'Accommodation', 'type' => 'textarea'],
                        ['key' => 'testprepText', 'label' => 'Test preparation', 'type' => 'textarea'],
                    ],
                ],
            ],
        ],
    ];

    /**
     * The per-slug singletons: a set of repeating blocks, once per country or
     * region. SCHEMA above cannot describe these - it models a flat form - so
     * they are declared here instead and the console renders them with a
     * different screen. Same principle either way: the fields are written down
     * once, on the server.
     *
     * Every field below is read off what js/render.js actually consumes. Where
     * the renderer splits a value on newlines (region `facts`, services
     * `offers`) the type is `lines`, and the screen says so.
     *
     * NOT INCLUDED, on purpose: the country pages carry eighteen
     * `data-crender` attributes and render.js reads only four. admits,
     * costLiving, costStudy, coursesBachelors, coursesMasters, eligBachelors,
     * eligMasters, exams, intakes, overview, recruiters, requirements,
     * visaCosts and visaDocs are hooks nothing listens to - those sections show
     * their static HTML whatever is stored. Giving them an editor would let
     * someone type for an hour into a field no visitor can ever see, which is
     * worse than the gap. Wire the renderer first, then add them here.
     */
    private const GROUPED = [
        'countries' => [
            'label' => 'Country pages',
            'blurb' => 'Wording and listings on the six "Study in ..." pages.',
            'empty_means' => 'keeps the wording already built into that page',
            'groups' => [
                ['slug' => 'usa', 'label' => 'USA', 'page' => 'study-in-usa.html'],
                ['slug' => 'uk', 'label' => 'UK', 'page' => 'study-in-uk.html'],
                ['slug' => 'canada', 'label' => 'Canada', 'page' => 'study-in-canada.html'],
                ['slug' => 'australia', 'label' => 'Australia', 'page' => 'study-in-australia.html'],
                ['slug' => 'ireland', 'label' => 'Ireland', 'page' => 'study-in-ireland.html'],
                ['slug' => 'newzealand', 'label' => 'New Zealand', 'page' => 'study-in-new-zealand.html'],
            ],
            'fields' => [
                ['key' => 'heroTitle', 'label' => 'Hero heading', 'type' => 'text'],
                ['key' => 'heroSub', 'label' => 'Hero subheading', 'type' => 'textarea'],
                ['key' => 'overviewLead', 'label' => 'Overview intro', 'type' => 'textarea'],
            ],
            'lists' => [
                [
                    'key' => 'universities', 'label' => 'Universities', 'singular' => 'university',
                    'item' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                        ['key' => 'loc', 'label' => 'Location', 'type' => 'text', 'half' => true],
                        ['key' => 'note1', 'label' => 'First note', 'type' => 'text', 'half' => true],
                        ['key' => 'note2', 'label' => 'Second note', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'scholarships', 'label' => 'Scholarships', 'singular' => 'scholarship',
                    'item' => [
                        ['key' => 'title', 'label' => 'Name', 'type' => 'text'],
                        ['key' => 'tag', 'label' => 'Tag', 'type' => 'text', 'half' => true,
                            'hint' => 'The small label on the card, e.g. "Merit".'],
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'text', 'half' => true],
                        ['key' => 'desc', 'label' => 'Description', 'type' => 'textarea'],
                    ],
                ],
                [
                    'key' => 'salaries', 'label' => 'Graduate salaries', 'singular' => 'role',
                    'item' => [
                        ['key' => 'role', 'label' => 'Role', 'type' => 'text', 'half' => true],
                        ['key' => 'pay', 'label' => 'Pay', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'faqs', 'label' => 'FAQs', 'singular' => 'question',
                    'item' => [
                        ['key' => 'q', 'label' => 'Question', 'type' => 'text'],
                        ['key' => 'a', 'label' => 'Answer', 'type' => 'textarea'],
                    ],
                ],
            ],
        ],

        'regions' => [
            'label' => 'Region pages',
            'blurb' => 'Wording and country bands on the Asia and Europe pages.',
            'empty_means' => 'keeps the wording already built into that page',
            'groups' => [
                ['slug' => 'asia', 'label' => 'Asia', 'page' => 'asia.html'],
                ['slug' => 'europe', 'label' => 'Europe', 'page' => 'europe.html'],
            ],
            'fields' => [
                ['key' => 'heroTitle', 'label' => 'Hero heading', 'type' => 'text'],
                ['key' => 'heroSub', 'label' => 'Hero subheading', 'type' => 'textarea'],
            ],
            'lists' => [
                [
                    'key' => 'bands', 'label' => 'Country bands', 'singular' => 'band',
                    'item' => [
                        ['key' => 'name', 'label' => 'Country', 'type' => 'text', 'half' => true],
                        ['key' => 'desc', 'label' => 'Description', 'type' => 'textarea'],
                        ['key' => 'facts', 'label' => 'Quick facts', 'type' => 'lines',
                            'hint' => 'One fact per line. Each line becomes a ticked bullet.'],
                        ['key' => 'img1', 'label' => 'Image 1 slot', 'type' => 'text', 'half' => true],
                        ['key' => 'img2', 'label' => 'Image 2 slot', 'type' => 'text', 'half' => true],
                        ['key' => 'img3', 'label' => 'Image 3 slot', 'type' => 'text', 'half' => true],
                    ],
                ],
            ],
        ],

        'servicesPage' => [
            'label' => 'Services page',
            'blurb' => 'The service blocks on the public services page.',
            'empty_means' => 'keeps the wording already built into the page',
            // No slug level: this page is one of a kind.
            'groups' => [],
            'fields' => [],
            'lists' => [
                [
                    'key' => 'blocks', 'label' => 'Service blocks', 'singular' => 'service',
                    'item' => [
                        ['key' => 'name', 'label' => 'Service name', 'type' => 'text'],
                        ['key' => 'anchor', 'label' => 'Anchor', 'type' => 'text', 'half' => true,
                            'hint' => 'Used in the #link. Left empty, it is made from the name.'],
                        ['key' => 'img', 'label' => 'Image slot', 'type' => 'text', 'half' => true],
                        ['key' => 'offers', 'label' => 'What is included', 'type' => 'lines',
                            'hint' => 'One item per line. Each line becomes a starred bullet.'],
                    ],
                ],
            ],
        ],
    ];

    /**
     * GET /api/admin/content/singletons — the ones this console offers as a
     * form, so the screen does not carry its own list of them.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $out = [];
        foreach (self::SCHEMA as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'blurb' => $meta['blurb'],
            ];
        }

        return response()->json(['data' => $out])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);
        abort_unless(in_array($key, self::EDITABLE, true), 404);

        $row = SiteContent::query()->where('key', $key)->first();

        return response()->json([
            'key' => $key,
            'value' => $row?->value ?? new \stdClass,
            'version' => $row?->version ?? 0,

            // The form, for the keys that have one. Null for the repeating-block
            // singletons, which the console must not try to render as a flat
            // form - it would drop everything it could not show.
            'label' => self::SCHEMA[$key]['label'] ?? self::GROUPED[$key]['label'] ?? null,
            'blurb' => self::SCHEMA[$key]['blurb'] ?? self::GROUPED[$key]['blurb'] ?? null,
            'empty_means' => self::SCHEMA[$key]['empty_means'] ?? self::GROUPED[$key]['empty_means'] ?? null,
            'sections' => self::SCHEMA[$key]['sections'] ?? null,

            // The per-slug singletons describe themselves here instead. A key
            // has one shape or the other, never both, so a console screen can
            // tell which editor to use by which of these two is not null.
            'grouped' => isset(self::GROUPED[$key]) ? [
                'groups' => self::GROUPED[$key]['groups'],
                'fields' => self::GROUPED[$key]['fields'],
                'lists' => self::GROUPED[$key]['lists'],
            ] : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);
        abort_unless(in_array($key, self::EDITABLE, true), 404);

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            // 'value' may be an object OR an array OR "" — accept present-but-anything.
            'value' => ['present'],
        ]);

        $row = SiteContent::query()->where('key', $key)->first();
        $currentVersion = $row?->version ?? 0;

        // Optimistic concurrency — a stale save loses, it never clobbers.
        if ((int) $data['version'] !== $currentVersion) {
            return response()->json([
                'message' => 'This content was changed by someone else. Reload and reapply your edits.',
                'currentVersion' => $currentVersion,
            ], 409)->header('Cache-Control', 'no-store');
        }

        $row = SiteContent::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $data['value'], 'version' => $currentVersion + 1],
        );

        return response()->json([
            'key' => $key,
            'value' => $row->value,
            'version' => $row->version,
        ])->header('Cache-Control', 'no-store');
    }
}
