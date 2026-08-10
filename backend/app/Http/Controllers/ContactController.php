<?php

namespace App\Http\Controllers;

use App\Models\ContactEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Public contact-form intake (Phase 2 §7). Anonymous, stateless, rate-limited.
 * No auth/CSRF (nothing to hijack); protection is validation + rate-limit +
 * optional Turnstile. Never accepts a partner/student id. Stored plain-text.
 */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fname' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'dest' => ['nullable', 'string', Rule::in(config('contact.destinations'))],
            'msg' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! $this->turnstileOk($request)) {
            return response()->json(['message' => 'Verification failed. Please try again.'], 422);
        }

        // Strip control chars (keep newlines/tabs in msg); trim already applied.
        $clean = fn (?string $v) => $v === null ? null : preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);

        ContactEnquiry::create([
            'fname' => $clean($data['fname']),
            'phone' => $clean($data['phone']),
            'email' => mb_strtolower($data['email']),
            'dest' => $clean($data['dest'] ?? null),
            'msg' => $clean($data['msg'] ?? null),
            'source_page' => $clean(substr((string) $request->input('source_page', ''), 0, 190)) ?: null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['ok' => true], 202);
    }

    /** Verify Turnstile only when enabled + configured; otherwise pass. */
    private function turnstileOk(Request $request): bool
    {
        if (! config('contact.turnstile.enabled') || ! config('contact.turnstile.secret')) {
            return true;
        }
        $token = (string) $request->input('cf-turnstile-response', '');
        if ($token === '') {
            return false;
        }
        $resp = Http::asForm()->timeout(8)->post(config('contact.turnstile.verify_url'), [
            'secret' => config('contact.turnstile.secret'),
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);

        return $resp->ok() && ($resp->json('success') === true);
    }
}
