<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner\PartnerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — tenant-scoped notifications (docs §7). The notifications page and
 * the bell popover both read this one source.
 */
class PartnerNotificationController extends Controller
{
    /** GET /api/partner/notifications — paged + unread count. */
    public function index(Request $request): JsonResponse
    {
        $page = PartnerNotification::orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PartnerNotification $n) => $this->present($n)),
            'unread_count' => PartnerNotification::whereNull('read_at')->count(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'no-store');
    }

    /** POST /api/partner/notifications/read — mark one {id} or all as read. */
    public function read(Request $request): JsonResponse
    {
        $id = $request->input('id');
        if ($id) {
            PartnerNotification::whereKey($id)->update(['read_at' => now()]);   // tenant-scoped
        } else {
            PartnerNotification::whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'unread_count' => PartnerNotification::whereNull('read_at')->count(),
        ])->header('Cache-Control', 'no-store');
    }

    private function present(PartnerNotification $n): array
    {
        return [
            'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body,
            'link' => $n->link, 'read' => $n->isRead(),
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }
}
