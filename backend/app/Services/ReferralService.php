<?php

namespace App\Services;

use App\Models\Partner\AgencyReferralLink;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Str;

/**
 * Phase 7 — the agency's QR/referral link (docs §6). The slug is opaque and
 * unguessable (16 random chars) so no raw agency id ever appears in a URL. The
 * link is revocable; regenerating supersedes the old one.
 */
class ReferralService
{
    /** The current active link for the session tenant, created on first ask. */
    public function forAgency(int $agencyId, ?int $userId = null): AgencyReferralLink
    {
        return AgencyReferralLink::whereNull('revoked_at')->first()
            ?? AgencyReferralLink::create(['agency_id' => $agencyId, 'slug' => $this->newSlug(), 'created_by_user_id' => $userId]);
    }

    /** Revoke the current link and mint a fresh one. */
    public function regenerate(int $agencyId, ?int $userId = null): AgencyReferralLink
    {
        AgencyReferralLink::whereNull('revoked_at')->update(['revoked_at' => now()]);

        return AgencyReferralLink::create(['agency_id' => $agencyId, 'slug' => $this->newSlug(), 'created_by_user_id' => $userId]);
    }

    /** The public QR-registration landing URL carrying the opaque ref. */
    public function publicUrl(string $slug): string
    {
        return rtrim((string) config('app.url'), '/').'/qr-register.html?ref='.$slug;
    }

    /** A real, scannable QR (SVG — no GD needed) encoding the landing URL. */
    public function qrSvg(string $url): string
    {
        $qr = new QrCode(data: $url, size: 220, margin: 8);

        return (new SvgWriter)->write($qr)->getString();
    }

    private function newSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(16));
        } while (AgencyReferralLink::withoutGlobalScopes()->where('slug', $slug)->exists());

        return $slug;
    }
}
