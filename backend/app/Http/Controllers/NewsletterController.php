<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public newsletter subscribe. Anonymous, stateless and rate-limited, exactly
 * like the contact intake — the footer box on ~30 pages posts here.
 *
 * Enumeration-safe: the response is identical whether the address is new, is
 * already subscribed, or was previously unsubscribed, so the endpoint cannot be
 * used to test whether somebody is on VFI's list.
 */
class NewsletterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'interest' => ['nullable', 'string', Rule::in(['student', 'institute', 'partner', 'franchisee'])],
            'source_page' => ['nullable', 'string', 'max:190'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // updateOrCreate keeps the unique index honest when someone subscribes
        // twice, and re-subscribing clears a previous opt-out deliberately —
        // they asked for it again.
        NewsletterSubscriber::updateOrCreate(
            ['email' => $email],
            [
                'interest' => $data['interest'] ?? null,
                'source_page' => mb_substr((string) ($data['source_page'] ?? ''), 0, 190) ?: null,
                'ip' => $request->ip(),
                'unsubscribed_at' => null,
            ],
        );

        return response()->json(['ok' => true], 202)->header('Cache-Control', 'no-store');
    }
}
