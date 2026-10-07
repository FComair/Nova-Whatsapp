<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Statut retrouvé quand une suspension ou une suppression programmée est levée : celui d'avant, sauf s'il est absurde.
 * Un essai échu redevient « actif » ; la suite (paiement) relève de la facturation.
 */
final class StatusRestorer
{
    public static function after(?string $previous, mixed $trialEndsAt): string
    {
        if ($previous === Organization::STATUS_TRIAL && ($trialEndsAt === null || CarbonImmutable::parse($trialEndsAt)->isPast())) {
            return Organization::STATUS_ACTIVE;
        }

        return in_array($previous, [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_PAUSED], true)
            ? (string) $previous
            : Organization::STATUS_ACTIVE;
    }

    /**
     * Statut retrouvé quand une SUPPRESSION programmée est annulée : celui d'avant, y compris « suspendu » (annuler une suppression
     * ne doit jamais lever une suspension).
     */
    public static function afterDeletion(?string $previous, mixed $trialEndsAt): string
    {
        return $previous === Organization::STATUS_SUSPENDED ? Organization::STATUS_SUSPENDED : self::after($previous, $trialEndsAt);
    }
}
