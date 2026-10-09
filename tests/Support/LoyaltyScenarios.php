<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Services\LoyaltyPrograms;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données du programme de fidélité, au-dessus de BookingScenarios (lundi 1er juin 2026, un site, un praticien, un service de 25 €).
 * Les rendez-vous sont créés pour le 2 juin puis terminés le 5 juin. Définit des fonctions sur le test : `in`, `program`, `book`,
 * `finishWith`, `visit`, `member`, `ledger`, `rewards`, `ledgerSum`.
 */
trait LoyaltyScenarios
{
    protected function loyaltyWorld(int $requiredVisits = 3): void
    {
        ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
        $this->bookingWorld('2026-06-01 08:00:00', $org);

        $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);

        $this->program = fn (array $override = [], bool $activate = true): LoyaltyProgram => ($this->in)(function () use ($override, $activate, $requiredVisits): LoyaltyProgram {
            $programs = app(LoyaltyPrograms::class);
            $programs->save([
                ...LoyaltyPrograms::defaults(),
                'name' => ['fr' => 'Fidélité', 'nl' => '', 'en' => 'Loyalty'],
                'reward_description' => ['fr' => 'Une coupe offerte', 'nl' => '', 'en' => 'A free haircut'],
                'terms' => ['fr' => 'Non cumulable.', 'nl' => '', 'en' => ''],
                'required_visits' => $requiredVisits,
                ...$override,
            ], $this->owner->id, true);

            if ($activate) {
                $programs->activate($this->owner->id);
            }

            return LoyaltyProgram::query()->firstOrFail();
        });

        $this->slot = 0;
        $this->book = function (?Customer $customer = null, ?Service $service = null): Booking {
            $time = sprintf('2026-06-02 %02d:%02d', 9 + intdiv($this->slot, 2), ($this->slot % 2) * 30);
            $this->slot++;
            $request = $this->request($time, customer: $customer);

            return ($this->in)(fn () => app(BookingCore::class)->create($this->org, $service === null ? $request : new NewBooking(
                $request->locationId, $service->id, $request->practitionerId, $request->customerId, $request->startsAt, $request->source, $request->actor, enforcePolicies: false,
            )));
        };
        $this->finishWith = function (Booking $booking, string $outcome = 'completed'): Booking {
            $this->travelTo(CarbonImmutable::parse('2026-06-05 12:00:00', 'UTC'));

            return ($this->in)(fn () => $outcome === 'completed' ? app(BookingCore::class)->markCompleted($booking, Actor::user(null)) : app(BookingCore::class)->markNoShow($booking, Actor::user(null)));
        };
        $this->visit = fn (?Customer $customer = null, ?Service $service = null): Booking => ($this->finishWith)(($this->book)($customer, $service));
        $this->member = fn (?Customer $customer = null): ?LoyaltyMember => ($this->in)(fn () => LoyaltyMember::query()->where('customer_id', ($customer ?? $this->customer)->id)->first());
        $this->ledger = fn (?string $kind = null): array => ($this->in)(fn () => LoyaltyLedgerEntry::query()->whereNotNull('customer_id')->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('id')->get()->all());
        $this->rewards = fn (?string $status = null): array => ($this->in)(fn () => LoyaltyReward::query()->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->get()->all());
        $this->ledgerSum = fn (?Customer $customer = null): int => (int) ($this->in)(fn () => LoyaltyLedgerEntry::query()->where('customer_id', ($customer ?? $this->customer)->id)->sum('stamps_delta'));
        $this->customerWith = fn (string $phone, string $name): Customer => $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => $phone, 'name' => $name]));
        $this->serviceWith = fn (string $name, int $price): Service => $this->asOwner(function () use ($name, $price): Service {
            $service = Service::query()->create(['org_id' => $this->org->id, 'name' => $name, 'duration_minutes' => 30, 'price_cents' => $price]);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'service_id' => $service->id]);

            return $service;
        });
    }
}
