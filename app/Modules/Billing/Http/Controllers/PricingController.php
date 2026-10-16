<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page tarifs publique (docs/40), sans connexion : exactement les formules que le Super Admin a publiées (actives, dans leurs dates, de visibilité
 * « publique »), dans l'ordre qu'il a choisi. Un brouillon, une formule archivée, remplacée ou réservée à des segments n'y figure jamais.
 * Les mêmes données sont servies en JSON (`/tarifs.json`) pour le site vitrine.
 */
final class PricingController
{
    public function __construct(private readonly PlanCatalog $catalog, private readonly PlatformSettings $settings) {}

    public function show(): Response
    {
        return Inertia::render('Pricing', $this->payload());
    }

    public function json(): JsonResponse
    {
        return response()->json($this->payload())->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $locale = app()->getLocale();

        return [
            'plans' => $this->catalog->publicListing()->map(fn (Plan $plan): array => [...$this->catalog->present($plan, $locale), 'cta' => $this->cta($plan)])->values()->all(),
            'trial' => ['days' => $this->settings->integer('trial_days'), 'url' => '/inscription'],
            'vat' => ['rate' => (int) config('nova.billing.vat.home_rate'), 'country' => (string) config('nova.billing.vat.home_country')],
        ];
    }

    /**
     * Bouton de la carte : où il mène selon son type.
     *
     * @return array{type: string, url: string}
     */
    private function cta(Plan $plan): array
    {
        $url = match ($plan->cta_type) {
            'choose_plan' => '/inscription?plan='.rawurlencode($plan->code),
            'start_trial' => filled($plan->cta_url) ? (string) $plan->cta_url : '/inscription',
            default => (string) ($plan->cta_url ?? '/inscription'),
        };

        return ['type' => $plan->cta_type, 'url' => $url];
    }
}
