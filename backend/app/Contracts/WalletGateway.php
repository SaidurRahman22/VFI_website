<?php

namespace App\Contracts;

use App\Models\Partner\Application;

/**
 * The seam between the applications pipeline and the (not yet built) Phase 9
 * money surface.
 *
 * WHY THIS EXISTS
 * The application-fee debit has to be atomic with application creation — the
 * invariant is "no debit without an application, no application without its
 * debit". That means the wallet has to be called from INSIDE the transaction in
 * PipelineService::create(). Rather than leave a comment for a future developer
 * to wire up (and risk them changing the pipeline itself), the call site already
 * exists and already runs — it just resolves to a no-op today.
 *
 * HOW PHASE 9 LANDS WITHOUT BREAKING OPERATIONS
 *   1. Implement this interface (e.g. LedgerWalletGateway) against the real
 *      wallets / wallet_transactions tables.
 *   2. Bind it in AppServiceProvider in place of NullWalletGateway.
 *   3. Turn the `wallet` feature flag on.
 * Nothing in the pipeline, the controllers or the console changes. Flipping the
 * flag back off falls straight back to the no-op, so a money incident can be
 * contained without a deploy.
 *
 * CONTRACT
 * - Implementations MUST be safe to call inside an open DB transaction.
 * - `debitApplicationFee` MUST be idempotent on $idempotencyKey: calling it
 *   twice for the same application must debit once.
 * - Throwing rolls back the whole application submission — which is the correct
 *   behaviour for insufficient funds or a frozen wallet.
 */
interface WalletGateway
{
    /** Is a money surface actually active right now? */
    public function enabled(): bool;

    /**
     * Debit the agency's wallet for an application fee, atomically with the
     * application row that is being created in the same transaction.
     *
     * @param  Application  $application  the just-created application
     * @param  int  $agencyId  the owning tenant (never taken from the request)
     * @param  string  $idempotencyKey  stable per application; replays must no-op
     * @return bool true when a debit was recorded, false when skipped (disabled/waived)
     */
    public function debitApplicationFee(Application $application, int $agencyId, string $idempotencyKey): bool;
}
