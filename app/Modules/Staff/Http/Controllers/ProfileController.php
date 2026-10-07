<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Catalog\Models\Service;
use App\Modules\Staff\Http\Requests\ScheduleRequest;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\PractitionerManager;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Espace du praticien : il renseigne LUI-MÊME ses services et ses disponibilités (flowchart 1C, docs/02 §1).
 * Il n'a accès qu'à sa propre fiche : elle est retrouvée par son compte, jamais par un identifiant fourni.
 */
final class ProfileController
{
    public function __construct(private readonly CurrentTenant $current, private readonly PractitionerManager $manager) {}

    public function show(): Response
    {
        $practitioner = $this->practitioner();
        $locations = Location::query()->orderBy('created_at')->get();

        return Inertia::render('Profile/Practitioner', [
            'practitioner' => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'color' => $practitioner->color,
                'status' => $practitioner->status,
                'bookable' => $this->manager->isBookable($practitioner),
                'service_ids' => $practitioner->services()->pluck('services.id')->all(),
            ],
            'services' => Service::query()->orderBy('position')->get(['id', 'name'])->map(fn (Service $service): array => ['id' => $service->id, 'name' => $service->name])->all(),
            'locations' => $locations->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])->all(),
            'schedules' => $locations->mapWithKeys(fn (Location $location): array => [$location->id => $this->manager->weekly($practitioner, $location)])->all(),
        ]);
    }

    public function saveServices(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['uuid', 'distinct', Rule::exists('services', 'id')->whereNull('deleted_at')],
        ]);

        /** @var list<string> $serviceIds */
        $serviceIds = array_values($data['service_ids']);
        $this->manager->setServices($this->organization(), $this->practitioner(), $serviceIds);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function saveSchedule(ScheduleRequest $request, Location $location): RedirectResponse
    {
        try {
            $this->manager->replaceSchedule($this->organization(), $this->practitioner(), $location, $request->week());
        } catch (InvalidArgumentException) {
            return back()->withErrors(['hours' => __('ui.practitioners.errors.overlap_other_site')]);
        }

        return back()->with('status', __('ui.onboarding.saved'));
    }

    /** Fiche du praticien connecté. */
    private function practitioner(): Practitioner
    {
        return Practitioner::query()->where('user_id', auth()->id())->firstOrFail();
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
