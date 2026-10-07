<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/** Résultat d'un débit : réussi (avec la référence du prestataire) ou refusé (avec un code stable, jamais un message libre). */
final readonly class ChargeResult
{
    private function __construct(public bool $succeeded, public ?string $providerRef, public ?string $failureCode) {}

    public static function succeeded(string $providerRef): self
    {
        return new self(true, $providerRef, null);
    }

    public static function failed(string $failureCode, ?string $providerRef = null): self
    {
        return new self(false, $providerRef, $failureCode);
    }
}
