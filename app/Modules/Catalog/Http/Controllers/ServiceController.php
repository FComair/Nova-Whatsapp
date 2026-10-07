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
 * Services de l'organisation. Propriétaire et responsable de site (docs/02 §1).
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
        $this->catalog->update($this->organization(), $service, $request->service());

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
