<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\PlanLimits;
use Illuminate\Support\Collection;

/**
 * Choix de la formule à l'inscription (docs/10). Tout vient de la configuration existante : formules actives de la table `plans` (nom, prix
 * mensuel hors TVA, devise, limites), durée de l'essai (`platform_settings.trial_days`), taux de TVA (`config('rendivoo.billing.vat')`) et
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

    public function __construct(private readonly PlatformSettings $settings, private readonly PlanCatalog $catalog) {}

    /**
     * Codes acceptés : l'essai, et chaque formule payante que la personne peut voir (publique, ou ouverte par son lien, son code d'invitation
     * ou son secteur).
     *
     * @return list<string>
     */
    public function codes(?string $invite = null, ?string $sector = null, ?string $direct = null): array
    {
        // Un lien direct vers une formule privée (?plan=code) l'ouvre, même si elle n'est pas listée.
        return [self::TRIAL, ...$this->plans($invite, $sector, $direct)->pluck('code')->map(fn ($code): string => (string) $code)->all()];
    }

    /**
     * Ce que l'écran d'inscription affiche et compare.
     *
     * @return array<string, mixed>
     */
    public function options(?string $invite = null, ?string $sector = null, ?string $direct = null): array
    {
        $plans = $this->plans($invite, $sector, $direct);
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
                ...$this->catalog->present($plan, app()->getLocale()),
                'limits' => $this->limits($plan),
            ])->values()->all(),
            'vat' => ['rate' => (int) config('rendivoo.billing.vat.home_rate'), 'country' => (string) config('rendivoo.billing.vat.home_country')],
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
    private function plans(?string $invite = null, ?string $sector = null, ?string $direct = null): Collection
    {
        // Seules les formules qu'on peut souscrire en ligne se choisissent à l'inscription (les autres : voir la page tarifs).
        return $this->catalog->visibleTo($sector, null, $invite, $direct)->filter(fn (Plan $plan): bool => $plan->purchasable())->values();
    }
}
