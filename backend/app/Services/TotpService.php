<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Mandatory-TOTP helper for admin/staff (docs §3.3). Secrets are generated
 * here, stored encrypted on the user (User::$mfa_secret cast), and verified
 * with a small clock window. QR is rendered server-side as inline SVG so the
 * enrolment page needs no third-party JS.
 */
class TotpService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function otpauthUri(string $accountEmail, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name', 'VFI').' Admin',
            $accountEmail,
            $secret,
        );
    }

    /** Inline SVG QR for the otpauth URI — no client JS required. */
    public function qrSvg(string $otpauthUri, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd));

        return $writer->writeString($otpauthUri);
    }

    /**
     * Verify a submitted 6-digit code. $window=1 tolerates ±30s of clock drift.
     * Returns the matched timeslice (to store as last-used and block replay) or
     * false. google2fa also rejects non-numeric / wrong-length input.
     */
    public function verify(string $secret, string $code, ?int $lastTimeSlice = null): int|false
    {
        $code = preg_replace('/\s+/', '', $code);

        return $this->engine->verifyKeyNewer($secret, $code, $lastTimeSlice ?? 0, 1);
    }
}
