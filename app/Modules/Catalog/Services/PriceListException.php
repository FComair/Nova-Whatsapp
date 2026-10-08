<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use RuntimeException;

/** Échec d'un import de liste de prix ; le code désigne le message à montrer (unavailable, bad_image, unreadable, no_prices, not_open, failed). */
final class PriceListException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $page = null)
    {
        parent::__construct($reason);
    }
}
