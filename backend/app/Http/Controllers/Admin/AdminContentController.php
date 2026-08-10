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

    public function show(string $key): JsonResponse
    {
        abort_unless(in_array($key, self::EDITABLE, true), 404);

        $row = SiteContent::query()->where('key', $key)->first();

        return response()->json([
            'key' => $key,
            'value' => $row?->value ?? new \stdClass,
            'version' => $row?->version ?? 0,
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $key): JsonResponse
    {
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
