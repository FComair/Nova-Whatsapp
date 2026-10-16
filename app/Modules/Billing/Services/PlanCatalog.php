<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Plan;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Ce que les clients voient des formules (docs/40) : page tarifs publique, choix à l'inscription, page Facturation. Une seule source,
 * donc ce que le Super Admin publie apparaît partout, et un brouillon, une formule archivée, remplacée ou hors de ses dates n'apparaît nulle part.
 *
 * Visibilité :
 *  - `public` : listée pour tout le monde ;
 *  - `private` : jamais listée ; ne se choisit qu'avec son lien direct (`?plan=code`) ;
 *  - `invite_only` : listée seulement avec son code d'invitation (`?invite=…`) ;
 *  - `segments` : listée seulement pour les secteurs et pays choisis.
 */
final class PlanCatalog
{
    /**
     * Formules de la page tarifs publique : publiques seulement, dans l'ordre d'affichage.
     *
     * @return Collection<int, Plan>
     */
    public function publicListing(): Collection
    {
        return Plan::query()->available()->where('visibility', 'public')->orderBy('position')->orderBy('created_at')->get();
    }

    /**
     * Formules que peut voir et choisir une personne : publiques, plus celles que son lien, son code d'invitation, son secteur ou son pays ouvrent.
     *
     * @return Collection<int, Plan>
     */
    public function visibleTo(?string $sector = null, ?string $country = null, ?string $invite = null, ?string $direct = null): Collection
    {
        return Plan::query()->available()->orderBy('position')->orderBy('created_at')->get()
            ->filter(fn (Plan $plan): bool => $this->allows($plan, $sector, $country, $invite, $direct))
            ->values();
    }

    /**
     * @return Collection<int, Plan>
     */
    public function forOrganization(Organization $organization, ?string $invite = null): Collection
    {
        return $this->visibleTo($organization->sector, $organization->country, $invite);
    }

    public function allows(Plan $plan, ?string $sector, ?string $country, ?string $invite, ?string $direct): bool
    {
        return match ($plan->visibility) {
            'public' => true,
            'private' => $direct !== null && hash_equals($plan->code, $direct),
            'invite_only' => $invite !== null && $plan->invite_code !== null && hash_equals($plan->invite_code, $invite),
            'segments' => ($sector !== null && in_array($sector, (array) ($plan->segments['sectors'] ?? []), true))
                || ($country !== null && in_array($country, (array) ($plan->segments['countries'] ?? []), true)),
            default => false,
        };
    }

    /**
     * Texte d'une formule tel qu'un écran client l'affiche, dans la langue demandée.
     *
     * @return array<string, mixed>
     */
    public function present(Plan $plan, string $locale): array
    {
        $limits = (array) $plan->limits;

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->publicDescription($locale),
            'type' => $plan->plan_type,
            'price_cents' => $plan->price_cents,
            'currency' => trim($plan->currency),
            'tax_mode' => $plan->tax_mode,
            'interval_months' => $plan->interval(),
            'frequency' => $plan->billing_frequency,
            'setup_fee_cents' => $plan->setup_fee_cents,
            'trial_days' => $plan->trial_days,
            'limits' => [
                'practitioners' => isset($limits['practitioners']) ? (int) $limits['practitioners'] : null,
                'locations' => isset($limits['locations']) ? (int) $limits['locations'] : null,
                'conversations' => isset($limits['conversations']) ? (int) $limits['conversations'] : null,
                'appointments' => isset($limits['appointments']) ? (int) $limits['appointments'] : null,
            ],
            'features' => collect((array) $plan->features)->map(fn (array $feature): array => [
                'label' => (string) (($feature['label'][$locale] ?? null) ?: ($feature['label'][(string) config('app.fallback_locale')] ?? '')),
                'state' => (string) ($feature['state'] ?? 'included'),
                'value' => filled($feature['value'] ?? null) ? (string) $feature['value'] : null,
            ])->filter(fn (array $feature): bool => $feature['label'] !== '')->values()->all(),
            'recommended' => $plan->is_recommended,
            'purchasable' => $plan->purchasable(),
            'cta' => ['type' => $plan->cta_type, 'url' => $plan->cta_url],
        ];
    }
}
