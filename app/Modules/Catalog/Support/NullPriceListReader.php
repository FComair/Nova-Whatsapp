<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Contracts\PriceListReader;

/** Utilisé quand aucune clé d'IA n'est configurée : l'import de liste de prix n'est pas disponible. */
final class NullPriceListReader implements PriceListReader
{
    public function available(): bool
    {
        return false;
    }

    public function read(array $images, string $locale): ?array
    {
        return null;
    }
}
