<?php

declare(strict_types=1);

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Support\AdminBack;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Bouton « Retour » du Super Admin (docs/36) : destination et libellé de chaque écran, présence dans la page, protection des formulaires.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['org' => $this->org] = $this->activatedAccount();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->boss->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->boss = $this->boss->refresh();
    $this->back = fn (string $name, array $parameters = []) => AdminBack::for(Route::getRoutes()->getByName('filament.admin.'.$name)?->bind(request()->create(route('filament.admin.'.$name, $parameters))));
});

describe('destination et libellé', function (): void {
    it('ramène un écran de détail ou d\'édition à sa liste', function (string $route, array $parameters, string $label, string $target): void {
        $back = ($this->back)($route, $parameters);

        expect($back['label'])->toBe(__("admin.back.{$label}"))->and($back['url'])->toBe(route('filament.admin.'.$target, $label === 'faq_editor' ? $parameters : []));
    })->with([
        'fiche client' => ['resources.organizations.view', ['record' => 'abc'], 'clients', 'resources.organizations.index'],
        'demande' => ['resources.support-tickets.view', ['record' => 'abc'], 'tickets', 'resources.support-tickets.index'],
        'agent' => ['resources.support-team.view', ['record' => 'abc'], 'support_team', 'resources.support-team.index'],
        'nouvelle FAQ' => ['resources.faq.create', [], 'faq', 'resources.faq.index'],
        'édition FAQ' => ['resources.faq.edit', ['record' => 'abc'], 'faq', 'resources.faq.index'],
        'aperçu FAQ' => ['resources.faq.preview', ['record' => 'abc'], 'faq_editor', 'resources.faq.edit'],
        'textes juridiques' => ['resources.legal-versions.index', [], 'legal', 'pages.legal-documents'],
    ]);

    it('ramène les listes et pages de premier niveau au tableau de bord', function (string $route): void {
        $back = ($this->back)($route);

        expect($back['label'])->toBe(__('admin.back.dashboard'))->and($back['url'])->toBe(route('filament.admin.pages.admin-dashboard'));
    })->with(['resources.organizations.index', 'resources.support-tickets.index', 'resources.support-team.index', 'resources.faq.index', 'resources.feedback.index', 'resources.invoices.index', 'resources.platform-settings.index', 'resources.sectors.index', 'resources.audit-logs.index', 'pages.client-reports', 'pages.support-reports']);

    it('n\'en met pas sur le tableau de bord, la connexion ni les écrans juridiques qui ont le leur', function (string $route): void {
        expect(($this->back)($route))->toBeNull();
    })->with(['pages.admin-dashboard', 'auth.login', 'pages.legal-documents', 'pages.legal-document', 'pages.legal-document-upload', 'pages.legal-document-history']);

    it('a un libellé dans les trois langues', function (): void {
        foreach (['fr', 'en', 'nl'] as $locale) {
            foreach (['aria', 'dashboard', 'clients', 'tickets', 'support_team', 'faq', 'faq_editor', 'legal', 'unsaved.message', 'unsaved.save', 'unsaved.leave', 'unsaved.cancel'] as $key) {
                expect(__("admin.back.{$key}", [], $locale))->not->toBe("admin.back.{$key}");
            }
        }
    });
});

describe('dans la page', function (): void {
    it('affiche « Retour » en haut de la fiche client et de la liste, avec la confirmation de départ', function (): void {
        $list = $this->actingAs($this->boss, 'platform')->get('/admin/organizations')->assertOk();
        $list->assertSee(__('admin.back.dashboard'))->assertSee(route('filament.admin.pages.admin-dashboard'), false)->assertSee(__('admin.back.unsaved.message'))->assertSee('class="nova-back"', false);

        $this->actingAs($this->boss, 'platform')->get('/admin/organizations/'.$this->org->id)->assertOk()
            ->assertSee(__('admin.back.clients'))->assertSee(route('filament.admin.resources.organizations.index'), false);
    });

    it('ne montre pas de retour sur le tableau de bord', function (): void {
        $this->actingAs($this->boss, 'platform')->get('/admin')->assertOk()->assertDontSee('class="nova-back"', false);
    });

    it('reste dans l\'espace Super Admin : l\'adresse du retour ne vient jamais de la requête', function (): void {
        $this->actingAs($this->boss, 'platform')->withHeader('Referer', 'https://evil.example/phish')->get('/admin/organizations/'.$this->org->id)->assertOk()
            ->assertDontSee('evil.example')->assertSee(route('filament.admin.resources.organizations.index'), false);
    });

    it('n\'est pas visible sans connexion', function (): void {
        $this->get('/admin/organizations')->assertRedirect('/admin/login');
    });
});
