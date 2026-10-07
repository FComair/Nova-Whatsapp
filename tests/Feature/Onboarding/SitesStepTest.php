<?php

declare(strict_types=1);

use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Models\Location;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Étape 2 : sites. Le site par défaut est créé à l'inscription (spec §9).
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['country' => 'BE'], onboarded: false);
    $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->update(['name' => 'Site principal']));
    $this->main = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->firstOrFail());

    $this->site = fn (array $override = []) => array_merge(['name' => 'Institut Nord', 'street' => 'Rue Neuve 12', 'postal_code' => '1000', 'city' => 'Bruxelles', 'country' => 'BE'], $override);
    $this->as = fn () => $this->signedIn($this->owner, $this->org);
});

it('liste les sites de l\'organisation avec le site principal', function (): void {
    ($this->as)()->get('/configuration/assistant/sites')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Onboarding/Sites')
        ->has('locations', 1)
        ->where('locations.0.name', 'Site principal')
        ->where('locations.0.is_default', true)
        ->where('defaultCountry', 'BE'));
});

describe('création et modification', function (): void {
    it('ajoute un site qui n\'est pas le site principal', function (): void {
        ($this->as)()->post('/configuration/assistant/sites', ($this->site)())->assertRedirect();

        $sites = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->orderBy('created_at')->get());
        expect($sites)->toHaveCount(2)
            ->and($sites[1]->name)->toBe('Institut Nord')
            ->and($sites[1]->is_default)->toBeFalse()
            ->and($sites[1]->address)->toEqual(['street' => 'Rue Neuve 12', 'postal_code' => '1000', 'city' => 'Bruxelles', 'country' => 'BE'])
            ->and($sites[1]->timezone)->toBe('Europe/Brussels');
    });

    it('accepte une adresse partielle', function (): void {
        ($this->as)()->post('/configuration/assistant/sites', ($this->site)(['street' => '', 'postal_code' => '', 'city' => '']))->assertSessionHasNoErrors();

        $address = $this->asOwner(fn () => Location::query()->where('name', 'Institut Nord')->value('address'));
        expect($address)->toEqual(['street' => null, 'postal_code' => null, 'city' => null, 'country' => 'BE']);
    });

    it('modifie le nom et l\'adresse d\'un site', function (): void {
        ($this->as)()->put("/configuration/assistant/sites/{$this->main->id}", ($this->site)(['name' => 'Salon Centre']))->assertRedirect();

        $site = $this->asOwner(fn () => Location::query()->find($this->main->id));
        expect($site->name)->toBe('Salon Centre')->and($site->address['city'])->toBe('Bruxelles');
    });

    it('trace la création, la modification et la suppression', function (): void {
        ($this->as)()->post('/configuration/assistant/sites', ($this->site)());
        ($this->as)()->put("/configuration/assistant/sites/{$this->main->id}", ($this->site)(['name' => 'Renommé']));

        $actions = $this->asOwner(fn () => DB::table('audit_logs')->where('org_id', $this->org->id)->pluck('action')->all());
        expect($actions)->toContain('location.created')->toContain('location.updated');
    });

    it('refuse les données invalides', function (array $override, string $field): void {
        ($this->as)()->post('/configuration/assistant/sites', ($this->site)($override))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->count()))->toBe(1);
    })->with([
        'nom vide' => [['name' => ''], 'name'],
        'nom trop court' => [['name' => 'X'], 'name'],
        'pays inconnu' => [['country' => 'JP'], 'country'],
        'rue trop longue' => [['street' => str_repeat('a', 161)], 'street'],
        'code postal trop long' => [['postal_code' => str_repeat('1', 13)], 'postal_code'],
    ]);
});

describe('suppression', function (): void {
    beforeEach(function (): void {
        ($this->as)()->post('/configuration/assistant/sites', ($this->site)());
        $this->second = $this->asOwner(fn () => Location::query()->where('name', 'Institut Nord')->firstOrFail());
    });

    it('supprime un site sans supprimer les autres', function (): void {
        ($this->as)()->delete("/configuration/assistant/sites/{$this->second->id}")->assertRedirect();

        expect($this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->pluck('name')->all()))->toBe(['Site principal']);
    });

    it('conserve la trace du site supprimé (suppression logique)', function (): void {
        ($this->as)()->delete("/configuration/assistant/sites/{$this->second->id}");

        expect($this->asOwner(fn () => DB::table('locations')->where('id', $this->second->id)->value('deleted_at')))->not->toBeNull();
    });

    it('désigne un nouveau site principal quand le principal est supprimé', function (): void {
        ($this->as)()->delete("/configuration/assistant/sites/{$this->main->id}")->assertRedirect();

        $remaining = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->get());
        expect($remaining)->toHaveCount(1)
            ->and($remaining[0]->name)->toBe('Institut Nord')
            ->and($remaining[0]->is_default)->toBeTrue();
    });

    it('refuse de supprimer le dernier site', function (): void {
        ($this->as)()->delete("/configuration/assistant/sites/{$this->second->id}");

        ($this->as)()->delete("/configuration/assistant/sites/{$this->main->id}")->assertSessionHasErrors(['site' => 'Vous devez garder au moins un site.']);

        expect($this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->count()))->toBe(1);
    });
});

describe('fin de l\'étape', function (): void {
    it('marque l\'étape faite et passe aux horaires', function (): void {
        ($this->as)()->post('/configuration/assistant/sites/continuer')->assertRedirect('/configuration/assistant/horaires');

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('locations')))->toBeTrue();
    });
});

describe('isolation entre clients', function (): void {
    beforeEach(function (): void {
        ['org' => $this->otherOrg] = $this->activatedAccount(email: 'autre@example.com');
        $this->foreign = $this->asOwner(fn () => Location::query()->where('org_id', $this->otherOrg->id)->firstOrFail());
    });

    it('ne liste jamais les sites d\'un autre client', function (): void {
        ($this->as)()->get('/configuration/assistant/sites')->assertInertia(fn (Assert $page) => $page
            ->has('locations', 1)
            ->where('locations.0.id', $this->main->id));
    });

    it('répond 404 pour modifier ou supprimer le site d\'un autre client', function (): void {
        ($this->as)()->put("/configuration/assistant/sites/{$this->foreign->id}", ($this->site)(['name' => 'Piraté']))->assertNotFound();
        ($this->as)()->delete("/configuration/assistant/sites/{$this->foreign->id}")->assertNotFound();

        expect($this->asOwner(fn () => Location::query()->find($this->foreign->id)->name))->not->toBe('Piraté');
    });
});
