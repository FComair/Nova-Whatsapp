<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\PriceListMatcher;

/**
 * Rapprochement des lignes d'une liste de prix avec les services existants.
 */
beforeEach(function (): void {
    $this->matcher = new PriceListMatcher;
    $this->services = [
        ['id' => 'a', 'name' => 'Haircut'],
        ['id' => 'b', 'name' => 'Beard trim'],
        ['id' => 'c', 'name' => 'Coupe + brushing'],
    ];
    $this->line = fn (string $name, ?string $ignored = null): array => ['key' => 'l', 'name' => $name, 'ignored_reason' => $ignored];
});

it('normalise sans casse, accents, ponctuation ni mots de liaison', function (): void {
    expect($this->matcher->normalize('  Coupe de l\'Homme  '))->toBe('coupe l homme')
        ->and($this->matcher->normalize('BRUSHING - Été!'))->toBe('brushing ete')
        ->and($this->matcher->normalize('The'))->toBe('the');
});

it('met à jour un service quand les noms sont identiques après normalisation ou très proches', function (string $name, string $service): void {
    $lines = $this->matcher->match([($this->line)($name)], $this->services);

    expect($lines[0]['action'])->toBe('update')->and($lines[0]['match']['name'])->toBe($service)->and($lines[0]['suggestion'])->toBeNull();
})->with([
    'identique' => ['Haircut', 'Haircut'],
    'casse et ponctuation' => ['HAIRCUT!', 'Haircut'],
    'accent et espace' => ['Beard  Trim', 'Beard trim'],
    'petite faute' => ['Beard trimm', 'Beard trim'],
]);

it('se contente de proposer un service proche, sans rien appliquer (70 à 89 %)', function (): void {
    $lines = $this->matcher->match([($this->line)('Coupe brushing long')], $this->services);

    expect($lines[0]['action'])->toBe('new')
        ->and($lines[0]['match'])->toBeNull()
        ->and($lines[0]['suggestion']['name'])->toBe('Coupe + brushing')
        ->and($lines[0]['suggestion']['score'])->toBeGreaterThanOrEqual(0.7)->toBeLessThan(0.9);
});

it('laisse en nouveau service ce qui ne ressemble à rien', function (): void {
    $lines = $this->matcher->match([($this->line)('Massage relaxant')], $this->services);

    expect($lines[0]['action'])->toBe('new')->and($lines[0]['match'])->toBeNull()->and($lines[0]['suggestion'])->toBeNull();
});

it('ne rapproche un service que d\'une seule ligne : la plus ressemblante', function (): void {
    $lines = $this->matcher->match([($this->line)('Haircutt'), ($this->line)('Haircut')], $this->services);

    expect($lines[1]['action'])->toBe('update')->and($lines[1]['match']['service_id'])->toBe('a')
        ->and($lines[0]['action'])->toBe('new')->and($lines[0]['match'])->toBeNull();
});

it('ignore les lignes que la lecture a écartées, même si elles ressemblent à un service', function (): void {
    $lines = $this->matcher->match([($this->line)('Haircut', 'no_price')], $this->services);

    expect($lines[0]['action'])->toBe('ignore');
});

it('mesure la ressemblance entre 0 et 1', function (): void {
    expect($this->matcher->similarity('Haircut', 'haircut'))->toBe(1.0)
        ->and($this->matcher->similarity('Haircut', 'Massage'))->toBeLessThan(0.5)
        ->and($this->matcher->similarity('', 'Haircut'))->toBe(0.0);
});
