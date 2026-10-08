<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Contracts\PriceListReader;

/**
 * Lecteur de démonstration (PRICELIST_DRIVER=fake) : renvoie toujours la même liste d'exemple, sans appel externe ni clé.
 * Sert au développement et aux essais de l'écran de vérification ; jamais utilisé en production.
 */
final class SamplePriceListReader implements PriceListReader
{
    public function available(): bool
    {
        return true;
    }

    public function read(array $images, string $locale): array
    {
        return [
            ['name' => 'Coupe femme', 'price' => 38, 'category' => 'Cheveux', 'duration_minutes' => 45, 'confidence' => 0.96],
            ['name' => 'Coupe homme', 'price' => 28, 'category' => 'Cheveux', 'confidence' => 0.95],
            ['name' => 'Taille de barbe', 'price' => 17, 'category' => 'Barbe', 'confidence' => 0.93],
            ['name' => 'Coupe + barbe', 'price' => 42, 'category' => 'Cheveux', 'confidence' => 0.9],
            ['name' => 'Couleur', 'price' => 0.55, 'category' => 'Couleur', 'confidence' => 0.55],
            ['name' => 'Shampoing', 'price' => 0, 'category' => 'Cheveux', 'confidence' => 0.9],
            ['name' => 'Mèches', 'price' => 85, 'category' => 'Couleur', 'confidence' => 0.92],
            ['name' => 'Brushing', 'price' => 20, 'category' => 'Cheveux', 'unit' => 'par heure', 'confidence' => 0.9],
            ['name' => 'Bon cadeau disponible', 'price' => null, 'confidence' => 0.9],
        ];
    }
}
