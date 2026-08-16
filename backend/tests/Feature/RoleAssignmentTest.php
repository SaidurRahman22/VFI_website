<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ContentAuditLog;
use App\Models\Partner\PartnerAgency;
use App\Models\User;
use App\Models\UserRole;
use App\Services\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9A slice 4 — role grant/revoke. The columns existed since Phase 1 with
 * no surface; these pin the guards that make a surface safe to add.
 */
class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $u = User::factory()->create();
        UserRole::create(['user_id' => $u->id, 'role' => Role::SuperAdmin->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    private function svc(): RoleAssignmentService
    {
        return app(RoleAssignmentService::class);
    }

    public function test_a_superadmin_can_grant_a_global_role(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();

        $this->svc()->grant($target, Role::ContentEditor, null, $actor);

        $this->assertTrue($target->fresh()->hasRole(Role::ContentEditor));
    }

    public function test_a_non_superadmin_cannot_change_roles(): void
    {
        $actor = User::factory()->create();   // no roles at all

        $this->expectException(\RuntimeException::class);
        $this->svc()->grant(User::factory()->create(), Role::ContentEditor, null, $actor);
    }

    public function test_a_tenant_bound_role_requires_an_agency(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->grant(User::factory()->create(), Role::PartnerOwner, null, $this->superadmin());
    }

    public function test_a_global_role_rejects_an_agency(): void
    {
        $agency = PartnerAgency::create(['legal_name' => 'Acme', 'country' => 'Bangladesh']);

        $this->expectException(\RuntimeException::class);
        $this->svc()->grant(User::factory()->create(), Role::ContentEditor, $agency->id, $this->superadmin());
    }

    public function test_granting_a_role_twice_is_refused(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        $this->svc()->grant($target, Role::ContentEditor, null, $actor);

        $this->expectException(\RuntimeException::class);
        $this->svc()->grant($target->fresh(), Role::ContentEditor, null, $actor);
    }

    public function test_you_cannot_remove_your_own_superadmin(): void
    {
        $actor = $this->superadmin();
        $this->superadmin();   // a second one exists, so it is not a last-owner block
        $own = UserRole::where('user_id', $actor->id)->where('role', Role::SuperAdmin->value)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->svc()->revoke($own, $actor);
    }

    public function test_the_last_superadmin_can_never_be_removed(): void
    {
        $actor = $this->superadmin();
        $other = $this->superadmin();
        $otherRole = UserRole::where('user_id', $other->id)->where('role', Role::SuperAdmin->value)->firstOrFail();

        // removing the second one is fine — one owner remains
        $this->svc()->revoke($otherRole, $actor);
        $this->assertNotNull($otherRole->fresh()->revoked_at);

        // now only $actor holds it; a third party cannot strip the last one either
        $rescuer = $this->superadmin();
        $actorRole = UserRole::where('user_id', $actor->id)->where('role', Role::SuperAdmin->value)->firstOrFail();
        $this->svc()->revoke($actorRole, $rescuer);       // ok: $rescuer remains

        $lastRole = UserRole::where('user_id', $rescuer->id)->where('role', Role::SuperAdmin->value)->firstOrFail();
        $bystander = $this->superadmin();                  // grant another so the actor check passes
        UserRole::where('user_id', $bystander->id)->update(['revoked_at' => now()]);   // …then take it away

        $this->expectException(\RuntimeException::class);
        $this->svc()->revoke($lastRole, $rescuer);
    }

    public function test_revocation_is_soft_so_the_history_survives(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        $this->svc()->grant($target, Role::ContentEditor, null, $actor);
        $row = UserRole::where('user_id', $target->id)->firstOrFail();

        $this->svc()->revoke($row, $actor);

        $this->assertDatabaseHas('user_roles', ['id' => $row->id]);   // row kept
        $this->assertNotNull($row->fresh()->revoked_at);
        $this->assertFalse($target->fresh()->hasRole(Role::ContentEditor));
    }

    public function test_grant_and_revoke_are_audited(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();

        $this->svc()->grant($target, Role::ContentEditor, null, $actor);
        $grant = ContentAuditLog::where('entity', 'user')->where('action', 'role_grant')->latest('id')->first();
        $this->assertNotNull($grant);
        $this->assertSame($actor->id, $grant->after['actor_user_id']);

        $this->svc()->revoke(UserRole::where('user_id', $target->id)->firstOrFail(), $actor);
        $revoke = ContentAuditLog::where('entity', 'user')->where('action', 'role_revoke')->latest('id')->first();
        $this->assertNotNull($revoke);
        $this->assertTrue($revoke->before['held']);
        $this->assertFalse($revoke->after['held']);
    }
}
