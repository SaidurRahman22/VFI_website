<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteContent;
use App\Support\ImageIdGuard;
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
        'universityPage',
    ];

    /*
     * How much one repeating-block save may hold.
     *
     * The per-type lengths are the ones the collections editor already applies
     * (AdminContentCollectionController::validated), so a paragraph that fits
     * in a blog body fits in a country FAQ and an editor never meets two
     * different limits for the same kind of box.
     *
     * There is a limit at all because this row is not private. Every value
     * written here is served verbatim to every anonymous visitor at
     * /api/content/bundle, which md5s the whole bundle per request to build its
     * ETag - so one oversized save is paid for on every page load of the public
     * site, by everyone, until somebody notices.
     */
    private const MAX_ROWS = 100;

    private const MAX_TEXT = 500;

    private const MAX_TEXTAREA = 40000;

    /** countries nests value → slug → list → row, so four, plus one spare. */
    private const MAX_DEPTH = 5;

    private const MAX_BYTES = 262144;

    /**
     * The FLAT singletons, as a form the console can render.
     *
     * Declared server-side for the same reason the collections are: the console
     * builds its inputs from this, so the fields are written down once. The
     * legacy admin page kept its own copy of every field list in js/admin.js,
     * and that second copy is how its photos form came to offer a field with no
     * column behind it.
     *
     * countries, regions, servicesPage and universityPage are deliberately
     * absent. They hold repeating blocks ({uk: {heroTitle: …, bands: [...]}}),
     * which a key-and-string form cannot express. They are declared in GROUPED
     * below instead, and index() offers only the keys named here — a flat form
     * built over one of them would show the handful of strings it understood
     * and then save that over the blocks it could not.
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
     * The singletons made of repeating blocks — once per country or region for
     * the first two, one of a kind for the rest. SCHEMA above cannot describe
     * these - it models a flat form - so they are declared here instead and the
     * console renders them with a different screen. Same principle either way:
     * the fields are written down once, on the server.
     *
     * Every field below is read off what the frontend actually consumes -
     * js/render.js for the country, region and services pages, and
     * js/universities.js through PublicUniversityController::pageDefaults() for
     * universityPage. Where the renderer splits a value on newlines (region
     * `facts`, services `offers`) the type is `lines`, and the screen says so.
     *
     * All eighteen country blocks are live. Fourteen of them were dead when this
     * const was first written - the markup advertised a `data-crender` hook and
     * js/render.js listened to only four - so they were deliberately left out
     * rather than shipped as fields that save data no visitor can see. The
     * renderer now reads all eighteen and the two pages that were missing their
     * hooks (study-in-usa, study-in-ireland) have them, so the fields below all
     * reach something.
     *
     * Nothing is declared `absent_on` any more. That key exists because the USA
     * page used to be missing its Top Admits section and its recruiter strip
     * outright - not a missing attribute, the markup simply was not there - and
     * a list that saves nowhere has to say so on screen rather than quietly
     * doing nothing. Both sections are now in study-in-usa.html, so all six
     * pages carry the same eighteen hooks; CountryPageMarkupTest is what keeps
     * that true, because a hook that goes missing again is silent by nature.
     *
     * Two lists carry an `image` field. It is a type, not a text box, and that
     * distinction is the whole point: update() below allow-lists the value of
     * every field declared `image`, so the only ids that can be stored are the
     * ones the upload pipeline produced and the photos bundled with the repo.
     * The region bands' img1/img2/img3 are still `text`, which is how an id
     * that never passed that pipeline - a remote URL, an SVG - could be typed
     * straight into a background-image on a public page. They are left alone
     * here only because changing them is a separate migration, not because the
     * shape is right.
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
                        // The crest circle is the one part of this card a photo
                        // can occupy without redrawing it - style.css hides the
                        // cap glyph the moment .has-photo lands on the tile,
                        // and until now nothing ever put a picture there.
                        //
                        // `image`, not the `text` the region bands use for
                        // img1/img2/img3: a typed box is how an id that never
                        // passed the upload pipeline - a remote URL, an SVG -
                        // reaches a background-image on a public page. The type
                        // is what gives the screen a file input and gives
                        // update() below something to allow-list.
                        ['key' => 'img', 'label' => 'Logo', 'type' => 'image',
                            'hint' => 'Square, about 200 × 200 px. Left empty, the card keeps the coloured circle and cap icon it has today.'],
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
                [
                    'key' => 'overview', 'label' => 'Why study here', 'singular' => 'reason',
                    'item' => [
                        ['key' => 'title', 'label' => 'Heading', 'type' => 'text'],
                        ['key' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                        ['key' => 'tone', 'label' => 'Colour', 'type' => 'text', 'half' => true,
                            'hint' => 'Optional. One of: blue, coral, gold, green, red, violet. Anything else keeps the colour the built-in card used.'],
                        ['key' => 'icon', 'label' => 'Icon', 'type' => 'text', 'half' => true,
                            'hint' => 'Optional. An icon name such as cap, briefcase, globe, shield, users, star, doc, money. An unknown name keeps the built-in icon.'],
                    ],
                ],
                [
                    'key' => 'coursesMasters', 'label' => 'Top courses - Masters', 'singular' => 'course',
                    'lines' => true,
                ],
                [
                    'key' => 'coursesBachelors', 'label' => 'Top courses - Bachelors', 'singular' => 'course',
                    'lines' => true,
                ],
                [
                    'key' => 'costStudy', 'label' => 'Cost of study', 'singular' => 'figure',
                    'item' => [
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'text', 'half' => true,
                            'hint' => 'The large bold figure on the card.'],
                        ['key' => 'label', 'label' => 'What it covers', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'costLiving', 'label' => 'Cost of living', 'singular' => 'figure',
                    'item' => [
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'text', 'half' => true],
                        ['key' => 'label', 'label' => 'What it covers', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'intakes', 'label' => 'Intakes', 'singular' => 'intake',
                    'item' => [
                        ['key' => 'name', 'label' => 'Intake name', 'type' => 'text', 'half' => true,
                            'hint' => 'The banner text, e.g. "Fall intake".'],
                        ['key' => 'months', 'label' => 'Months', 'type' => 'text', 'half' => true],
                        ['key' => 'desc', 'label' => 'Description', 'type' => 'textarea'],
                        ['key' => 'apply', 'label' => 'Apply-by line', 'type' => 'text',
                            'hint' => 'Written out in full, including any prefix - e.g. "Apply by: Dec - Mar".'],
                    ],
                ],
                [
                    'key' => 'eligBachelors', 'label' => 'Eligibility - Bachelors', 'singular' => 'point',
                    'lines' => true,
                ],
                [
                    'key' => 'eligMasters', 'label' => 'Eligibility - Masters', 'singular' => 'point',
                    'lines' => true,
                ],
                [
                    'key' => 'exams', 'label' => 'Entrance exams', 'singular' => 'exam',
                    'item' => [
                        ['key' => 'name', 'label' => 'Exam', 'type' => 'text', 'half' => true],
                        ['key' => 'score', 'label' => 'Score needed', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'requirements', 'label' => 'Application requirements', 'singular' => 'requirement',
                    'item' => [
                        ['key' => 'title', 'label' => 'Heading', 'type' => 'text'],
                        ['key' => 'text', 'label' => 'Text', 'type' => 'textarea'],
                        ['key' => 'icon', 'label' => 'Icon', 'type' => 'text', 'half' => true,
                            'hint' => 'Optional. An icon name such as cap, briefcase, globe, shield, users, star, doc, money. An unknown name keeps the built-in icon.'],
                    ],
                ],
                [
                    'key' => 'visaCosts', 'label' => 'Visa fees and funds', 'singular' => 'figure',
                    'item' => [
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'text', 'half' => true],
                        ['key' => 'label', 'label' => 'What it is for', 'type' => 'text', 'half' => true],
                    ],
                ],
                [
                    'key' => 'visaDocs', 'label' => 'Visa documents', 'singular' => 'document',
                    'lines' => true,
                ],
                [
                    'key' => 'recruiters', 'label' => 'Top recruiters', 'singular' => 'employer',
                    'lines' => true,
                ],
                [
                    'key' => 'admits', 'label' => 'Top admits', 'singular' => 'student',
                    'item' => [
                        ['key' => 'name', 'label' => 'Student name', 'type' => 'text', 'half' => true],
                        ['key' => 'uni', 'label' => 'University', 'type' => 'text', 'half' => true],
                        ['key' => 'prog', 'label' => 'Programme', 'type' => 'text', 'half' => true],
                        ['key' => 'initials', 'label' => 'Initials', 'type' => 'text', 'half' => true,
                            'hint' => 'Optional - taken from the name when left empty.'],
                        // The avatar circle. The initials stay as the fallback
                        // rather than being replaced by it: a row whose photo
                        // is missing, or whose file has been swept, keeps the
                        // card it has today instead of showing an empty disc.
                        ['key' => 'img', 'label' => 'Photo', 'type' => 'image',
                            'hint' => 'Square, about 180 × 180 px. Left empty, the circle shows the initials.'],
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
                        // 'slot', not 'image': these hold the NAME of a media slot
                        // (asia_japan_1), not a path to a file, which is why the labels
                        // say slot. Typed as `image` they refused every value already
                        // stored. Typed as nothing they accepted a full https:// address,
                        // which VFI.getImage resolves to itself - a third-party request
                        // on a public page. `slot` accepts an identifier and no more.
                        ['key' => 'img1', 'label' => 'Image 1 slot', 'type' => 'slot', 'half' => true],
                        ['key' => 'img2', 'label' => 'Image 2 slot', 'type' => 'slot', 'half' => true],
                        ['key' => 'img3', 'label' => 'Image 3 slot', 'type' => 'slot', 'half' => true],
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
                        // A slot name, same as the region bands above.
                        ['key' => 'img', 'label' => 'Image slot', 'type' => 'slot', 'half' => true],
                        ['key' => 'offers', 'label' => 'What is included', 'type' => 'lines',
                            'hint' => 'One item per line. Each line becomes a starred bullet.'],
                    ],
                ],
            ],
        ],

        /*
         * The copy every university DETAIL page falls back to when a university
         * has none of its own: the intake season cards, the cost intro and
         * footnote, the standard FAQ set, and the lead form's options.
         *
         * Here and not in SCHEMA because three of the seven things it holds are
         * rows, not strings. Its stored shape is already
         * {cost_intro: '…', seasons: [...], faqs: [...], interest_options: [...]},
         * which is exactly what a GROUPED key with no slug level produces - so
         * the console writes the same JSON the Filament screen has been writing
         * and pageDefaults() goes on reading it untouched. Nothing migrates.
         *
         * Every field is one that PublicUniversityController::pageDefaults()
         * serves AND js/universities.js reads; both ends were checked. The
         * country pages are the reason that is worth stating: fourteen of their
         * hooks have no renderer behind them, so an editor can fill those in
         * for an hour and no visitor ever sees a word of it.
         */
        'universityPage' => [
            'label' => 'University page defaults',
            'blurb' => 'The wording a university page falls back to when that university has none of its own.',
            'empty_means' => 'keeps the wording already built into the university page',
            // No slug level: one set of defaults serves every university.
            'groups' => [],
            'fields' => [
                ['key' => 'intake_footnote', 'label' => 'Footnote under the intake cards', 'type' => 'textarea'],
                ['key' => 'cost_intro', 'label' => 'Cost to study: intro paragraph', 'type' => 'textarea',
                    'hint' => 'Write {university} where the university’s name should appear.'],
                ['key' => 'cost_footnote', 'label' => 'Cost to study: footnote under the table', 'type' => 'textarea'],
                ['key' => 'scholarship_note', 'label' => 'Note shown when a university lists no scholarships', 'type' => 'textarea',
                    'hint' => 'Write {university} where the university’s name should appear.'],
            ],
            'lists' => [
                [
                    'key' => 'seasons', 'label' => 'Intake season cards', 'singular' => 'season',
                    'item' => [
                        // The page looks a card up by this word and by nothing
                        // else, so a row misspelling it is stored and never read.
                        ['key' => 'key', 'label' => 'Season', 'type' => 'text', 'half' => true,
                            'hint' => 'One of fall, spring, summer or winter. Any other word is never looked up.'],
                        ['key' => 'month', 'label' => 'Month on the card', 'type' => 'text', 'half' => true],
                        ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
                        /*
                         * `asset`, not `text`, and not `image`.
                         *
                         * It was `text`, so the allow-list every other picture
                         * field goes through never ran on it — and this one is
                         * painted on university.html, a public page. A content
                         * editor could put `https://evil.example/beacon.png`
                         * here and every anonymous visitor's browser would fetch
                         * it. The hint used to invite exactly that ("or a full
                         * https:// address"); a public page is not ours to point
                         * at someone else's server, so the invitation is gone.
                         *
                         * Not `image` either: this holds the DISK KEY Filament's
                         * upload writes, which assetUrl() prefixes with
                         * /storage/ on the way out. An id that is already a URL
                         * would come back as /storage/storage/… and render
                         * nothing.
                         */
                        ['key' => 'image', 'label' => 'Card image', 'type' => 'asset',
                            'hint' => 'A picture stored on this site, such as media/universities/intakes/fall.jpg. '
                                .'Uploading one on the University defaults screen fills this in for you. '
                                .'Left empty, the card keeps its built-in photo.'],
                    ],
                ],
                [
                    'key' => 'faqs', 'label' => 'Default FAQs', 'singular' => 'question',
                    'item' => [
                        ['key' => 'q', 'label' => 'Question', 'type' => 'text'],
                        ['key' => 'a', 'label' => 'Answer', 'type' => 'textarea'],
                    ],
                ],
                [
                    'key' => 'interest_options', 'label' => 'Lead form: “I’m interested in” options', 'singular' => 'option',
                    'item' => [
                        ['key' => 'label', 'label' => 'Option', 'type' => 'text'],
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

        // `present` is as much as one rule can say about a value whose shape
        // changes with the key, so the repeating-block keys are checked against
        // the schema that declares them instead, before anything is written.
        // What lands in this row is not private: it is served verbatim to every
        // anonymous visitor at /api/content/bundle.
        if (isset(self::GROUPED[$key])) {
            $data['value'] = $this->checkedGroupedValue($key, $data['value']);
        }

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

    /**
     * A grouped value this server is willing to publish, or a 422 saying which
     * field is in the way.
     *
     * Two rules, and the difference between them is the important part.
     *
     * DECLARED fields are held to what GROUPED says they are: a row count, a
     * length per type, and for an `image` the allow-list of ids that can
     * actually load. That last one is why declaring a type was worth doing -
     * the stored-id box on the screen is free text, and without a check here an
     * editor write of https://evil.example/beacon.png becomes a background-image
     * on six public pages, fetched by every anonymous visitor from a third-party
     * host that then holds their IP and the page they were reading.
     *
     * UNDECLARED keys are bounded but KEPT. The console merges its form over the
     * value it loaded and posts the whole object back, the services blocks still
     * carry `desc`, `ctaLabel` and `ctaHref` that this const has never described,
     * and content:import copies legacy JSON in verbatim. Dropping what the schema
     * does not mention would delete live page copy on the first save an editor
     * made - which is why two tests already insist an undescribed key survives.
     *
     * Refused, not silently corrected. A save that stored the row with the bad
     * field blanked would look like it worked and cost the editor the picture
     * they were pointing at, on a form with forty fields and no clue which one
     * was at fault.
     */
    private function checkedGroupedValue(string $key, mixed $value): mixed
    {
        if (! is_array($value)) {
            // "" is how a cleared singleton round-trips (Phase 0 landmine: the
            // empty-string middleware is off for these routes). Any other
            // scalar is a shape no screen sends and no renderer reads.
            abort_unless($value === '', 422, "This page's content has to be saved as an object.");

            return $value;
        }

        // Size first: a value that passes every other check can still be large
        // enough to matter, because the public bundle is rebuilt and re-hashed
        // from these rows on every request that misses the 60-second cache.
        $encoded = json_encode($value);
        abort_if(
            $encoded === false || strlen($encoded) > self::MAX_BYTES,
            422,
            'This is too large to save. Every visitor downloads this content with the page, so it is capped at '
                .(int) (self::MAX_BYTES / 1024).' KB.',
        );

        $this->checkShape($value, 1, '');

        $spec = self::GROUPED[$key];
        if ($spec['groups'] === []) {
            return $this->checkedBody($value, $spec, '');
        }

        $labels = array_column($spec['groups'], 'label', 'slug');
        foreach ($value as $slug => $body) {
            if (is_array($body)) {
                $value[$slug] = $this->checkedBody($body, $spec, ($labels[$slug] ?? $slug).' — ');
            }
        }

        return $value;
    }

    /**
     * Depth, leaf types and string length, for the keys the schema has no
     * opinion about.
     *
     * Without this the only bound on an undeclared key is the byte cap, and a
     * single deeply nested value is cheap to post and expensive to json_encode
     * on every public page load afterwards.
     */
    private function checkShape(mixed $node, int $depth, string $where): void
    {
        if (is_array($node)) {
            abort_if($depth > self::MAX_DEPTH, 422, 'This content is nested deeper than any page reads.');
            foreach ($node as $k => $child) {
                $this->checkShape($child, $depth + 1, $where === '' ? (string) $k : $where.' → '.$k);
            }

            return;
        }

        if ($node === null || is_bool($node) || is_int($node) || is_float($node)) {
            return;
        }

        abort_unless(is_string($node), 422, "“{$where}” is not something this content can hold.");
        abort_if(
            mb_strlen($node) > self::MAX_TEXTAREA,
            422,
            "“{$where}” is longer than ".self::MAX_TEXTAREA.' characters.',
        );
    }

    /**
     * One slug's bucket, or the whole value for a page that is one of a kind.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $spec  the GROUPED entry that describes it
     * @param  string  $where  what to call this bucket in a message to an editor
     * @return array<string, mixed>
     */
    private function checkedBody(array $body, array $spec, string $where): array
    {
        foreach ($spec['fields'] as $field) {
            if (array_key_exists($field['key'], $body)) {
                $body[$field['key']] = $this->checkedValue($body[$field['key']], $field, $where.$field['label']);
            }
        }

        foreach ($spec['lists'] as $list) {
            if (! array_key_exists($list['key'], $body)) {
                continue;
            }
            $rows = $body[$list['key']];

            // A `lines` list is one newline-separated string, not rows, because
            // that is the shape js/render.js clines() reads. checkShape has
            // already bounded it either way.
            if (! empty($list['lines']) || ! is_array($rows)) {
                continue;
            }

            abort_if(
                count($rows) > self::MAX_ROWS,
                422,
                "“{$where}{$list['label']}” has more than ".self::MAX_ROWS.' rows. No page renders a list that long.',
            );

            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($list['item'] as $field) {
                    if (array_key_exists($field['key'], $row)) {
                        $row[$field['key']] = $this->checkedValue(
                            $row[$field['key']],
                            $field,
                            $where.$list['singular'].' '.((int) $i + 1).' — '.$field['label'],
                        );
                    }
                }
                $rows[$i] = $row;
            }

            $body[$list['key']] = $rows;
        }

        return $body;
    }

    /** One declared field's value, held to what its declared type means. */
    private function checkedValue(mixed $value, array $field, string $where): mixed
    {
        if ($field['type'] === 'image') {
            // Trimmed before it is judged: this value can be pasted by hand, and
            // an id that fails only on a trailing space is a refusal an editor
            // cannot see the reason for.
            $id = is_string($value) ? trim($value) : '';

            abort_if($id !== '' && ! ImageIdGuard::isUsable($id), 422, "“{$where}”: ".ImageIdGuard::MESSAGE);

            return $id;
        }

        if ($field['type'] === 'asset') {
            // A file on the public disk, stored as the key rather than the URL.
            // See the `seasons[].image` declaration above for why that is its
            // own type and not a second meaning for `image`.
            $key = is_string($value) ? trim($value) : '';

            abort_if($key !== '' && ! ImageIdGuard::isUsableAssetKey($key), 422, "“{$where}”: ".ImageIdGuard::ASSET_MESSAGE);

            return $key;
        }

        if ($field['type'] === 'slot') {
            /*
             * A media slot NAME, not a path. Guarded because the sink is
             * generous: VFI.getImage() resolves anything carrying a scheme, a
             * slash or an image extension to itself, so an unguarded slot box
             * accepts `https://evil.example/beacon.png` and the public page
             * fetches it - a third-party beacon on a visitor's browser.
             *
             * An identifier cannot express any of that. No dot, so no
             * extension; no slash or colon, so no path and no scheme; no quote,
             * so nothing to break out of an attribute with.
             */
            $slot = is_string($value) ? trim($value) : '';

            abort_if(
                $slot !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slot) !== 1,
                422,
                "“{$where}”: that is not an image slot name. Use letters, numbers, underscores "
                    .'or hyphens - for example asia_japan_1.',
            );

            return $slot;
        }

        if (! is_string($value)) {
            return $value;   // already bounded by checkShape
        }

        $max = in_array($field['type'], ['textarea', 'lines'], true) ? self::MAX_TEXTAREA : self::MAX_TEXT;
        abort_if(mb_strlen($value) > $max, 422, "“{$where}” is longer than {$max} characters.");

        return $value;
    }
}
