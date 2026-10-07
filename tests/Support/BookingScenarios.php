<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données du moteur de réservation : un site à Bruxelles ouvert du lundi au vendredi de 9 h à 17 h,
 * un praticien qui travaille les mêmes heures et un service de 30 minutes. Créé avec le rôle propriétaire ; les
 * tests exécutent ensuite le moteur dans le contexte de l'organisation, soumis à la RLS.
 */
trait BookingScenarios
{
    protected Organization $org;

    protected Location $location;

    protected Service $service;

    protected Practitioner $practitioner;

    protected Customer $customer;

    /**
     * Lundi 1er juin 2026, 08:00 UTC (10:00 à Bruxelles). Délai minimum par défaut : 2 h.
     */
    protected function bookingWorld(string $now = '2026-06-01 08:00:00', ?Organization $existing = null): void
    {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

        $this->asOwner(function () use ($existing): void {
            $this->org = $existing ?? Organization::factory()->create();
            $this->location = $existing === null
                ? Location::factory()->create(['org_id' => $this->org->id, 'is_default' => true, 'timezone' => 'Europe/Brussels'])
                : tap(Location::query()->where('org_id', $this->org->id)->firstOrFail(), fn (Location $location) => $location->forceFill(['timezone' => 'Europe/Brussels'])->save());
            $this->service = Service::query()->create(['org_id' => $this->org->id, 'name' => 'Coupe', 'duration_minutes' => 30, 'price_cents' => 2500]);
            $this->practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Léa', 'color' => '#12A594']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'service_id' => $this->service->id]);

            foreach (range(1, 5) as $weekday) {
                BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);
                PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'location_id' => $this->location->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);
            }

            $this->customer = Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470123456', 'name' => 'Client Test']);
        });

        app(TenantContext::class)->set($this->org->id);
    }

    /** Une heure locale de Bruxelles vers un instant UTC. */
    protected function brussels(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Europe/Brussels')->utc();
    }

    protected function request(string $localStart, string $source = 'dashboard', bool $hold = false, bool $enforcePolicies = false, ?string $key = null, ?Practitioner $practitioner = null, ?Customer $customer = null): NewBooking
    {
        return new NewBooking(
            locationId: $this->location->id,
            serviceId: $this->service->id,
            practitionerId: ($practitioner ?? $this->practitioner)->id,
            customerId: ($customer ?? $this->customer)->id,
            startsAt: $this->brussels($localStart),
            source: $source,
            actor: Actor::user(null),
            hold: $hold,
            enforcePolicies: $enforcePolicies,
            idempotencyKey: $key,
        );
    }
}
