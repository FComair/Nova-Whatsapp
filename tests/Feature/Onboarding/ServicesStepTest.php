<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Onboarding\Models\OnboardingProgress;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Étape 4 : catalogue de services, avec modèles sectoriels (flowchart 1B).
 */
function validService(array $override = []): array
{
    return array_merge([
        'name' => 'Coupe femme', 'category' => 'Coupe', 'description' => 'Shampoing inclus', 'duration_minutes' => 45,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 5, 'price' => '40,50', 'vat_rate' => 21, 'capacity' => 1,
        'bookable_online' => true, 'notes_required' => false,
    ], $override);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['sector' => 'hairdressing', 'locale' => 'fr'], onboarded: false);
    $this->as = fn () => $this->signedIn($this->owner, $this->org);
});

describe('page et modèles', function (): void {
    it('propose le modèle du secteur quand le catalogue est vide', function (): void {
        ($this->as)()->get('/configuration/assistant/services')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Services')
            ->where('services', [])
            ->where('template.sector', 'hairdressing')
            ->where('template.count', 7)
            ->where('vatRates', [0, 6, 12, 21]));
    });

    it('ne propose pas de modèle pour le secteur « autre »', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['sector' => 'other'])->save());

        ($this->as)()->get('/configuration/assistant/services')->assertInertia(fn (Assert $page) => $page->where('template', null));
    });

    it('applique le modèle en créant catégories et services dans la langue de l\'entreprise', function (): void {
        ($this->as)()->post('/configuration/services/modele')->assertSessionHasNoErrors();

        $services = $this->asOwner(fn () => Service::query()->where('org_id', $this->org->id)->orderBy('position')->get());
        expect($services->pluck('name')->all())->toBe(['Coupe femme', 'Coupe homme', 'Coupe enfant', 'Brushing', 'Coloration', 'Taille de barbe', 'Coupe et barbe'])
            ->and($services[0]->duration_minutes)->toBe(45)
            ->and($services[0]->price_cents)->toBe(4000)
            ->and($services[0]->vat_rate)->toBe(21)
            ->and($services[0]->bookable_online)->toBeTrue();

        $categories = $this->asOwner(fn () => ServiceCategory::query()->where('org_id', $this->org->id)->orderBy('position')->pluck('name')->all());
        expect($categories)->toBe(['Coupe et coiffage', 'Coloration', 'Barbe']);
    });

    it('utilise la langue de l\'entreprise pour les intitulés', function (string $locale, string $first): void {
        $this->asOwner(fn () => $this->org->forceFill(['locale' => $locale])->save());

        ($this->as)()->post('/configuration/services/modele');

        expect($this->asOwner(fn () => Service::query()->orderBy('position')->value('name')))->toBe($first);
    })->with([['fr', 'Coupe femme'], ['nl', 'Dameskapsel'], ['en', 'Women\'s cut']]);

    it('propose une TVA à 0 % pour les soins de santé', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['sector' => 'dental'])->save());

        ($this->as)()->post('/configuration/services/modele');

        expect($this->asOwner(fn () => Service::query()->pluck('vat_rate')->unique()->values()->all()))->toBe([0]);
    });

    it('refuse d\'appliquer le modèle sur un catalogue non vide, sans rien dupliquer', function (): void {
        ($this->as)()->post('/configuration/services', validService());

        ($this->as)()->post('/configuration/services/modele')->assertSessionHasErrors(['template' => 'Le modèle ne peut être appliqué que sur un catalogue vide.']);

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(1);
    });

    it('refuse un second clic sur « Utiliser le modèle »', function (): void {
        ($this->as)()->post('/configuration/services/modele');
        ($this->as)()->post('/configuration/services/modele')->assertSessionHasErrors('template');

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(7);
    });

    it('refuse le modèle quand le secteur n\'en a pas', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['sector' => 'other'])->save());

        ($this->as)()->post('/configuration/services/modele')->assertSessionHasErrors('template');
    });

    it('trace l\'application du modèle', function (): void {
        ($this->as)()->post('/configuration/services/modele');

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'services.template_applied')->first());
        expect(json_decode($log->diff, true))->toEqual(['sector' => 'hairdressing', 'count' => 7]);
    });
});

describe('création, modification, suppression', function (): void {
    it('crée un service avec un prix converti en centimes', function (): void {
        ($this->as)()->post('/configuration/services', validService())->assertSessionHasNoErrors();

        $service = $this->asOwner(fn () => Service::query()->firstOrFail());
        expect($service->name)->toBe('Coupe femme')
            ->and($service->price_cents)->toBe(4050)
            ->and($service->duration_minutes)->toBe(45)
            ->and($service->buffer_after_minutes)->toBe(5)
            ->and($service->currency ?? 'EUR')->toBe('EUR');
    });

    it('accepte un prix avec un point, avec une virgule, ou gratuit', function (string $price, int $cents): void {
        ($this->as)()->post('/configuration/services', validService(['price' => $price]))->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Service::query()->value('price_cents')))->toBe($cents);
    })->with([['40.5', 4050], ['40,50', 4050], ['0', 0], ['1 200', 120000]]);

    it('réutilise une catégorie existante sans tenir compte de la casse', function (): void {
        ($this->as)()->post('/configuration/services', validService(['category' => 'Coupe']));
        ($this->as)()->post('/configuration/services', validService(['name' => 'Coupe homme', 'category' => '  coupe ']));

        expect($this->asOwner(fn () => ServiceCategory::query()->count()))->toBe(1)
            ->and($this->asOwner(fn () => Service::query()->pluck('category_id')->unique()->count()))->toBe(1);
    });

    it('accepte un service sans catégorie ni description', function (): void {
        ($this->as)()->post('/configuration/services', validService(['category' => '', 'description' => '']))->assertSessionHasNoErrors();

        $service = $this->asOwner(fn () => Service::query()->firstOrFail());
        expect($service->category_id)->toBeNull()->and($service->description)->toBeNull();
    });

    it('numérote les services à la suite', function (): void {
        ($this->as)()->post('/configuration/services', validService(['name' => 'Premier']));
        ($this->as)()->post('/configuration/services', validService(['name' => 'Second']));

        expect($this->asOwner(fn () => Service::query()->orderBy('position')->pluck('name', 'position')->all()))->toBe([1 => 'Premier', 2 => 'Second']);
    });

    it('modifie un service et trace uniquement les champs changés', function (): void {
        ($this->as)()->post('/configuration/services', validService());
        $service = $this->asOwner(fn () => Service::query()->firstOrFail());

        ($this->as)()->put("/configuration/services/{$service->id}", validService(['price' => '45', 'duration_minutes' => 60]))->assertSessionHasNoErrors();

        $fresh = $this->asOwner(fn () => Service::query()->find($service->id));
        expect($fresh->price_cents)->toBe(4500)->and($fresh->duration_minutes)->toBe(60);

        $diff = json_decode($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'service.updated')->value('diff')), true);
        expect(array_keys($diff))->toEqualCanonicalizing(['price_cents', 'duration_minutes'])
            ->and($diff['price_cents'])->toBe([4050, 4500]);
    });

    it('n\'écrit pas d\'audit quand rien ne change', function (): void {
        ($this->as)()->post('/configuration/services', validService());
        $service = $this->asOwner(fn () => Service::query()->firstOrFail());

        ($this->as)()->put("/configuration/services/{$service->id}", validService());

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'service.updated')->count()))->toBe(0);
    });

    it('supprime un service de façon logique et le trace', function (): void {
        ($this->as)()->post('/configuration/services', validService());
        $service = $this->asOwner(fn () => Service::query()->firstOrFail());

        ($this->as)()->delete("/configuration/services/{$service->id}")->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(0)
            ->and($this->asOwner(fn () => DB::table('services')->where('id', $service->id)->value('deleted_at')))->not->toBeNull()
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'service.deleted')->count()))->toBe(1);
    });

    it('refuse les données invalides', function (array $override, string $field): void {
        ($this->as)()->post('/configuration/services', validService($override))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(0);
    })->with([
        'nom vide' => [['name' => ''], 'name'],
        'nom trop court' => [['name' => 'X'], 'name'],
        'durée trop courte' => [['duration_minutes' => 4], 'duration_minutes'],
        'durée trop longue' => [['duration_minutes' => 481], 'duration_minutes'],
        'durée non numérique' => [['duration_minutes' => 'abc'], 'duration_minutes'],
        'marge avant excessive' => [['buffer_before_minutes' => 121], 'buffer_before_minutes'],
        'marge après négative' => [['buffer_after_minutes' => -1], 'buffer_after_minutes'],
        'prix vide' => [['price' => ''], 'price'],
        'prix non numérique' => [['price' => 'abc'], 'price'],
        'prix négatif' => [['price' => '-5'], 'price'],
        'prix à trois décimales' => [['price' => '40,555'], 'price'],
        'TVA négative' => [['vat_rate' => -1], 'vat_rate'],
        'TVA supérieure à 100' => [['vat_rate' => 101], 'vat_rate'],
        'TVA non numérique' => [['vat_rate' => 'abc'], 'vat_rate'],
        'TVA à trois décimales' => [['vat_rate' => '21,555'], 'vat_rate'],
        'TVA vide' => [['vat_rate' => ''], 'vat_rate'],
        'capacité nulle' => [['capacity' => 0], 'capacity'],
        'capacité excessive' => [['capacity' => 51], 'capacity'],
        'description trop longue' => [['description' => str_repeat('a', 1001)], 'description'],
        'catégorie trop longue' => [['category' => str_repeat('a', 121)], 'category'],
    ]);

    it('affiche le message de prix invalide dans la langue de l\'interface', function (string $locale, string $message): void {
        // La langue du compte prime sur celle de la session.
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->as)()->post('/configuration/services', validService(['price' => 'abc']))->assertSessionHasErrors(['price' => $message]);
    })->with([
        ['fr', 'Saisissez un montant valide, par exemple 40 ou 40,50.'],
        ['nl', 'Voer een geldig bedrag in, bijvoorbeeld 40 of 40,50.'],
        ['en', 'Enter a valid amount, for example 40 or 40.50.'],
    ]);
});

describe('fin de l\'étape', function (): void {
    it('exige au moins un service', function (): void {
        ($this->as)()->post('/configuration/assistant/services/continuer')->assertSessionHasErrors(['service' => 'Ajoutez au moins un service pour continuer.']);

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('services')))->toBeFalse();
    });

    it('marque l\'étape faite et ouvre la suivante', function (): void {
        ($this->as)()->post('/configuration/services', validService());

        ($this->as)()->post('/configuration/assistant/services/continuer')->assertRedirect('/configuration/assistant/praticiens');

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('services')))->toBeTrue();
    });

    it('n\'est pas satisfaite par un service supprimé', function (): void {
        ($this->as)()->post('/configuration/services', validService());
        $service = $this->asOwner(fn () => Service::query()->firstOrFail());
        ($this->as)()->delete("/configuration/services/{$service->id}");

        ($this->as)()->post('/configuration/assistant/services/continuer')->assertSessionHasErrors('service');
    });
});

describe('rôles', function (): void {
    it('autorise le responsable de site à gérer les services', function (): void {
        ['user' => $manager, 'org' => $org] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $org)->post('/configuration/services', validService())->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Service::query()->where('org_id', $org->id)->count()))->toBe(1);
    });

    it('refuse les praticiens et réceptionnistes', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);
        $this->signedIn($user, $org);

        $this->post('/configuration/services', validService())->assertForbidden();
        $this->post('/configuration/services/modele')->assertForbidden();
        expect($this->asOwner(fn () => Service::query()->count()))->toBe(0);
    })->with(['practitioner', 'receptionist']);

    it('réserve la fin de l\'assistant au propriétaire', function (): void {
        ['user' => $manager, 'org' => $org] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $org)->post('/configuration/assistant/services/continuer')->assertForbidden();
    });

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->as)()->post('/configuration/services', validService())->assertRedirect('/compte');

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(0);
    });
});

describe('isolation entre clients et garanties de la base', function (): void {
    beforeEach(function (): void {
        ['user' => $this->otherOwner, 'org' => $this->otherOrg] = $this->activatedAccount(['sector' => 'garage'], email: 'autre@example.com');
        $this->signedIn($this->otherOwner, $this->otherOrg)->post('/configuration/services', validService(['name' => 'Vidange', 'category' => 'Entretien']));
        $this->foreign = $this->asOwner(fn () => Service::query()->where('org_id', $this->otherOrg->id)->firstOrFail());
    });

    it('ne liste jamais les services d\'un autre client', function (): void {
        ($this->as)()->post('/configuration/services', validService(['name' => 'Le mien']));

        ($this->as)()->get('/configuration/assistant/services')->assertInertia(fn (Assert $page) => $page
            ->has('services', 1)
            ->where('services.0.name', 'Le mien')
            ->where('categories', ['Coupe']));
    });

    it('répond 404 pour modifier ou supprimer le service d\'un autre client', function (): void {
        ($this->as)()->put("/configuration/services/{$this->foreign->id}", validService(['name' => 'Piraté']))->assertNotFound();
        ($this->as)()->delete("/configuration/services/{$this->foreign->id}")->assertNotFound();

        expect($this->asOwner(fn () => Service::query()->find($this->foreign->id)->name))->toBe('Vidange');
    });

    it('permet deux organisations avec des catégories de même nom', function (): void {
        ($this->as)()->post('/configuration/services', validService(['category' => 'Entretien']));

        expect($this->asOwner(fn () => ServiceCategory::query()->where('name', 'Entretien')->count()))->toBe(2);
    });

    it('interdit en base deux catégories de même nom dans une organisation', function (): void {
        $this->asOwner(function (): void {
            ServiceCategory::query()->create(['org_id' => $this->org->id, 'name' => 'Soins']);
            ServiceCategory::query()->create(['org_id' => $this->org->id, 'name' => 'SOINS']);
        });
    })->throws(QueryException::class, 'service_categories_name_unique');

    it('refuse en base des valeurs hors limites', function (array $override, string $constraint): void {
        $this->asOwner(fn () => Service::query()->create(array_merge(
            ['org_id' => $this->org->id, 'name' => 'Test', 'duration_minutes' => 30, 'price_cents' => 1000],
            $override,
        )));
    })->with([
        'durée' => [['duration_minutes' => 3], 'services_duration_check'],
        'TVA' => [['vat_rate' => 101], 'services_vat_check'],
        'capacité' => [['capacity' => 0], 'services_capacity_check'],
        'marge' => [['buffer_after_minutes' => 200], 'services_buffers_check'],
    ])->throws(QueryException::class);

    it('conserve les services quand leur catégorie est supprimée', function (): void {
        ($this->as)()->post('/configuration/services', validService());
        $category = $this->asOwner(fn () => ServiceCategory::query()->where('org_id', $this->org->id)->firstOrFail());

        $this->asOwner(fn () => DB::table('service_categories')->where('id', $category->id)->delete());

        $service = $this->asOwner(fn () => Service::query()->where('org_id', $this->org->id)->firstOrFail());
        expect($service->category_id)->toBeNull();
    });
});
