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
            'label' => self::SCHEMA[$key]['label'] ?? null,
            'blurb' => self::SCHEMA[$key]['blurb'] ?? null,
            'empty_means' => self::SCHEMA[$key]['empty_means'] ?? null,
            'sections' => self::SCHEMA[$key]['sections'] ?? null,
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
