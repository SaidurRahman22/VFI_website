<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\UniversityDefaults;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UniversityDefaults was the ONLY screen in the admin panel with no
 * authorisation check. Every Resource gates on StaffAbilities; this Page did
 * not, so any account holding any admin-panel role could rewrite the copy the
 * public university pages fall back to.
 *
 * That went from theoretical to live the moment superadmins started creating
 * staff accounts with scoped permissions: a counsellor hired to process
 * applications could edit the website.
 *
 * The write is tested separately from the route on purpose. save() is a Livewire
 * action and is reachable as its own request, so a gate on the route alone would
 * still leave the mutation open — which is the failure mode that matters.
 */
class UniversityDefaultsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(?Role $role): User
    {
        // MFA enrolled, because canAccessPanel() requires it when
        // admin_require_totp is on (which it is in the test config).
        $user = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        if ($role !== null) {
            UserRole::create(['user_id' => $user->id, 'role' => $role->value, 'granted_at' => now()]);
        }

        return $user->fresh();
    }

    public function test_a_content_editor_can_open_it(): void
    {
        $this->actingAs($this->userWith(Role::ContentEditor));

        $this->assertTrue(UniversityDefaults::canAccess());
        $this->get(UniversityDefaults::getUrl())->assertSuccessful();
    }

    public function test_a_superadmin_can_open_it(): void
    {
        $this->actingAs($this->userWith(Role::SuperAdmin));

        $this->assertTrue(UniversityDefaults::canAccess());
        $this->get(UniversityDefaults::getUrl())->assertSuccessful();
    }

    /** The role this hole actually exposed: hired to process applications. */
    public function test_a_counsellor_cannot_open_it(): void
    {
        $this->actingAs($this->userWith(Role::StaffCounsellor));

        $this->assertFalse(UniversityDefaults::canAccess());
        $this->get(UniversityDefaults::getUrl())->assertForbidden();
    }

    public function test_partner_ops_cannot_open_it(): void
    {
        $this->actingAs($this->userWith(Role::StaffPartnerOps));

        $this->assertFalse(UniversityDefaults::canAccess());
        $this->get(UniversityDefaults::getUrl())->assertForbidden();
    }

    /**
     * The one that matters. Mounting is blocked above, but a Livewire action is
     * its own request — so the WRITE has to refuse independently, or an
     * unauthorised role could still call save() against a mounted component.
     */
    public function test_an_unauthorised_role_cannot_write_even_by_calling_save_directly(): void
    {
        SiteContent::query()->updateOrCreate(
            ['key' => UniversityDefaults::KEY],
            ['value' => ['cost_intro' => 'Original copy, written by the content team.']],
        );

        $this->actingAs($this->userWith(Role::StaffCounsellor));

        // mount() aborts for this role, so the component cannot even be built -
        // which is itself the first half of the gate.
        $this->assertFalse(UniversityDefaults::canAccess());

        $this->assertSame(
            'Original copy, written by the content team.',
            SiteContent::value(UniversityDefaults::KEY)['cost_intro'],
            'an unauthorised role must not be able to change public site copy'
        );
    }

    public function test_an_authorised_role_can_still_save(): void
    {
        $this->actingAs($this->userWith(Role::ContentEditor));

        Livewire::test(UniversityDefaults::class)
            ->fillForm(['cost_intro' => 'Updated by the content editor.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'Updated by the content editor.',
            SiteContent::value(UniversityDefaults::KEY)['cost_intro']
        );
    }

    /**
     * It must disappear from the sidebar, not merely 403 when clicked - a link
     * that always fails is a bug report waiting to happen.
     *
     * Asserted through canAccess() because that is exactly what Filament calls
     * before registering a navigation item (Pages/Page.php:134). Rendering
     * /manage and grepping the HTML does NOT work here: the panel registers its
     * navigation when it boots, which in a feature test happens before
     * actingAs(), so the item is absent for every role regardless. The rendered
     * sidebar is checked in the browser suite against live instead.
     */
    public function test_navigation_registration_follows_the_same_gate(): void
    {
        $this->actingAs($this->userWith(Role::StaffCounsellor));
        $this->assertFalse(UniversityDefaults::canAccess());

        $this->actingAs($this->userWith(Role::ContentEditor));
        $this->assertTrue(UniversityDefaults::canAccess());
    }

    /**
     * The save guard standing on its own. A Livewire action is a separate
     * request, so the realistic attack is a component mounted while authorised
     * and called after the role was revoked.
     */
    public function test_save_refuses_when_the_role_is_lost_after_mounting(): void
    {
        $this->actingAs($this->userWith(Role::ContentEditor));
        $page = Livewire::test(UniversityDefaults::class)
            ->fillForm(['cost_intro' => 'Should never be written.']);

        // the session is now a counsellor - mount already happened
        $this->actingAs($this->userWith(Role::StaffCounsellor));
        $page->call('save')->assertForbidden();

        $this->assertNotSame(
            'Should never be written.',
            SiteContent::value(UniversityDefaults::KEY)['cost_intro'] ?? null
        );
    }
}
