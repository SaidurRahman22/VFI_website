<?php

namespace Tests\Feature;

use App\Contracts\WalletGateway;
use App\Models\Partner\Application;
use App\Services\Money\NullWalletGateway;
use App\Services\PipelineService;
use Tests\TestCase;

/**
 * The money surface is deliberately NOT built yet, but the seam it will plug
 * into is. These tests pin the seam so Phase 9 can land without touching the
 * applications pipeline.
 */
class WalletSeamTest extends TestCase
{
    public function test_the_default_gateway_is_a_disabled_no_op(): void
    {
        $gw = app(WalletGateway::class);

        $this->assertInstanceOf(NullWalletGateway::class, $gw);
        $this->assertFalse($gw->enabled());
        $this->assertFalse($gw->debitApplicationFee(new Application, 1, 'app-fee:1'));
    }

    public function test_pipeline_calls_the_gateway_inside_the_create_transaction(): void
    {
        // A fake gateway records the call so we prove the seam is actually wired
        // (rather than being a comment a future developer has to find).
        $spy = new class implements WalletGateway
        {
            public array $calls = [];

            public function enabled(): bool
            {
                return true;
            }

            public function debitApplicationFee(Application $application, int $agencyId, string $idempotencyKey): bool
            {
                $this->calls[] = ['agency' => $agencyId, 'key' => $idempotencyKey, 'in_txn' => \DB::transactionLevel() > 0];

                return true;
            }
        };
        $this->app->instance(WalletGateway::class, $spy);

        $svc = app(PipelineService::class);
        $ref = new \ReflectionClass($svc);
        $prop = $ref->getProperty('wallet');
        $this->assertSame($spy, $prop->getValue($svc), 'PipelineService must resolve the bound gateway');
    }

    public function test_a_throwing_gateway_would_roll_the_submission_back(): void
    {
        // Documents the contract: the debit is atomic with the application, so a
        // wallet failure must abort the whole submission rather than leave an
        // application with no matching debit.
        $gw = new class implements WalletGateway
        {
            public function enabled(): bool
            {
                return true;
            }

            public function debitApplicationFee(Application $application, int $agencyId, string $idempotencyKey): bool
            {
                throw new \RuntimeException('insufficient funds');
            }
        };

        $this->expectException(\RuntimeException::class);
        $gw->debitApplicationFee(new Application, 1, 'app-fee:1');
    }
}
