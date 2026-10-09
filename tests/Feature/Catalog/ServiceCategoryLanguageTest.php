<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Catégories de services par langue : chaque catégorie a une langue, la liste proposée ne montre que celle de l'interface,
 * un même nom peut exister dans deux langues, et modifier un service ne déplace jamais sa catégorie.
 */
function categoryService(array $override = []): array
{
    return array_merge([
        'name' => 'Coupe', 'category' => 'Coupe', 'description' => '', 'duration_minutes' => 30, 'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0, 'price' => '30', 'vat_rate' => 21, 'capacity' => 1, 'bookable_online' => true, 'notes_required' => false,
    ], $override);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['sector' => 'hairdressing', 'locale' => 'fr'], onboarded: false);
    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->ui = fn (string $locale) => $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());
    $this->categories = fn (): array => $this->asOwner(fn () => ServiceCategory::query()->orderBy('locale')->orderBy('name')->get()->map(fn ($c): string => "{$c->locale}:{$c->name}")->all());
});

it('range la catégorie créée dans la langue de l\'interface', function (): void {
    ($this->as)()->post('/configuration/services', categoryService(['category' => 'Coupe']))->assertSessionHasNoErrors();
    ($this->ui)('en');
    ($this->as)()->post('/configuration/services', categoryService(['name' => 'Cut', 'category' => 'Cutting']))->assertSessionHasNoErrors();
    ($this->ui)('nl');
    ($this->as)()->post('/configuration/services', categoryService(['name' => 'Knippen', 'category' => 'Knippen']))->assertSessionHasNoErrors();

    expect(($this->categories)())->toBe(['en:Cutting', 'fr:Coupe', 'nl:Knippen']);
});

it('ne propose que les catégories de la langue de l\'interface, jamais un mélange', function (): void {
    $this->asOwner(function (): void {
        foreach ([['fr', 'Cheveux'], ['fr', 'Barbe'], ['en', 'Hair'], ['en', 'Beard'], ['nl', 'Haar']] as $position => [$locale, $name]) {
            ServiceCategory::query()->create(['org_id' => $this->org->id, 'locale' => $locale, 'name' => $name, 'position' => $position + 1]);
        }
    });

    foreach (['fr' => ['Cheveux', 'Barbe'], 'en' => ['Hair', 'Beard'], 'nl' => ['Haar']] as $locale => $expected) {
        ($this->ui)($locale);

        ($this->as)()->get('/configuration/assistant/services')->assertInertia(fn (Assert $page) => $page->where('categories', $expected));
    }
});

it('accepte le même nom dans deux langues mais pas deux fois dans la même', function (): void {
    ($this->as)()->post('/configuration/services', categoryService(['category' => 'Barber']));
    ($this->ui)('nl');
    ($this->as)()->post('/configuration/services', categoryService(['name' => 'Knippen', 'category' => 'Barber']));

    expect(($this->categories)())->toBe(['fr:Barber', 'nl:Barber']);

    expect(fn () => $this->asOwner(function (): void {
        ServiceCategory::query()->create(['org_id' => $this->org->id, 'locale' => 'nl', 'name' => 'BARBER']);
    }))->toThrow(QueryException::class, 'service_categories_name_unique');
});

it('retrouve une catégorie existante sans tenir compte de la casse, dans la langue courante', function (): void {
    ($this->as)()->post('/configuration/services', categoryService(['category' => 'Coupe']));
    ($this->as)()->post('/configuration/services', categoryService(['name' => 'Coupe homme', 'category' => '  coupe ']));

    expect(($this->categories)())->toBe(['fr:Coupe']);
});

it('ne déplace pas un service dans une autre catégorie quand on le modifie depuis une autre langue', function (): void {
    ($this->as)()->post('/configuration/services', categoryService(['name' => 'Coupe femme', 'category' => 'Coupe']));
    $service = $this->asOwner(fn () => Service::query()->firstOrFail());
    $categoryId = $service->category_id;

    ($this->ui)('en');
    ($this->as)()->put("/configuration/services/{$service->id}", categoryService(['name' => 'Coupe femme (modifiée)', 'category' => 'Coupe', 'price' => '35']))->assertSessionHasNoErrors();

    expect($this->asOwner(fn () => Service::query()->findOrFail($service->id)))->category_id->toBe($categoryId)->price_cents->toBe(3500);
    expect(($this->categories)())->toBe(['fr:Coupe']);
});

it('range les catégories d\'un modèle sectoriel dans la langue de l\'entreprise', function (): void {
    $this->asOwner(fn () => $this->org->forceFill(['locale' => 'nl'])->save());

    ($this->as)()->post('/configuration/services/modele')->assertSessionHasNoErrors();

    $locales = $this->asOwner(fn () => ServiceCategory::query()->pluck('locale')->unique()->values()->all());
    expect($locales)->toBe(['nl']);
});
