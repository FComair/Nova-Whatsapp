<?php

declare(strict_types=1);

use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\DefaultSectors;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Étape 1 : entreprise et fuseau horaire.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(
        ['name' => 'nouveau', 'sector' => 'beauty', 'timezone' => 'Europe/Brussels', 'locale' => 'fr'],
        onboarded: false,
    );

    $this->valid = fn (array $override = []) => array_merge([
        'name' => 'Salon Aurore', 'sector' => 'hairdressing', 'country' => 'BE', 'timezone' => 'Europe/Brussels', 'locale' => 'nl',
    ], $override);
});

it('affiche les informations actuelles avec les listes de choix', function (): void {
    $this->signedIn($this->owner, $this->org)->get('/configuration/assistant/entreprise')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Onboarding/Company')
        ->where('company.name', 'nouveau')
        ->where('company.timezone', 'Europe/Brussels')
        ->has('sectors', count(DefaultSectors::rows()))
        ->where('countries', ['BE', 'FR', 'NL', 'LU', 'DE'])
        ->where('wizard.step', 'company')
        ->has('wizard.steps', 9)
        ->has('timezones'));
});

it('enregistre l\'entreprise, marque l\'étape faite et passe aux sites', function (): void {
    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)())
        ->assertRedirect('/configuration/assistant/sites');

    $org = $this->asOwner(fn () => Organization::query()->find($this->org->id));
    expect($org->name)->toBe('Salon Aurore')
        ->and($org->sector)->toBe('hairdressing')
        ->and($org->locale)->toBe('nl');

    expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('company')))->toBeTrue();
});

it('supprime les espaces autour du nom', function (): void {
    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)(['name' => '   Salon Aurore  ']));

    expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('Salon Aurore');
});

it('trace les modifications dans le journal d\'audit avec l\'ancienne et la nouvelle valeur', function (): void {
    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)());

    $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.company.updated')->where('org_id', $this->org->id)->first());

    expect($log)->not->toBeNull();
    $diff = json_decode($log->diff, true);
    expect($diff['name'])->toBe(['nouveau', 'Salon Aurore'])
        ->and($diff['sector'])->toBe(['beauty', 'hairdressing'])
        ->and($diff)->not->toHaveKey('timezone');
});

it('n\'écrit pas d\'entrée d\'audit quand rien ne change', function (): void {
    $this->asOwner(fn () => Organization::query()->find($this->org->id)->update(['name' => 'Salon Aurore', 'sector' => 'hairdressing', 'locale' => 'nl']));

    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)());

    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.company.updated')->count()))->toBe(0);
});

it('répercute le nouveau fuseau sur les sites qui suivaient celui de l\'entreprise', function (): void {
    $this->asOwner(function (): void {
        Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Suit l\'entreprise', 'timezone' => 'Europe/Brussels']);
        Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Fuseau propre', 'timezone' => 'Europe/London']);
    });

    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)(['timezone' => 'Europe/Paris']));

    $zones = $this->asOwner(fn () => DB::table('locations')->where('org_id', $this->org->id)->pluck('timezone', 'name')->all());
    expect($zones["Suit l'entreprise"])->toBe('Europe/Paris')
        ->and($zones['Fuseau propre'])->toBe('Europe/London');
});

it('refuse les données invalides', function (array $override, string $field): void {
    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)($override))->assertSessionHasErrors($field);

    expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('nouveau');
})->with([
    'nom vide' => [['name' => ''], 'name'],
    'nom trop court' => [['name' => 'A'], 'name'],
    'nom trop long' => [['name' => str_repeat('a', 121)], 'name'],
    'secteur inconnu' => [['sector' => 'pirate'], 'sector'],
    'pays inconnu' => [['country' => 'US'], 'country'],
    'fuseau inexistant' => [['timezone' => 'Mars/Phobos'], 'timezone'],
    'fuseau hors Europe' => [['timezone' => 'America/New_York'], 'timezone'],
    'langue inconnue' => [['locale' => 'de'], 'locale'],
]);

it('ne modifie pas l\'organisation d\'un autre client', function (): void {
    ['org' => $other] = $this->activatedAccount(['name' => 'Concurrent'], email: 'autre@example.com');

    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)());

    expect($this->asOwner(fn () => Organization::query()->find($other->id)->name))->toBe('Concurrent');
});

it('refuse toute modification quand le compte est suspendu (lecture seule)', function (): void {
    $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

    $this->signedIn($this->owner, $this->org)->post('/configuration/assistant/entreprise', ($this->valid)())->assertRedirect('/compte');

    expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('nouveau');
});
