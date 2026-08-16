<?php

namespace App\Services\Money;

use App\Contracts\WalletGateway;
use App\Models\Partner\Application;

/**
 * The default WalletGateway while there is no money surface (Phase 9 excluded
 * from the current build). Every call is a no-op that records nothing and
 * charges nobody, so the application pipeline behaves exactly as it does today.
 *
 * This is deliberately the bound implementation rather than "no call at all":
 * the call site is exercised on every submission and covered by tests, so when
 * the real ledger implementation replaces it there is no new, untested code path
 * being introduced into the middle of a financial transaction.
 */
class NullWalletGateway implements WalletGateway
{
    public function enabled(): bool
    {
        return false;
    }

    public function debitApplicationFee(Application $application, int $agencyId, string $idempotencyKey): bool
    {
        return false;   // no wallet, no debit — application submission proceeds
    }
}
