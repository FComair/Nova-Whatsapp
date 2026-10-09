<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\PlanLimits;
use Illuminate\Support\Collection;

/**
 * Choix de la formule à l'inscription (docs/10). Tout vient de la configuration existante : formules actives de la table `plans` (nom, prix
 * mensuel hors TVA, devise, limites), durée de l'essai (`platform_settings.trial_days`), taux de TVA (`config('nova.billing.vat')`) et
 * formule recommandée SI la plateforme en a désigné une. Rien n'est écrit en dur côté écran.
 *
 * Règles de facturation inchangées : l'inscription ne demande jamais de moyen de paiement et ne crée jamais d'abonnement ; elle enregistre
 * la formule choisie (`organizations.signup_plan`) et ouvre l'essai avec les limites de cette formule. L'abonnement payant ne naît que
 * quand le propriétaire le confirme depuis la page Facturation.
 */
final class SignupPlans
{
    /** Code du choix « essai gratuit » (aucune formule payante retenue). */
    public const TRIAL = 'trial';

    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * Codes acceptés : l'essai, et chaque formule payante active.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return [self::TRIAL, ...$this->plans()->pluck('code')->map(fn ($code): string => (string) $code)->all()];
    }

    /**
     * Ce que l'écran d'inscription affiche et compare.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $plans = $this->plans();
        $fallback = Plan::query()->where('code', PlanLimits::TRIAL_FALLBACK_PLAN)->first();

        return [
            'trial' => [
                'days' => $this->settings->integer('trial_days'),
                // Pendant un essai sans formule choisie, l'établissement dispose des limites de la formule de repli (voir PlanLimits).
                'limits' => $fallback === null ? null : $this->limits($fallback),
                'based_on' => $fallback?->name,
                'payment_method_required' => false,
            ],
            'plans' => $plans->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'price_cents' => $plan->price_monthly_cents,
                'currency' => trim($plan->currency),
                'limits' => $this->limits($plan),
                'recommended' => $plan->is_recommended,
            ])->values()->all(),
            'vat' => ['rate' => (int) config('nova.billing.vat.home_rate'), 'country' => (string) config('nova.billing.vat.home_country')],
        ];
    }

    /**
     * @return array{practitioners: int|null, locations: int|null, conversations: int|null, appointments: int|null}
     */
    private function limits(Plan $plan): array
    {
        $limits = $plan->limits;

        return [
            'practitioners' => isset($limits['practitioners']) ? (int) $limits['practitioners'] : null,
            'locations' => isset($limits['locations']) ? (int) $limits['locations'] : null,
            'conversations' => isset($limits['conversations']) ? (int) $limits['conversations'] : null,
            'appointments' => isset($limits['appointments']) ? (int) $limits['appointments'] : null,
        ];
    }

    /**
     * @return Collection<int, Plan>
     */
    private function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('position')->get();
    }
}
