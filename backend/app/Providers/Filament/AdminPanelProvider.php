<?php

namespace App\Providers\Filament;

use App\Http\Middleware\StaffRlsRead;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('manage')                 // /manage — distinct from the static admin.html
            ->brandName('VFI Admin')
            // No ->login(): the panel has NO Filament login page. Auth is owned
            // by the TOTP-gated flow (admin-login.html → /api/admin/login). Guests
            // are redirected to admin-login.html (bootstrap/app.php redirectGuestsTo),
            // and canAccessPanel() (App\Models\User) additionally requires an
            // admin-panel role + completed TOTP. (Phase 1 §4)
            ->colors([
                'primary' => Color::hex('#2f62a8'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Reads across tenants. Required HERE for the page render, and
                // ALSO on the global web group for /livewire/update, which every
                // panel button acts through and which is not part of this stack.
                // Registered in one place only, either page loads render empty
                // or every button dies — see StaffRlsRead's own notes.
                StaffRlsRead::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
        // NOTE: StaffRlsRead is registered BOTH above and on the global web
        // group in bootstrap/app.php. A Filament panel does not include
        // Laravel's `web` group, and /livewire/update is not in this stack, so
        // each registration covers a half the other cannot reach.
    }
}
