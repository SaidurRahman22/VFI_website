<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentAuditLog;
use App\Models\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3D — page-visibility management (docs §3). OWNER/superadmin only.
 * A filename must be in the server-side catalogue (config/pages.php); locked
 * and sign-in pages can never be toggled. Every change is audited as
 * 'toggle_page'. Menu-level only — not access control.
 */
class AdminPageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $state = SiteContent::value('pages', []);   // {file: bool}; absent = on
        $catalogue = [];
        foreach (config('pages.catalogue') as $group => $items) {
            foreach ($items as $file => $meta) {
                $catalogue[] = [
                    'group' => $group,
                    'file' => $file,
                    'label' => $meta['label'],
                    'locked' => (bool) ($meta['locked'] ?? false) || $this->isSignin($file),
                    'enabled' => ($state[$file] ?? true) !== false,
                ];
            }
        }

        return response()->json(['pages' => $catalogue])->header('Cache-Control', 'no-store');
    }

    public function toggle(Request $request, string $file): JsonResponse
    {
        $this->authorizeOwner($request);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        // allow-list: only catalogue filenames are writable (rejects arbitrary names)
        abort_unless($this->inCatalogue($file), 422, 'Unknown page.');
        // sign-in pages can never be switched off (business-wide DoS lever)
        if ($this->isSignin($file) && $data['enabled'] === false) {
            abort(422, 'Sign-in pages cannot be disabled.');
        }
        if ($this->isLocked($file) && $data['enabled'] === false) {
            abort(422, 'This page is locked on.');
        }

        $row = SiteContent::query()->where('key', 'pages')->first();
        $before = $row?->value ?? [];
        $after = array_merge($before, [$file => (bool) $data['enabled']]);

        // Update the map without the generic content-audit noise; write one
        // explicit 'toggle_page' audit row instead.
        ContentAuditLog::$muted = true;
        SiteContent::query()->updateOrCreate(
            ['key' => 'pages'],
            ['value' => $after, 'version' => ($row->version ?? 0) + 1],
        );
        ContentAuditLog::$muted = false;
        ContentAuditLog::record('toggle_page', 'pages', $file, $before, $after);

        return response()->json(['file' => $file, 'enabled' => (bool) $data['enabled']])
            ->header('Cache-Control', 'no-store');
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isSuperAdmin(), 403, 'Owner only.');
    }

    private function inCatalogue(string $file): bool
    {
        foreach (config('pages.catalogue') as $items) {
            if (array_key_exists($file, $items)) {
                return true;
            }
        }

        return false;
    }

    private function isLocked(string $file): bool
    {
        foreach (config('pages.catalogue') as $items) {
            if (isset($items[$file])) {
                return (bool) ($items[$file]['locked'] ?? false);
            }
        }

        return false;
    }

    private function isSignin(string $file): bool
    {
        return in_array($file, config('pages.signin', []), true);
    }
}
