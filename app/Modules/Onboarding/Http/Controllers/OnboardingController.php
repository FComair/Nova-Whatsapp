<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Support\PlanLimits;
use App\Modules\Booking\Http\Requests\PolicyRequest;
use App\Modules\Booking\Services\PolicyService;
use App\Modules\Catalog\Http\Requests\ServiceRequest;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\BusinessHoursService;
use App\Modules\Catalog\Support\SectorTemplates;
use App\Modules\Onboarding\Http\Requests\CompanyRequest;
use App\Modules\Tenancy\Support\TimezoneCatalog;
use App\Modules\Onboarding\Http\Requests\HoursRequest;
use App\Modules\Onboarding\Http\Requests\SiteRequest;
use App\Modules\Onboarding\Services\OnboardingProgressService;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\PractitionerManager;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\CompanyUpdater;
use App\Modules\Tenancy\Services\SectorCatalog;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Http\Controllers\WhatsappController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistant d'onboarding (flowcharts 1B à 1D) : entreprise, sites et horaires d'ouverture.
 * Réservé au propriétaire. La progression est enregistrée à chaque étape et reprise à la prochaine connexion.
 */
final class OnboardingController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly OnboardingProgressService $progress,
        private readonly BusinessHoursService $hours,
        private readonly PractitionerManager $staff,
        private readonly PlanLimits $limits,
        private readonly PolicyService $policies,
        private readonly CompanyUpdater $company,
    ) {}

    /** Rouvre l'assistant à l'étape enregistrée (flowchart 2A). */
    public function index(): RedirectResponse
    {
        return $this->redirectToCurrent();
    }

    public function show(Request $request, string $slug): Response
    {
        $step = OnboardingSteps::fromSlug($slug) ?? abort(404);

        return match ($step) {
            OnboardingSteps::COMPANY => $this->companyPage(),
            OnboardingSteps::LOCATIONS => $this->sitesPage(),
            OnboardingSteps::HOURS => $this->hoursPage($request),
            OnboardingSteps::SERVICES => $this->servicesPage(),
            OnboardingSteps::PRACTITIONERS => $this->practitionersPage(),
            OnboardingSteps::POLICIES => $this->policiesPage(),
            OnboardingSteps::WHATSAPP => Inertia::render('Onboarding/Whatsapp', [...$this->wizard($step), ...app(WhatsappController::class)->state()]),
            OnboardingSteps::TEMPLATES => Inertia::render('Onboarding/Templates', [...$this->wizard($step), ...app(WhatsappController::class)->state()]),
            default => Inertia::render('Onboarding/Pending', $this->wizard($step)),
        };
    }

    // --- Étape 1 : entreprise ---

    public function saveCompany(CompanyRequest $request): RedirectResponse
    {
        $this->company->update($this->organization(), $request->validated(), auth()->id());

        return $this->finish(OnboardingSteps::COMPANY);
    }

    // --- Étape 2 : sites ---

    public function storeSite(SiteRequest $request): RedirectResponse
    {
        $organization = $this->organization();

        $location = Location::query()->create([
            'org_id' => $organization->id,
            'name' => $request->string('name')->toString(),
            'address' => $request->address(),
            'timezone' => $organization->timezone,
            'is_default' => ! Location::query()->exists(),
        ]);

        Audit::record('location.created', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'location', entityId: $location->id, diff: ['name' => $location->name]);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function updateSite(SiteRequest $request, Location $location): RedirectResponse
    {
        $location->fill(['name' => $request->string('name')->toString(), 'address' => $request->address()]);
        $diff = collect($location->getDirty())->keys()->all();
        $location->save();

        Audit::record('location.updated', 'user', actorId: auth()->id(), orgId: $location->org_id, entityType: 'location', entityId: $location->id, diff: ['fields' => $diff]);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function destroySite(Location $location): RedirectResponse
    {
        if (Location::query()->count() <= 1) {
            return back()->withErrors(['site' => __('ui.onboarding.errors.site_last')]);
        }

        DB::transaction(function () use ($location): void {
            $wasDefault = $location->is_default;
            $location->forceFill(['is_default' => false])->save();
            $location->delete();

            // Le site par défaut disparaît : le plus ancien site restant prend le relais.
            if ($wasDefault) {
                Location::query()->orderBy('created_at')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        Audit::record('location.deleted', 'user', actorId: auth()->id(), orgId: $location->org_id, entityType: 'location', entityId: $location->id, diff: ['name' => $location->name]);

        return back()->with('status', __('ui.onboarding.saved'));
    }

    public function completeSites(): RedirectResponse
    {
        if (! Location::query()->exists()) {
            return back()->withErrors(['site' => __('ui.onboarding.errors.site_none')]);
        }

        return $this->finish(OnboardingSteps::LOCATIONS);
    }

    // --- Étape 3 : horaires d'ouverture ---

    public function saveHours(HoursRequest $request, Location $location): RedirectResponse
    {
        $this->hours->replace($location, $request->week());

        // Chaque site doit avoir ses horaires avant de terminer l'étape.
        $missing = Location::query()->orderBy('created_at')->get()->first(fn (Location $site): bool => ! $this->hours->hasAnyOpenRange($site));

        if ($missing !== null) {
            return redirect()->route('onboarding.step', ['slug' => OnboardingSteps::SLUGS[OnboardingSteps::HOURS], 'site' => $missing->id])
                ->with('status', __('ui.onboarding.saved'));
        }

        return $this->finish(OnboardingSteps::HOURS);
    }

    // --- Étape 4 : services ---

    public function completeServices(): RedirectResponse
    {
        if (! Service::query()->exists()) {
            return back()->withErrors(['service' => __('ui.services.errors.none')]);
        }

        return $this->finish(OnboardingSteps::SERVICES);
    }

    // --- Étape 5 : praticiens ---

    public function completePractitioners(): RedirectResponse
    {
        $bookable = Practitioner::query()->get()->contains(fn (Practitioner $practitioner): bool => $this->staff->isBookable($practitioner));

        if (! $bookable) {
            return back()->withErrors(['practitioner' => __('ui.practitioners.errors.none_bookable')]);
        }

        return $this->finish(OnboardingSteps::PRACTITIONERS);
    }

    // --- Étape 6 : politiques de réservation ---

    public function savePolicies(PolicyRequest $request): RedirectResponse
    {
        $this->policies->update($this->organization(), $request->policy());

        return $this->finish(OnboardingSteps::POLICIES);
    }

    // --- Étapes 7 et 8 : WhatsApp et modèles de messages ---
    // Le numéro et les modèles se règlent dans les paramètres WhatsApp ; l'assistant peut être poursuivi sans attendre Meta (approbation sous quelques heures).

    public function completeWhatsapp(): RedirectResponse
    {
        return $this->finish(OnboardingSteps::WHATSAPP);
    }

    public function completeTemplates(): RedirectResponse
    {
        return $this->finish(OnboardingSteps::TEMPLATES);
    }

    // --- Pages ---

    private function companyPage(): Response
    {
        $organization = $this->organization();

        return Inertia::render('Onboarding/Company', [
            ...$this->wizard(OnboardingSteps::COMPANY),
            'company' => $organization->only(['name', 'sector', 'country', 'timezone', 'locale']),
            'sectors' => app(SectorCatalog::class)->options(app()->getLocale(), $organization->sector),
            'countries' => config('nova.countries'),
            'timezones' => app(TimezoneCatalog::class)->byCountry(config('nova.countries')),
        ]);
    }

    private function sitesPage(): Response
    {
        $locations = Location::query()->orderBy('created_at')->get();

        return Inertia::render('Onboarding/Sites', [
            ...$this->wizard(OnboardingSteps::LOCATIONS),
            'locations' => $locations->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'is_default' => $location->is_default,
                'address' => $location->address ?? ['street' => null, 'postal_code' => null, 'city' => null, 'country' => $this->organization()->country],
            ])->all(),
            'countries' => config('nova.countries'),
            'defaultCountry' => $this->organization()->country,
        ]);
    }

    private function servicesPage(): Response
    {
        $organization = $this->organization();
        $services = Service::query()->with('category')->orderBy('position')->get();

        return Inertia::render('Onboarding/Services', [
            ...$this->wizard(OnboardingSteps::SERVICES),
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category?->name,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'buffer_before_minutes' => $service->buffer_before_minutes,
                'buffer_after_minutes' => $service->buffer_after_minutes,
                'price_cents' => $service->price_cents,
                'vat_rate' => $service->vat_rate,
                'capacity' => $service->capacity,
                'bookable_online' => $service->bookable_online,
                'notes_required' => $service->notes_required,
            ])->all(),
            'categories' => ServiceCategory::query()->where('locale', app()->getLocale())->orderBy('position')->pluck('name')->all(),
            'template' => $services->isEmpty() && SectorTemplates::has((string) $organization->sector)
                ? ['sector' => $organization->sector, 'count' => count(SectorTemplates::services((string) $organization->sector, $organization->locale))]
                : null,
            'vatRates' => ServiceRequest::VAT_RATES,
        ]);
    }

    private function practitionersPage(): Response
    {
        $organization = $this->organization();
        $locations = Location::query()->orderBy('created_at')->get();
        $practitioners = Practitioner::query()->with('services:id')->orderBy('position')->get();

        return Inertia::render('Onboarding/Practitioners', [
            ...$this->wizard(OnboardingSteps::PRACTITIONERS),
            'practitioners' => $practitioners->map(fn (Practitioner $practitioner): array => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'bio' => $practitioner->bio,
                'color' => $practitioner->color,
                'photo_version' => $practitioner->photoVersion(),
                'status' => $practitioner->status,
                'is_me' => $practitioner->user_id !== null && $practitioner->user_id === (string) auth()->id(),
                'bookable' => $this->staff->isBookable($practitioner),
                'has_account' => $practitioner->user_id !== null,
                'invitation' => $this->invitationOf($practitioner),
                'service_ids' => $practitioner->services->pluck('id')->all(),
                'schedules' => $locations->mapWithKeys(fn (Location $location): array => [$location->id => $this->staff->weekly($practitioner, $location)])->all(),
            ])->all(),
            'services' => Service::query()->orderBy('position')->get(['id', 'name'])->map(fn (Service $service): array => ['id' => $service->id, 'name' => $service->name])->all(),
            'locations' => $locations->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])->all(),
            'colors' => config('nova.practitioner_colors'),
            'plan' => [
                'name' => $this->limits->planFor($organization)->name,
                'limit' => $this->staff->limit($organization),
                'used' => $this->staff->usedSlots(),
            ],
            'invitationDays' => (int) config('nova.security.invitation_ttl_days'),
            'meLinked' => Practitioner::query()->where('user_id', auth()->id())->exists(),
        ]);
    }

    /**
     * Invitation en attente (ou expirée) d'un praticien, pour l'affichage et le renvoi.
     *
     * @return array{email: string, status: string, expires_at: string}|null
     */
    private function invitationOf(Practitioner $practitioner): ?array
    {
        $invitation = Invitation::query()->where('practitioner_id', $practitioner->id)->whereNull('used_at')->whereNull('revoked_at')->first();

        return $invitation === null ? null : [
            'email' => $invitation->email,
            'status' => $invitation->isUsable() ? 'pending' : 'expired',
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    private function policiesPage(): Response
    {
        $policy = $this->policies->for($this->organization());

        return Inertia::render('Onboarding/Policies', [
            ...$this->wizard(OnboardingSteps::POLICIES),
            'policy' => [
                'min_notice_hours' => $policy->min_notice_hours,
                'max_horizon_days' => $policy->max_horizon_days,
                'cancellation_window_hours' => $policy->cancellation_window_hours,
                'no_show_fee_cents' => $policy->no_show_fee_cents,
                'allow_overbooking' => $policy->allow_overbooking,
                'hold_minutes' => $policy->hold_minutes,
                'reminder_hours_before' => $policy->reminder_hours_before,
            ],
        ]);
    }

    private function hoursPage(Request $request): Response
    {
        $locations = Location::query()->orderBy('created_at')->get();
        $selected = $locations->firstWhere('id', $request->query('site')) ?? $locations->first() ?? abort(404);

        return Inertia::render('Onboarding/Hours', [
            ...$this->wizard(OnboardingSteps::HOURS),
            'locations' => $locations->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'configured' => $this->hours->hasAnyOpenRange($location),
            ])->all(),
            'selectedId' => $selected->id,
            'week' => $this->hours->weekly($selected),
        ]);
    }

    /**
     * @return array{wizard: array{steps: list<array<string, mixed>>, current: string, step: string, total: int}}
     */
    private function wizard(string $step): array
    {
        $progress = $this->progress->current();

        return ['wizard' => [
            'steps' => $progress->overview(),
            'current' => $progress->currentStep(),
            'step' => $step,
            'total' => count(OnboardingSteps::ALL),
        ]];
    }

    /** Marque l'étape terminée et ouvre la suivante à faire. */
    private function finish(string $step): RedirectResponse
    {
        $progress = $this->progress->current();
        $progress->markDone($step);

        return redirect()->route('onboarding.step', ['slug' => OnboardingSteps::SLUGS[$progress->nextStepAfter($step)]]);
    }

    private function redirectToCurrent(): RedirectResponse
    {
        $step = $this->progress->current()->currentStep();

        return redirect()->route('onboarding.step', ['slug' => OnboardingSteps::SLUGS[$step]]);
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
