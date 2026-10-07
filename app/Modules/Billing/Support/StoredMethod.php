<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/** Moyen de paiement enregistré chez le prestataire : jeton et signes distinctifs (jamais le numéro de carte). */
final readonly class StoredMethod
{
    public function __construct(
        public string $ref,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
    ) {}
}
