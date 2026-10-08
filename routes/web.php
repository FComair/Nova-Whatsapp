<?php

declare(strict_types=1);

use App\Http\Controllers\AccountStateController;
use App\Http\Controllers\DashboardController;
use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Booking\Http\Controllers\PlanningController;
use App\Modules\Calendars\Http\Controllers\CalendarController;
use App\Modules\Calendars\Http\Controllers\FeedController;
use App\Modules\Catalog\Http\Controllers\PriceListImportController;
use App\Modules\Catalog\Http\Controllers\ServiceController;
use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\LocaleController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Identity\Http\Controllers\SecurityController;
use App\Modules\Identity\Http\Controllers\TwoFactorChallengeController;
use App\Modules\Identity\Http\Middleware\RequireTwoFactor;
use App\Modules\Onboarding\Http\Controllers\OnboardingController;
use App\Modules\Onboarding\Http\Controllers\SetupController;
use App\Modules\Privacy\Http\Controllers\AccountDataController;
use App\Modules\Staff\Http\Controllers\InvitationController;
use App\Modules\Staff\Http\Controllers\ProfileController;
use App\Modules\Staff\Http\Controllers\StaffController;
use App\Modules\Tenancy\Http\Controllers\AccountProfileController;
use App\Modules\Tenancy\Http\Controllers\PauseController;
use App\Modules\Tenancy\Http\Controllers\SettingsSearchController;
use App\Modules\Tenancy\Http\Middleware\EnsureOrganizationUsable;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Whatsapp\Http\Controllers\WhatsappController;
use App\Modules\Whatsapp\Http\Controllers\WhatsappQrController;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Espace client
|--------------------------------------------------------------------------
| Le Super Admin est un panel séparé (guard, sous-domaine et rôle de base distincts).
*/

Route::redirect('/', '/connexion');

Route::post('/langue', [LocaleController::class, 'update'])->name('locale.update');

// Flux ICS sortant d'un praticien : adresse secrète (jeton), sans session.
Route::get('/ics/{token}.ics', [FeedController::class, 'show'])->where('token', 'nf_[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('calendar.feed');

// --- Visiteurs (flowcharts 1A, 2A, 5A) ---
Route::middleware('guest')->group(function (): void {
    Route::get('/inscription', [RegisterController::class, 'create'])->name('register');
    Route::post('/inscription', [RegisterController::class, 'store'])->middleware('throttle:10,1')->name('register.store');

    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');

    // Seconde étape de la connexion (double authentification) : le mot de passe est déjà vérifié.
    Route::get('/connexion/verification', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/connexion/verification', [TwoFactorChallengeController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.verify');

    Route::get('/mot-de-passe-oublie', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reinitialiser-mot-de-passe/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reinitialiser-mot-de-passe', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('password.update');

    // Invitation d'un praticien : page publique (l'invité n'a pas encore de compte).
    Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

    // Activation du compte : e-mail puis téléphone. L'utilisateur n'est pas encore connecté.
    Route::get('/verification-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verification-email/expire', [EmailVerificationController::class, 'expired'])->name('verification.expired');
    Route::get('/verification-email/{user}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/verification-email/renvoi', [EmailVerificationController::class, 'resend'])->middleware('throttle:10,1')->name('verification.send');

    Route::get('/verification-telephone', [PhoneVerificationController::class, 'show'])->name('phone.show');
    Route::post('/verification-telephone', [PhoneVerificationController::class, 'verify'])->middleware('throttle:15,1')->name('phone.verify');
    Route::post('/verification-telephone/renvoi', [PhoneVerificationController::class, 'resend'])->middleware('throttle:10,1')->name('phone.send');
});

// --- Espace connecté : chaque route s'exécute dans le contexte de l'organisation (RLS) ---
Route::middleware(['auth', AuthenticateSession::class, SetTenantContext::class, RequireTwoFactor::class, EnsureOrganizationUsable::class])->group(function (): void {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/tableau-de-bord', DashboardController::class)->name('dashboard');
    Route::get('/compte', AccountStateController::class)->name('account.state');

    // Paramètres > Sécurité (flowchart 3C) : tous les rôles, quel que soit le statut du compte.
    Route::prefix('/securite')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'show'])->name('show');
        Route::put('/mot-de-passe', [SecurityController::class, 'changePassword'])->middleware('throttle:10,1')->name('password');
        Route::post('/sessions/fermer', [SecurityController::class, 'closeOtherSessions'])->middleware('throttle:10,1')->name('sessions.close');
        Route::post('/email', [SecurityController::class, 'requestEmailChange'])->middleware('throttle:10,1')->name('email.request');
        Route::get('/email/confirmer/{token}', [SecurityController::class, 'confirmEmailChange'])->middleware('signed')->name('email.confirm');
        Route::post('/telephone', [SecurityController::class, 'requestPhoneChange'])->middleware('throttle:10,1')->name('phone.request');
        Route::post('/telephone/confirmer', [SecurityController::class, 'confirmPhoneChange'])->middleware('throttle:15,1')->name('phone.confirm');
        Route::post('/2fa', [SecurityController::class, 'beginTwoFactor'])->name('two-factor.begin');
        Route::post('/2fa/confirmer', [SecurityController::class, 'confirmTwoFactor'])->middleware('throttle:15,1')->name('two-factor.confirm');
        Route::post('/2fa/codes', [SecurityController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1')->name('two-factor.codes');
        Route::delete('/2fa', [SecurityController::class, 'disableTwoFactor'])->middleware('throttle:10,1')->name('two-factor.disable');
    });

    // Recherche dans les Réglages : index multilingue, filtré selon le rôle (lecture seule).
    Route::get('/reglages/recherche', SettingsSearchController::class)->name('settings.search');

    // Réglages > Profil : coordonnées de chacun (tous les rôles) ; entreprise et facturation réservées au propriétaire.
    Route::prefix('/reglages/profil')->name('settings.profile.')->group(function (): void {
        Route::get('/', [AccountProfileController::class, 'show'])->name('show');
        Route::put('/entreprise', [AccountProfileController::class, 'updateCompany'])->middleware('role:owner')->name('company');
        // Logo de l'établissement : lecture pour tous les rôles (barre de navigation), envoi et retrait réservés au propriétaire.
        Route::get('/logo/{size}', [AccountProfileController::class, 'showLogo'])->whereNumber('size')->name('logo.show');
        Route::post('/logo', [AccountProfileController::class, 'storeLogo'])->middleware(['role:owner', 'throttle:10,1'])->name('logo.store');
        Route::delete('/logo', [AccountProfileController::class, 'destroyLogo'])->middleware(['role:owner', 'throttle:10,1'])->name('logo.destroy');
    });

    // Réglages > Mise en route : liste des tâches par catégorie, qui renvoie vers les écrans existants (propriétaire uniquement).
    Route::get('/reglages/demarrage/{category}', [SetupController::class, 'show'])->middleware('role:owner')->name('settings.setup.show');

    // Assistant d'onboarding : propriétaire uniquement (docs/02 §1).
    Route::middleware('role:owner')->prefix('/configuration/assistant')->group(function (): void {
        Route::get('/', [OnboardingController::class, 'index'])->name('onboarding.index');
        Route::get('/{slug}', [OnboardingController::class, 'show'])->name('onboarding.step');

        Route::post('/entreprise', [OnboardingController::class, 'saveCompany'])->name('onboarding.company');

        Route::post('/sites', [OnboardingController::class, 'storeSite'])->name('onboarding.sites.store');
        Route::post('/sites/continuer', [OnboardingController::class, 'completeSites'])->name('onboarding.sites.complete');
        Route::put('/sites/{location}', [OnboardingController::class, 'updateSite'])->name('onboarding.sites.update');
        Route::delete('/sites/{location}', [OnboardingController::class, 'destroySite'])->name('onboarding.sites.destroy');

        Route::put('/horaires/{location}', [OnboardingController::class, 'saveHours'])->name('onboarding.hours.save');

        Route::post('/services/continuer', [OnboardingController::class, 'completeServices'])->name('onboarding.services.complete');
        Route::post('/praticiens/continuer', [OnboardingController::class, 'completePractitioners'])->name('onboarding.practitioners.complete');
        Route::post('/politiques', [OnboardingController::class, 'savePolicies'])->name('onboarding.policies');

        Route::post('/whatsapp/continuer', [OnboardingController::class, 'completeWhatsapp'])->name('onboarding.whatsapp.complete');
        Route::post('/messages-types/continuer', [OnboardingController::class, 'completeTemplates'])->name('onboarding.templates.complete');
    });

    // Espace du praticien : il renseigne lui-même ses services et disponibilités (docs/02 §1).
    Route::middleware('role:practitioner,owner')->prefix('/mon-profil')->group(function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/services', [ProfileController::class, 'saveServices'])->name('profile.services');
        Route::put('/horaires/{location}', [ProfileController::class, 'saveSchedule'])->name('profile.schedule');
    });

    // Praticiens : propriétaire (docs/02 §1).
    Route::middleware('role:owner')->prefix('/configuration/praticiens')->group(function (): void {
        Route::post('/', [StaffController::class, 'store'])->name('practitioners.store');
        Route::post('/invitation', [StaffController::class, 'invite'])->name('practitioners.invite');
        Route::post('/{practitioner}/invitation', [StaffController::class, 'resendInvitation'])->name('practitioners.reinvite');
        Route::put('/{practitioner}', [StaffController::class, 'update'])->name('practitioners.update');
        Route::delete('/{practitioner}', [StaffController::class, 'destroy'])->name('practitioners.destroy');
        Route::post('/{practitioner}/activer', [StaffController::class, 'activate'])->name('practitioners.activate');
        Route::put('/{practitioner}/horaires/{location}', [StaffController::class, 'saveSchedule'])->name('practitioners.schedule');
    });

    // Import du catalogue depuis une liste de prix photographiée : propriétaire uniquement (lecture, vérification, import, annulation).
    Route::middleware('role:owner')->prefix('/configuration/services/import')->name('services.import.')->group(function (): void {
        Route::get('/', [PriceListImportController::class, 'index'])->name('index');
        Route::post('/', [PriceListImportController::class, 'store'])->name('store');
        Route::get('/{import}', [PriceListImportController::class, 'review'])->name('review');
        Route::post('/{import}/apply', [PriceListImportController::class, 'apply'])->name('apply');
        Route::post('/{import}/undo', [PriceListImportController::class, 'undo'])->name('undo');
        Route::delete('/{import}', [PriceListImportController::class, 'cancel'])->name('cancel');
        Route::get('/{import}/csv', [PriceListImportController::class, 'csv'])->name('csv');
    });

    // Catalogue de services : propriétaire et responsable de site (docs/02 §1).
    Route::middleware('role:owner,site_manager')->prefix('/configuration/services')->group(function (): void {
        Route::post('/', [ServiceController::class, 'store'])->name('services.store');
        Route::post('/modele', [ServiceController::class, 'applyTemplate'])->name('services.template');
        Route::put('/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::delete('/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
    });

    // Planning : tous les rôles y accèdent ; PlanningAccess restreint aux sites et rendez-vous autorisés (docs/02 §1).
    Route::prefix('/planning')->group(function (): void {
        Route::get('/', [PlanningController::class, 'index'])->name('planning.index');
        Route::post('/rendez-vous', [PlanningController::class, 'store'])->name('planning.store');
        Route::put('/rendez-vous/{booking}/deplacer', [PlanningController::class, 'move'])->name('planning.move');
        Route::post('/rendez-vous/{booking}/annuler', [PlanningController::class, 'cancel'])->name('planning.cancel');
        Route::post('/rendez-vous/{booking}/resultat', [PlanningController::class, 'finish'])->name('planning.finish');
        Route::put('/rendez-vous/{booking}/paiement', [PlanningController::class, 'payment'])->name('planning.payment');
    });

    // Fiches clients : PlanningAccess/CustomerController restreignent le praticien à ses propres clients (docs/02 §1).
    Route::prefix('/clients')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/actions', [CustomerController::class, 'bulk'])->name('customers.bulk');
        Route::post('/vues', [CustomerController::class, 'saveView'])->name('customers.views.store');
        Route::delete('/vues/{view}', [CustomerController::class, 'deleteView'])->name('customers.views.destroy');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::put('/{customer}/blocage', [CustomerController::class, 'block'])->name('customers.block');
        // Effacement à la demande du client final (E14) : propriétaire et responsable de site (docs/02 §1).
        Route::post('/{customer}/effacement', [CustomerController::class, 'erase'])->middleware('role:owner,site_manager')->name('customers.erase');
    });

    // Facturation : propriétaire uniquement ; accessible quel que soit le statut du compte, pour pouvoir régulariser (docs/02 §4).
    Route::middleware('role:owner')->prefix('/facturation')->group(function (): void {
        Route::get('/', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/abonnement', [BillingController::class, 'choosePlan'])->name('billing.plan');
        Route::post('/moyen-de-paiement', [BillingController::class, 'changeMethod'])->name('billing.method');
        Route::get('/retour', [BillingController::class, 'completeReturn'])->name('billing.return');
        Route::post('/annulation', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::delete('/annulation', [BillingController::class, 'resume'])->name('billing.resume');
        Route::put('/identite', [BillingController::class, 'updateIdentity'])->name('billing.identity');
        Route::get('/factures/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');
        Route::get('/simulation', [BillingController::class, 'simulation'])->name('billing.simulation');
        Route::post('/simulation', [BillingController::class, 'simulate'])->name('billing.simulate');
    });

    // « Mes données » : export et suppression du compte, propriétaire uniquement ; accessibles quel que soit le statut (docs/02 §4).
    Route::middleware('role:owner')->prefix('/compte')->group(function (): void {
        Route::get('/donnees', [AccountDataController::class, 'show'])->name('account.data');
        Route::post('/donnees/export', [AccountDataController::class, 'export'])->name('account.export');
        Route::post('/suppression', [AccountDataController::class, 'requestDeletion'])->name('account.deletion.request');
        Route::get('/suppression/confirmer/{token}', [AccountDataController::class, 'confirmDeletion'])->middleware('signed')->name('account.deletion.confirm');
        Route::delete('/suppression', [AccountDataController::class, 'cancelDeletion'])->name('account.deletion.cancel');

        // Pause d'établissement (flowchart 4B) : non facturée, 90 jours au plus (réglage pause_max_days).
        Route::get('/pause', [PauseController::class, 'show'])->name('account.pause.show');
        Route::post('/pause', [PauseController::class, 'start'])->name('account.pause.start');
        Route::delete('/pause', [PauseController::class, 'resume'])->name('account.pause.resume');
    });

    // WhatsApp : connexion du numéro et modèles de messages (étapes 7 et 8 de l'assistant, puis paramètres) ; propriétaire uniquement.
    Route::middleware('role:owner')->prefix('/whatsapp')->name('whatsapp.')->group(function (): void {
        Route::get('/', [WhatsappController::class, 'show'])->name('show');
        Route::post('/', [WhatsappController::class, 'connect'])->name('connect');
        Route::post('/meta', [WhatsappController::class, 'embedded'])->middleware('throttle:10,1')->name('embedded');
        Route::delete('/', [WhatsappController::class, 'disconnect'])->name('disconnect');
        Route::post('/modeles', [WhatsappController::class, 'submitTemplates'])->name('templates.submit');
        Route::post('/modeles/synchroniser', [WhatsappController::class, 'syncTemplates'])->name('templates.sync');
    });

    // QR codes WhatsApp : liens wa.me imprimables vers l'assistant ; propriétaire uniquement.
    Route::middleware('role:owner')->prefix('/qr-whatsapp')->name('whatsapp.qr.')->group(function (): void {
        Route::get('/', [WhatsappQrController::class, 'show'])->name('show');
        Route::post('/', [WhatsappQrController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::put('/{code}', [WhatsappQrController::class, 'update'])->name('update');
        Route::delete('/{code}', [WhatsappQrController::class, 'destroy'])->name('destroy');
        Route::get('/{code}/{format}', [WhatsappQrController::class, 'image'])->whereIn('format', ['svg', 'png', 'pdf'])->middleware('throttle:60,1')->name('image');
    });

    // Calendriers externes : propriétaire, responsable de site (ses sites) et praticien (les siens) ; pas de réceptionniste (docs/02 §1).
    Route::middleware('role:owner,site_manager,practitioner')->prefix('/calendriers')->group(function (): void {
        Route::get('/', [CalendarController::class, 'index'])->name('calendars.index');
        Route::post('/', [CalendarController::class, 'store'])->name('calendars.store');
        Route::post('/{calendar}/synchroniser', [CalendarController::class, 'refresh'])->name('calendars.refresh');
        Route::put('/{calendar}/pause', [CalendarController::class, 'toggle'])->name('calendars.toggle');
        Route::delete('/{calendar}', [CalendarController::class, 'destroy'])->name('calendars.destroy');
        Route::post('/flux/{practitioner}/renouveler', [CalendarController::class, 'rotateFeed'])->name('calendars.feed.rotate');
    });
});
