<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Plan;
use App\Modules\Tenancy\Models\Organization;

/**
 * Limites incluses dans l'offre d'une organisation (flowcharts 1C et 3A/3C, erreur E8).
 *
 * Offre retenue : celle de l'abonnement (`plan_id`) ; à défaut celle choisie à l'inscription ; pour un essai sans choix,
 * l'offre **Business** (HYPOTHÈSE : l'essai permet de tester toutes les fonctions, à valider).
 */
final class PlanLimits
{
    public const TRIAL_FALLBACK_PLAN = 'business';

    public function planFor(Organization $organization): Plan
    {
        if ($organization->plan_id !== null) {
            return Plan::query()->findOrFail($organization->plan_id);
        }

        $code = in_array($organization->signup_plan, ['basic', 'business'], true) ? $organization->signup_plan : self::TRIAL_FALLBACK_PLAN;

        return Plan::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  'practitioners'|'locations'|'conversations'|'appointments'  $key
     * @return int|null null = illimité
     */
    public function limit(Organization $organization, string $key): ?int
    {
        $value = $this->planFor($organization)->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
