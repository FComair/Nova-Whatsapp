<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Tenancy\Models\Organization;

/**
 * TVA d'une facture d'abonnement. RÈGLES PROVISOIRES, à faire valider par un comptable :
 * - client établi en Belgique : taux belge (21 %) ;
 * - client professionnel d'un autre pays de l'UE avec un numéro de TVA : 0 % (autoliquidation) ;
 * - tout autre cas : taux belge.
 * La validité du numéro de TVA n'est pas vérifiée (VIES à ajouter).
 */
final class VatCalculator
{
    public function rateFor(Organization $organization): int
    {
        $home = (string) config('nova.billing.vat.home_country');
        $vatNumber = trim((string) $organization->billing_vat_number);

        if ($organization->country !== $home && $vatNumber !== '' && in_array($organization->country, (array) config('nova.billing.vat.eu_countries'), true)) {
            return 0;
        }

        return (int) config('nova.billing.vat.home_rate');
    }

    /**
     * @return array{subtotal: int, rate: int, vat: int, total: int}
     */
    public function compute(Organization $organization, int $subtotalCents): array
    {
        $rate = $this->rateFor($organization);
        $vat = (int) round($subtotalCents * $rate / 100);

        return ['subtotal' => $subtotalCents, 'rate' => $rate, 'vat' => $vat, 'total' => $subtotalCents + $vat];
    }
}
