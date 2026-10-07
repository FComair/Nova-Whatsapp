<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Requests\ServiceRequest;
use App\Modules\Catalog\Support\SectorTemplates;

/**
 * Cohérence des modèles sectoriels : chaque modèle doit produire des services valides dans les trois langues.
 */
it('fournit un modèle pour chaque secteur sauf « autre »', function (): void {
    foreach (config('nova.sectors') as $sector) {
        expect(SectorTemplates::has($sector))->toBe($sector !== 'other', "Secteur : {$sector}");
    }
});

it('ne définit que des valeurs valides', function (): void {
    foreach (config('sector_templates') as $sector => $template) {
        foreach ($template['services'] as $key => $service) {
            expect($service['duration'])->toBeBetween(5, 480, "{$sector}.{$key}")
                ->and($service['price'])->toBeInt()->toBeGreaterThanOrEqual(0)
                ->and($service['vat'])->toBeIn(ServiceRequest::VAT_RATES)
                ->and($template['categories'])->toContain($service['category']);
        }
    }
});

it('est traduit dans les trois langues, sans clé manquante', function (string $locale): void {
    foreach (config('sector_templates') as $sector => $template) {
        foreach ($template['categories'] as $category) {
            $key = "sector_templates.{$sector}.categories.{$category}";
            expect(__($key, [], $locale))->not->toBe($key, "Manquant ({$locale}) : {$key}");
        }

        foreach (array_keys($template['services']) as $service) {
            $key = "sector_templates.{$sector}.services.{$service}";
            expect(__($key, [], $locale))->not->toBe($key, "Manquant ({$locale}) : {$key}");
        }
    }
})->with(['fr', 'nl', 'en']);

it('renvoie les services localisés', function (): void {
    $services = SectorTemplates::services('hairdressing', 'nl');

    expect($services)->toHaveCount(7)
        ->and($services[0])->toMatchArray(['key' => 'cut_woman', 'name' => 'Dameskapsel', 'category' => 'Knippen en stylen', 'duration' => 45, 'price' => 4000]);
});

it('renvoie une liste vide pour un secteur sans modèle', function (): void {
    expect(SectorTemplates::services('other', 'fr'))->toBe([])->and(SectorTemplates::services('inconnu', 'fr'))->toBe([]);
});
