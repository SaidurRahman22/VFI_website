<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuthEvent;
use App\Models\Concerns\BelongsToAgencyScope;
use App\Models\SyntheticPartnerRow;
use App\Models\User;
use App\Models\UserRole;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenancyAndIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_passwords_use_argon2id(): void
    {
        $hash = Hash::make('correct horse battery staple');
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(Hash::check('correct horse battery staple', $hash));
    }

    public function test_belongs_to_agency_scope_isolates_tenants(): void
    {
        $this->tenant()->setAgencyId(1);
        SyntheticPartnerRow::create(['label' => 'agency-1 row']);

        $this->tenant()->setAgencyId(2);
        SyntheticPartnerRow::create(['label' => 'agency-2 row']);

        // back to agency 1 — must see only its own row
        $this->tenant()->setAgencyId(1);
        $this->assertSame(1, SyntheticPartnerRow::count());
        $this->assertSame('agency-1 row', SyntheticPartnerRow::first()->label);

        // fail-closed: no tenant in context → zero rows, never a leak
        $this->tenant()->clear();
        $this->assertSame(0, SyntheticPartnerRow::count());
    }

    public function test_second_net_when_app_scope_is_stripped(): void
    {
        $this->tenant()->setAgencyId(1);
        SyntheticPartnerRow::create(['label' => 'a']);
        $this->tenant()->setAgencyId(2);
        SyntheticPartnerRow::create(['label' => 'b']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            /*
             * Agency 2 is still bound, and that is the assertion: stripping the
             * app scope must NOT reveal agency 1's row, because RLS FORCE keeps
             * the read inside whatever tenant is bound.
             *
             * The previous version took the count BEFORE deciding what it
             * expected and then ran `SET LOCAL app.agency_id = '1'` four lines
             * AFTER the query that line was meant to set up - so it measured
             * agency 2's single row and asserted 0, and reported "RLS should deny
             * cross-tenant read" when RLS had done exactly its job. (SET LOCAL
             * outside a transaction is a no-op in any case.) Of the 24 Postgres
             * failures this was the only one that looked like a real tenancy
             * hole; it was the test.
             */
            $this->assertSame(
                1,
                SyntheticPartnerRow::withoutGlobalScope(BelongsToAgencyScope::class)->count(),
                'RLS must keep a scope-stripped read inside the bound tenant'
            );
            $this->assertSame('b', SyntheticPartnerRow::withoutGlobalScope(BelongsToAgencyScope::class)->value('label'));

            // Nothing bound: RLS is the only net left, and it must fail closed.
            $this->tenant()->clear();
            $this->assertSame(
                0,
                SyntheticPartnerRow::withoutGlobalScope(BelongsToAgencyScope::class)->count(),
                'RLS must fail closed with no tenant bound'
            );

            return;
        }

        // No RLS on sqlite/mysql: document that stripping the scope is a
        // deliberate, audited escape hatch — the app scope is the net here.
        $this->assertSame(2, SyntheticPartnerRow::withoutGlobalScope(BelongsToAgencyScope::class)->count());
        $this->markTestIncomplete('RLS second net is Postgres-only; the Postgres CI leg exercises it.');
    }

    public function test_tenant_bound_role_requires_agency_id(): void
    {
        $u = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        UserRole::create([
            'user_id' => $u->id,
            'role' => Role::PartnerOwner,
            'agency_id' => null,
            'granted_at' => now(),
        ]);
    }

    public function test_non_tenant_role_rejects_agency_id(): void
    {
        $u = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        UserRole::create([
            'user_id' => $u->id,
            'role' => Role::SuperAdmin,
            'agency_id' => 5,
            'granted_at' => now(),
        ]);
    }

    public function test_auth_events_are_append_only(): void
    {
        $e = AuthEvent::record('login_failed', ['email' => 'x@example.com']);

        try {
            $e->update(['event' => 'tampered']);
            $this->fail('auth_events update should be blocked');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }

        try {
            $e->delete();
            $this->fail('auth_events delete should be blocked');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }
    }

    public function test_email_is_stored_lowercased(): void
    {
        $u = User::factory()->create(['email' => 'MixedCase@Example.COM']);
        $this->assertSame('mixedcase@example.com', $u->fresh()->email);
    }
}
