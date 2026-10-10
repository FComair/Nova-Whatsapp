<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\ServiceRequest;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Catalog\Support\SectorTemplates;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Services de l'organisation. Chaque action a sa permission : créer, modifier, archiver, et régler les prix (docs/34).
 */
final class ServiceController
{
    public function __construct(private readonly CurrentTenant $current, private readonly ServiceCatalog $catalog) {}

    public function store(ServiceRequest $request): RedirectResponse
    {
        $this->catalog->create($this->organization(), $request->service());

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $changes = $request->service();

        // Le prix et la TVA se règlent avec leur propre permission : modifier le reste d'un service ne suffit pas (docs/34).
        abort_if(
            ! $this->current->can('services.prices') && ((int) $changes['price_cents'] !== (int) $service->price_cents || (float) $changes['vat_rate'] !== (float) $service->vat_rate),
            403,
        );

        $this->catalog->update($this->organization(), $service, $changes);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->catalog->delete($this->organization(), $service);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    /** Applique le modèle du secteur (uniquement sur un catalogue vide). */
    public function applyTemplate(): RedirectResponse
    {
        if (! SectorTemplates::has((string) $this->organization()->sector)) {
            return back()->withErrors(['template' => __('ui.services.errors.template_none')]);
        }

        try {
            $this->catalog->applyTemplate($this->organization());
        } catch (RuntimeException) {
            return back()->withErrors(['template' => __('ui.services.errors.template_not_empty')]);
        }

        return back()->with('status', __('ui.onboarding.saved'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
