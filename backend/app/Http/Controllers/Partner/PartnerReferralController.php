<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner\AgencyReferralLink;
use App\Services\ReferralService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7 — the agency's referral/QR link (docs §6). Tenant-scoped: the link
 * belongs to the session agency. Returns a real, scannable QR of the public
 * landing URL carrying the opaque slug.
 */
class PartnerReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals)
    {
    }

    /** GET /api/partner/referral-link */
    public function show(Request $request): JsonResponse
    {
        $agencyId = app(TenantContext::class)->agencyId();

        return $this->payload($this->referrals->forAgency($agencyId, $request->user()->id));
    }

    /** POST /api/partner/referral-link/regenerate — revoke + mint a fresh slug. */
    public function regenerate(Request $request): JsonResponse
    {
        $agencyId = app(TenantContext::class)->agencyId();

        return $this->payload($this->referrals->regenerate($agencyId, $request->user()->id));
    }

    private function payload(AgencyReferralLink $link): JsonResponse
    {
        $url = $this->referrals->publicUrl($link->slug);

        return response()->json([
            'slug' => $link->slug,
            'url' => $url,
            'qr_svg' => $this->referrals->qrSvg($url),
            'uses_count' => $link->uses_count,
            'active' => $link->isActive(),
        ])->header('Cache-Control', 'no-store');
    }
}
