<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\Sector;
use App\Modules\Tenancy\Services\SectorCatalog;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Les secteurs proposés aux commerçants viennent de la table gérée par le Super Admin : seuls les secteurs actifs, dans la langue
 * de la personne ; un établissement dans un secteur désactivé le garde.
 */
beforeEach(function (): void {
    $this->deactivate = fn (string $code) => $this->asOwner(fn () => Sector::query()->where('code', $code)->update(['is_active' => false]));
});

describe('options', function (): void {
    it('liste les secteurs actifs dans la langue demandée, « Autre » en dernier', function (): void {
        $options = app(SectorCatalog::class)->options('fr');

        expect($options)->toHaveCount(11)
            ->and($options[0])->toBe(['value' => 'driving_school', 'label' => 'Auto-école'])
            ->and(end($options))->toBe(['value' => 'other', 'label' => 'Autre']);
    });

    it('n\'affiche pas un secteur désactivé, sauf à l\'établissement qui y est déjà', function (): void {
        ($this->deactivate)('driving_school');

        $catalog = app(SectorCatalog::class);

        expect(array_column($catalog->options('en'), 'value'))->not->toContain('driving_school')
            ->and(array_column($catalog->options('en', 'driving_school'), 'value'))->toContain('driving_school')
            ->and($catalog->codes())->not->toContain('driving_school')
            ->and($catalog->codes('driving_school'))->toContain('driving_school');
    });

    it('retombe sur le nom saisi quand la traduction manque', function (): void {
        $this->asOwner(fn () => Sector::query()->where('code', 'dental')->update(['name_fr' => null]));

        $labels = array_column(app(SectorCatalog::class)->options('fr'), 'label', 'value');

        expect($labels['dental'])->toBe('Dentist')->and($labels['garage'])->toBe('Garage et automobile');
    });

    it('propose un secteur ajouté par le Super Admin dès qu\'il est actif', function (): void {
        $this->asOwner(fn () => Sector::query()->create(['code' => 'food', 'name' => 'Food & Beverage', 'name_key' => 'food & beverage', 'is_active' => true]));

        expect(array_column(app(SectorCatalog::class)->options('en'), 'label', 'value'))->toHaveKey('food', 'Food & Beverage');
    });
});

describe('inscription', function (): void {
    it('propose les secteurs actifs avec leur nom', function (): void {
        ($this->deactivate)('driving_school');

        $this->get('/inscription')->assertOk()->assertInertia(fn (Assert $page) => $page->has('sectors', 10)->where('sectors.0.value', 'beauty')->where('sectors.9.value', 'other'));
    });

    it('refuse un secteur désactivé', function (): void {
        ($this->deactivate)('driving_school');

        $this->post('/inscription', $this->validRegistration(['sector' => 'driving_school']))->assertSessionHasErrors('sector');
    });

    it('accepte un secteur ajouté par le Super Admin', function (): void {
        $this->asOwner(fn () => Sector::query()->create(['code' => 'food', 'name' => 'Food & Beverage', 'name_key' => 'food & beverage', 'is_active' => true]));

        $this->post('/inscription', $this->validRegistration(['sector' => 'food']))->assertSessionDoesntHaveErrors('sector');
    });
});

describe('établissement dans un secteur désactivé', function (): void {
    beforeEach(function (): void {
        ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['sector' => 'driving_school', 'name' => 'Auto-école Aurore']);
        ($this->deactivate)('driving_school');
        $this->save = fn (string $sector) => $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', [
            'name' => 'Auto-école Aurore', 'sector' => $sector, 'country' => 'BE', 'timezone' => 'Europe/Brussels', 'locale' => 'fr',
        ]);
    });

    it('voit toujours son secteur dans son profil', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/reglages/profil')->assertInertia(fn (Assert $page) => $page
            ->where('company.sector', 'driving_school')
            ->where('sectors', fn ($options) => in_array('driving_school', array_column($options->toArray(), 'value'), true)));
    });

    it('peut le garder en modifiant autre chose', function (): void {
        ($this->save)('driving_school')->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->sector))->toBe('driving_school');
    });

    it('peut le quitter pour un secteur actif, mais pas en choisir un autre désactivé', function (): void {
        ($this->deactivate)('garage');

        ($this->save)('garage')->assertSessionHasErrors('sector');
        ($this->save)('beauty')->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->sector))->toBe('beauty');
    });
});
