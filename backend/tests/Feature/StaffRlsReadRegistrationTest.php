<?php

namespace Tests\Feature;

use App\Http\Middleware\StaffRlsRead;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Structural guard for a bug no behavioural test in this suite can see.
 *
 * The console tables carry Postgres RLS FORCE, and staff hold no tenant, so
 * every staff screen needs StaffRlsRead to read across agencies. It has to be
 * registered in TWO stacks that do not overlap:
 *
 *   - the Filament panel's own middleware  -> the /manage/* page RENDER
 *   - Laravel's global `web` group         -> /livewire/update, where every
 *                                             panel button actually acts
 *
 * A Filament panel does not include the `web` group, and /livewire/update is
 * not in the panel's stack. Register it in only one place and exactly half the
 * panel breaks — silently, with zero rows and no exception. Both halves have
 * shipped broken this way, in both directions.
 *
 * Tests run on SQLite, which has no row-level security, so the flag is a no-op
 * there and a rendering test passes either way. This asserts the wiring instead,
 * which is the part that was actually wrong.
 */
class StaffRlsReadRegistrationTest extends TestCase
{
    public function test_the_admin_panel_render_stack_carries_the_bypass(): void
    {
        $middleware = Filament::getPanel('admin')->getMiddleware();

        $this->assertContains(
            StaffRlsRead::class,
            $middleware,
            'Without this on the panel stack every /manage table renders empty on Postgres.'
        );
    }

    public function test_the_web_group_carries_the_bypass_for_livewire_updates(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertArrayHasKey('web', $groups);
        $this->assertContains(
            StaffRlsRead::class,
            $groups['web'],
            'Without this on the web group every panel BUTTON silently finds zero rows, '
            .'because /livewire/update is not part of the Filament panel stack.'
        );
    }

    /** The bypass must never be handed to a non-admin. */
    public function test_the_bypass_is_gated_on_holding_an_admin_role(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/StaffRlsRead.php'));

        $this->assertStringContainsString('usesAdminPanel()', $source);
        // and it must be released, or a pooled connection carries it onward
        $this->assertStringContainsString('function terminate', $source);
    }
}
