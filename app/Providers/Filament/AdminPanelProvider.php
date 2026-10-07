<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Modules\Admin\Filament\Pages\Login;
use App\Modules\Admin\Http\Middleware\EnforceAdminIpAllowlist;
use App\Modules\Admin\Support\AdminMfa;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel Super Admin (fondateur). Séparé du client : guard `platform`, rôle de base `nova_platform`, 2FA obligatoire.
 * Pas d'impersonation (décision du fondateur). Voir docs/02-roles-et-permissions.md §3.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Sous-domaine dédié en production (cookies de session distincts) ; sans valeur en développement local.
            ->domain(config('nova.admin_domain'))
            ->authGuard('platform')
            ->login(Login::class)
            ->brandName('Nova Admin')
            // Couleur ambre : distingue immédiatement l'espace Super Admin de l'espace client (docs/03 §A.1).
            ->colors(['primary' => Color::Amber])
            ->discoverResources(in: app_path('Modules/Admin/Filament/Resources'), for: 'App\\Modules\\Admin\\Filament\\Resources')
            ->pages([Dashboard::class])
            ->widgets([])
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
                SetLocale::class,
            ])
            ->authMiddleware([Authenticate::class, EnforceAdminIpAllowlist::class]);

        // Authentification à deux facteurs (TOTP) OBLIGATOIRE, avec codes de secours. Seul le développement local peut la désactiver (AdminMfa).
        if (AdminMfa::required()) {
            $panel->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true);
        }

        return $panel;
    }
}
