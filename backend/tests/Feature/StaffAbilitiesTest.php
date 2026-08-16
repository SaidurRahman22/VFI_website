<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuthEvent;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminAccounts;
use App\Support\StaffAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles previously decided nothing: any admin-panel role could reach every
 * screen, so a content editor could process applications and open students.
 * These pin who can do what, and the staff-account creation that replaces
 * needing shell access.
 */
class StaffAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(?Role $role): User
    {
        $u = User::factory()->create();
        if ($role) {
            UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);
        }

        return $u->fresh();
    }

    public function test_a_content_editor_cannot_touch_students_or_applications(): void
    {
        $editor = $this->withRole(Role::ContentEditor);

        $this->assertFalse(StaffAbilities::allows($editor, 'applications.process'));
        $this->assertFalse(StaffAbilities::allows($editor, 'documents.review'));
        $this->assertFalse(StaffAbilities::allows($editor, 'students.crossTenant'));
        $this->assertFalse(StaffAbilities::allows($editor, 'agencies.manage'));
        // but does hold its own ground
        $this->assertTrue(StaffAbilities::allows($editor, 'content.manage'));
        $this->assertTrue(StaffAbilities::allows($editor, 'catalogue.manage'));
    }

    public function test_a_counsellor_can_process_applications_and_documents(): void
    {
        $c = $this->withRole(Role::StaffCounsellor);

        $this->assertTrue(StaffAbilities::allows($c, 'applications.process'));
        $this->assertTrue(StaffAbilities::allows($c, 'documents.review'));
        $this->assertTrue(StaffAbilities::allows($c, 'students.crossTenant'));
        // suspending an agency is a partner-ops decision, not a counsellor's
        $this->assertFalse(StaffAbilities::allows($c, 'agencies.manage'));
    }

    public function test_partner_ops_manages_agencies_but_not_cross_tenant_students(): void
    {
        $ops = $this->withRole(Role::StaffPartnerOps);

        $this->assertTrue(StaffAbilities::allows($ops, 'agencies.manage'));
        $this->assertTrue(StaffAbilities::allows($ops, 'applications.process'));
        $this->assertFalse(StaffAbilities::allows($ops, 'students.crossTenant'));
    }

    public function test_a_superadmin_holds_everything(): void
    {
        $su = $this->withRole(Role::SuperAdmin);

        foreach (['applications.process', 'documents.review', 'agencies.manage',
            'students.crossTenant', 'catalogue.manage', 'content.manage', 'enquiries.view'] as $a) {
            $this->assertTrue(StaffAbilities::allows($su, $a), $a);
        }
    }

    public function test_unknown_abilities_and_guests_are_denied(): void
    {
        $this->assertFalse(StaffAbilities::allows($this->withRole(Role::StaffCounsellor), 'money.refund'));
        $this->assertFalse(StaffAbilities::allows($this->withRole(null), 'applications.process'));
        $this->assertFalse(StaffAbilities::allows(null, 'applications.process'));
    }

    // ---- staff account creation -------------------------------------------

    public function test_a_superadmin_creates_a_working_staff_account(): void
    {
        $su = $this->withRole(Role::SuperAdmin);

        $made = app(AdminAccounts::class)->createStaff($su, 'Case Officer', 'Ops@VFI-FC.com', Role::StaffPartnerOps);

        $user = $made['user']->fresh();
        $this->assertSame('ops@vfi-fc.com', $user->email);            // normalised
        $this->assertTrue($user->hasRole(Role::StaffPartnerOps));
        $this->assertTrue($user->usesAdminPanel());
        // the returned password is the real one, and it is hashed at rest
        $this->assertTrue(Hash::check($made['password'], $user->password));
        $this->assertNotSame($made['password'], $user->password);
        // and the new account can immediately do its job
        $this->assertTrue(StaffAbilities::allows($user, 'applications.process'));
    }

    public function test_only_a_superadmin_may_create_staff(): void
    {
        $this->expectException(\RuntimeException::class);
        app(AdminAccounts::class)->createStaff(
            $this->withRole(Role::StaffPartnerOps), 'X', 'x@vfi-fc.com', Role::StaffCounsellor
        );
    }

    public function test_superadmin_cannot_be_handed_out_here(): void
    {
        $this->expectException(\RuntimeException::class);
        app(AdminAccounts::class)->createStaff(
            $this->withRole(Role::SuperAdmin), 'X', 'x@vfi-fc.com', Role::SuperAdmin
        );
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $su = $this->withRole(Role::SuperAdmin);
        app(AdminAccounts::class)->createStaff($su, 'One', 'dupe@vfi-fc.com', Role::StaffCounsellor);

        $this->expectException(\RuntimeException::class);
        app(AdminAccounts::class)->createStaff($su, 'Two', 'dupe@vfi-fc.com', Role::StaffCounsellor);
    }

    public function test_creation_is_recorded_without_leaking_the_password(): void
    {
        $su = $this->withRole(Role::SuperAdmin);
        $made = app(AdminAccounts::class)->createStaff($su, 'Logged', 'logged@vfi-fc.com', Role::StaffCounsellor);

        $ev = AuthEvent::where('event', 'staff_account_created')->latest('id')->firstOrFail();
        $this->assertSame($su->id, $ev->user_id);            // the actor, not the new account
        $this->assertSame('logged@vfi-fc.com', $ev->email);
        $this->assertStringNotContainsString($made['password'], json_encode($ev->context));
    }
}
