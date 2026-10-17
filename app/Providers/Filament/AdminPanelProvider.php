<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
use App\Modules\Admin\Filament\Pages\AdminDashboard;
use App\Modules\Admin\Filament\Pages\ClientReports;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentDetail;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentHistory;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocuments;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentUpload;
use App\Modules\Admin\Filament\Pages\Login;
use App\Modules\Admin\Filament\Pages\SupportReports;
use App\Modules\Admin\Http\Controllers\LegalFileController;
use App\Modules\Admin\Http\Controllers\SupportAttachmentController;
use App\Modules\Admin\Http\Middleware\EnforceAdminIdleTimeout;
use App\Modules\Admin\Http\Middleware\EnforceAdminIpAllowlist;
use App\Modules\Admin\Support\AdminMfa;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
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
            ->domain(config('rendivoo.admin_domain'))
            ->authGuard('platform')
            ->login(Login::class)
            ->brandName('Rendivoo Admin')
            // Couleur ambre : distingue immédiatement l'espace Super Admin de l'espace client (docs/03 §A.1).
            ->colors(['primary' => Color::Amber])
            ->discoverResources(in: app_path('Modules/Admin/Filament/Resources'), for: 'App\\Modules\\Admin\\Filament\\Resources')
            // Menu : tableau de bord, clients et factures, puis « Configuration » (secteurs, paramètres) et « Opérations » (suppressions, journal).
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('tickets.navigation.group')),
                NavigationGroup::make()->label(fn (): string => __('faq.navigation.group')),
                NavigationGroup::make()->label(fn (): string => __('admin.navigation.configuration')),
                NavigationGroup::make()->label(fn (): string => __('admin.navigation.operations')),
            ])
            ->pages([AdminDashboard::class, ClientReports::class, SupportReports::class, LegalDocuments::class, LegalDocumentDetail::class, LegalDocumentUpload::class, LegalDocumentHistory::class])
            // Notifications des agents (cloche) : table réservée au rôle de la plateforme (PlatformNotification).
            ->databaseNotifications()
            // Téléchargement d'une pièce jointe de ticket : même authentification et même liste blanche d'adresses que le panel.
            ->authenticatedRoutes(function (): void {
                Route::get('/support-attachments/{attachment}', SupportAttachmentController::class)->name('support.attachment');
                // Téléchargement contrôlé d'un PDF juridique (version en vigueur ou archivée) : Super Admin seulement, tracé.
                Route::get('/legal-documents/{document}/versions/{version}/file', LegalFileController::class)->name('legal.file');
            })
            // Bouton « Retour » en haut à gauche de chaque écran profond, et confirmation avant de quitter un formulaire modifié (docs/36).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => view('admin.back')->render())
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
            ->authMiddleware([Authenticate::class, EnforceAdminIpAllowlist::class, EnforceAdminIdleTimeout::class]);

        // Authentification à deux facteurs (TOTP) OBLIGATOIRE, avec codes de secours. Seul le développement local peut la désactiver (AdminMfa).
        if (AdminMfa::required()) {
            $panel->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true);
        }

        return $panel;
    }
}
