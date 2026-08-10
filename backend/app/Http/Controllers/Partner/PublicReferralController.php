<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner\AgencyReferralLink;
use App\Models\Partner\PartnerAgency;
use App\Support\RlsBypass;
use Illuminate\Http\JsonResponse;

/**
 * Phase 7 — the PUBLIC resolver behind a QR-registration landing page (docs §6).
 * Validates the opaque slug (active + not revoked), rate-limited per slug/IP.
 * Reveals only the agency's display name — never any id — so the landing page
 * can say "Register with <agency>". Attribution itself happens in the register
 * flow and only counts after email verification.
 */
class PublicReferralController extends Controller
{
    /** GET /api/referral/{slug} */
    public function resolve(string $slug): JsonResponse
    {
        // Public path: no tenant is bound, so RLS must stand down for this one
        // read (the slug itself is the capability; only the name is revealed).
        $link = RlsBypass::run(fn () => AgencyReferralLink::withoutGlobalScopes()->where('slug', $slug)->first());
        if (! $link || ! $link->isActive()) {
            abort(404);
        }
        $agency = PartnerAgency::find($link->agency_id);

        return response()->json(['agency_name' => $agency?->legal_name, 'ref' => $slug])
            ->header('Cache-Control', 'no-store');
    }
}
