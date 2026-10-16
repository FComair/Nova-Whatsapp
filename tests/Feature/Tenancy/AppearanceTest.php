<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Réglages › Apparence : le thème se choisit dans les réglages (tous les rôles), plus dans la barre principale.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'locale' => 'fr']);
});

it('affiche la page Apparence au membre connecté', function (): void {
    $this->signedIn($this->owner, $this->org)->get('/reglages/apparence')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Account/Appearance'));
});

it('exige d\'être connecté', function (): void {
    $this->get('/reglages/apparence')->assertRedirect();
});

it('propose Clair, Sombre et Défaut du système dans les trois langues', function (): void {
    foreach (['fr' => 'Défaut du système', 'nl' => 'Systeemstandaard', 'en' => 'System default'] as $locale => $system) {
        expect(trans('ui.common.themes.system', [], $locale))->toBe($system)
            ->and(trans('ui.appearance.title', [], $locale))->not->toBe('ui.appearance.title');
    }
});
