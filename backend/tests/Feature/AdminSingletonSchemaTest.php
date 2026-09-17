<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SiteContent;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The singleton editor's field schema, and the form the console builds from it.
 *
 * Why this matters more than it looks: `settings` holds the brand, the phone
 * numbers, the email and the address that appear in the footer of every page on
 * the site. Until this console, the only screen that edited them was
 * admin.html, which writes to the editor's own localStorage — so changing the
 * phone number changed it in one browser and nowhere else. This endpoint is the
 * first thing that actually saves them.
 *
 * What is tested beyond the happy path:
 *
 *   ONLY THE FLAT KEYS GET A FORM. countries, regions and servicesPage hold
 *   repeating blocks per slug. A console that rendered them as a flat form
 *   would show a handful of fields and then DELETE everything it could not
 *   show on the next save, so they must not be offered one.
 *
 *   THE VERSION IS NOT DECORATION. Two editors with the form open must not
 *   silently overwrite each other; the second save has to lose visibly.
 *
 *   UNDESCRIBED KEYS SURVIVE. The stored object may hold keys the schema does
 *   not mention yet. A save must not drop them.
 */
class AdminSingletonSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::ContentEditor): User
    {
        $u = User::factory()->create([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enrolled_at' => now(),
        ]);
        UserRole::create(['user_id' => $u->id, 'role' => $role->value, 'granted_at' => now()]);

        return $u->fresh();
    }

    // ---------------------------------------------------------------- access

    public function test_the_singleton_list_needs_a_content_role(): void
    {
        $this->getJson('/api/admin/content/singletons')->assertStatus(401);

        $this->actingAs($this->staff(Role::StaffPartnerOps));
        $this->getJson('/api/admin/content/singletons')->assertStatus(403);
    }

    // ------------------------------------------------------------------ list

    public function test_only_the_flat_singletons_are_offered_as_a_form(): void
    {
        $this->actingAs($this->staff());

        $keys = array_column($this->getJson('/api/admin/content/singletons')->assertOk()->json('data'), 'key');

        $this->assertSame(['settings', 'partnerPage', 'partnerPortal'], $keys);

        // Rendering these as a flat form would drop every block it could not
        // show, on the very next save.
        $this->assertNotContains('countries', $keys);
        $this->assertNotContains('regions', $keys);
        $this->assertNotContains('servicesPage', $keys);
    }

    public function test_a_repeating_block_singleton_still_reads_but_carries_no_form(): void
    {
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/singleton/countries')->assertOk();

        $this->assertNull($res->json('sections'), 'a flat form must not be offered for these');
        $this->assertNotNull($res->json('version'), 'but it is still readable and writable as before');
    }

    // ---------------------------------------------------------------- schema

    /**
     * The console renders its inputs from this, so a missing field is an
     * uneditable setting — and these particular settings are on every page.
     */
    public function test_every_stored_setting_has_a_field_to_edit_it(): void
    {
        SiteContent::query()->create(['key' => 'settings', 'version' => 1, 'value' => [
            'brand' => 'VFI', 'tagline' => 'overseas education', 'about' => 'About us.',
            'phone' => '+880 1700-000000', 'phone2' => '+880 9600-000000',
            'email' => 'dhaka@vfi-fc.com', 'address' => 'Gulshan Avenue',
            'addressShort' => 'Dhaka', 'hours' => 'Sat – Thu',
            'facebook' => '#', 'instagram' => '#', 'linkedin' => '#', 'x' => '#', 'youtube' => '#',
        ]]);
        $this->actingAs($this->staff());

        $res = $this->getJson('/api/admin/content/singleton/settings')->assertOk();

        $described = [];
        foreach ($res->json('sections') as $section) {
            $this->assertNotEmpty($section['title']);
            foreach ($section['fields'] as $f) {
                $described[] = $f['key'];
                $this->assertNotEmpty($f['label'], $f['key'].' needs a label a person can read');
            }
        }

        $stored = array_keys($res->json('value'));
        $this->assertSame([], array_diff($stored, $described), 'every stored key must be editable');
        $this->assertSame('Site settings', $res->json('label'));
    }

    // ----------------------------------------------------------------- write

    public function test_saving_writes_the_settings_and_bumps_the_version(): void
    {
        SiteContent::query()->create(['key' => 'settings', 'version' => 1,
            'value' => ['brand' => 'VFI', 'phone' => '+880 1700-000000']]);
        $this->actingAs($this->staff());

        $res = $this->putJson('/api/admin/content/singleton/settings', [
            'version' => 1,
            'value' => ['brand' => 'VFI', 'phone' => '+880 1900-111222'],
        ])->assertOk();

        $this->assertSame(2, $res->json('version'));
        $this->assertSame('+880 1900-111222', SiteContent::value('settings')['phone']);
    }

    /**
     * Two people with the form open. The second save must lose visibly rather
     * than wiping the first — the whole reason the endpoint carries a version.
     */
    public function test_a_stale_save_is_refused_rather_than_overwriting(): void
    {
        SiteContent::query()->create(['key' => 'settings', 'version' => 1, 'value' => ['phone' => 'original']]);
        $this->actingAs($this->staff());

        // Editor A saves.
        $this->putJson('/api/admin/content/singleton/settings', [
            'version' => 1, 'value' => ['phone' => 'from editor A'],
        ])->assertOk();

        // Editor B still holds version 1.
        $this->putJson('/api/admin/content/singleton/settings', [
            'version' => 1, 'value' => ['phone' => 'from editor B'],
        ])->assertStatus(409)->assertJsonPath('currentVersion', 2);

        $this->assertSame('from editor A', SiteContent::value('settings')['phone']);
    }

    /**
     * The console merges its form over the value it loaded. If it did not, a
     * save would delete whatever the schema has not caught up with yet — so the
     * endpoint must faithfully store what it is handed.
     */
    public function test_a_key_the_schema_does_not_describe_can_still_be_kept(): void
    {
        SiteContent::query()->create(['key' => 'settings', 'version' => 1,
            'value' => ['phone' => '1', 'someFutureKey' => 'keep me']]);
        $this->actingAs($this->staff());

        $this->putJson('/api/admin/content/singleton/settings', [
            'version' => 1,
            'value' => ['phone' => '2', 'someFutureKey' => 'keep me'],
        ])->assertOk();

        $this->assertSame('keep me', SiteContent::value('settings')['someFutureKey']);
    }

    public function test_an_unknown_singleton_is_a_404(): void
    {
        $this->actingAs($this->staff());

        $this->getJson('/api/admin/content/singleton/users')->assertStatus(404);
        $this->putJson('/api/admin/content/singleton/users', ['version' => 0, 'value' => []])
            ->assertStatus(404);
    }
}
