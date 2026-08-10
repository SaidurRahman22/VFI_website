<?php

namespace App\Services;

/**
 * Phase 5 — the scan-gate (docs §Scan-gate). A document blob is written to
 * private storage but is NOT readable until this returns 'clean'. Pluggable:
 *   - builtin (default): magic-byte sniff already happened upstream; here we
 *     detect the EICAR test signature so the malware-quarantine path is real
 *     and testable without a virus engine.
 *   - clamav (deferred): streams the bytes to clamd via INSTREAM.
 *
 * Fail-CLOSED: if the configured scanner cannot render a verdict, this throws
 * rather than returning 'clean' — a sensitive file must never become readable
 * on the strength of a scanner that didn't actually run.
 */
class DocumentScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /** @return self::CLEAN|self::INFECTED */
    public function scan(string $bytes): string
    {
        return match (config('documents.scanner', 'builtin')) {
            'clamav' => $this->clamav($bytes),
            default => $this->builtin($bytes),
        };
    }

    private function builtin(string $bytes): string
    {
        // The EICAR standard anti-virus test file — the industry-standard way to
        // exercise a scan-gate without a live virus.
        if (str_contains($bytes, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) {
            return self::INFECTED;
        }

        return self::CLEAN;
    }

    private function clamav(string $bytes): string
    {
        $cfg = config('documents.clamav');
        $sock = @fsockopen($cfg['host'], (int) $cfg['port'], $errno, $errstr, (float) $cfg['timeout']);
        if (! $sock) {
            // No verdict → fail closed (caller keeps the file unreadable).
            throw new \RuntimeException("Virus scanner unreachable: {$errstr} ({$errno})");
        }

        try {
            stream_set_timeout($sock, (int) $cfg['timeout']);
            fwrite($sock, "zINSTREAM\0");
            // clamd INSTREAM framing: <4-byte length><chunk>… then a zero-length terminator.
            foreach (str_split($bytes, 8192) as $chunk) {
                fwrite($sock, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($sock, pack('N', 0));

            $response = '';
            while (! feof($sock)) {
                $response .= fread($sock, 4096);
            }
        } finally {
            fclose($sock);
        }

        if (str_contains($response, 'FOUND')) {
            return self::INFECTED;
        }
        if (str_contains($response, 'OK')) {
            return self::CLEAN;
        }

        throw new \RuntimeException('Virus scanner returned no verdict: '.trim($response));
    }
}
