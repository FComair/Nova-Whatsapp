<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Résumé du jour » du tableau de bord (flowchart 2B) : rendez-vous du jour, prochain rendez-vous et quelques compteurs,
 * limités à ce que l'utilisateur peut voir (docs/02 §1) : propriétaire = tout, responsable et réceptionniste = leurs sites,
 * praticien = ses propres rendez-vous. Le jour est celui du fuseau de l'établissement. À exécuter sous le contexte de l'organisation.
 */
final class DaySummary
{
    /** Nombre maximal de rendez-vous listés ; le compteur, lui, reste exact. */
    public const MAX_ROWS = 40;

    public function __construct(private readonly PlanningAccess $access) {}

    /**
     * @return array{
     *     date: string,
     *     counts: array{today: int, remaining: int, completed: int, cancelled: int, tomorrow: int, week: int, new_customers: int|null},
     *     next: array<string, mixed>|null,
     *     appointments: list<array<string, mixed>>,
     *     truncated: bool,
     *     multi_site: bool,
     *     sees_notes: bool
     * }
     */
    public function forToday(Organization $organization): array
    {
        $timezone = $organization->timezone ?: 'UTC';
        $now = CarbonImmutable::now();
        $dayStart = $now->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->addDay();
        $locations = $this->access->locations();
        $locationIds = $locations->pluck('id')->all();

        $scope = fn () => Booking::query()->whereIn('location_id', $locationIds)
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioner_id', $this->access->practitionerId()));

        $today = $scope()->whereIn('status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])
            ->where('starts_at', '>=', $dayStart->utc())->where('starts_at', '<', $dayEnd->utc())->orderBy('starts_at')->get();

        $customers = DB::table('customers')->whereIn('id', $today->pluck('customer_id'))->pluck('name', 'id');
        $services = Service::query()->pluck('name', 'id');
        $practitioners = Practitioner::query()->pluck('name', 'id');
        $sites = $locations->pluck('name', 'id');

        $upcoming = fn (Booking $booking): bool => in_array($booking->status, Booking::BLOCKING, true) && $booking->starts_at > $now;
        $nextId = $today->first($upcoming)?->id;

        $rows = $today->take(self::MAX_ROWS)->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'starts' => $booking->starts_at->setTimezone($timezone)->format('H:i'),
            'ends' => $booking->ends_at->setTimezone($timezone)->format('H:i'),
            'customer' => $customers[$booking->customer_id] ?? null,
            'service' => $services[$booking->service_id] ?? null,
            'practitioner' => $practitioners[$booking->practitioner_id] ?? null,
            'site' => $sites[$booking->location_id] ?? null,
            'status' => $booking->status,
            'is_next' => $booking->id === $nextId,
            'is_past' => $booking->starts_at <= $now,
        ])->values()->all();

        $blocking = fn ($from, $to) => $scope()->whereIn('status', Booking::BLOCKING)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->count();

        return [
            'date' => $dayStart->toDateString(),
            'counts' => [
                'today' => $today->count(),
                'remaining' => $today->filter($upcoming)->count(),
                'completed' => $today->where('status', Booking::COMPLETED)->count(),
                'cancelled' => $scope()->whereIn('status', [Booking::CANCELLED_BY_CUSTOMER, Booking::CANCELLED_BY_BUSINESS])
                    ->where('starts_at', '>=', $dayStart->utc())->where('starts_at', '<', $dayEnd->utc())->count(),
                'tomorrow' => $blocking($dayEnd->utc(), $dayEnd->addDay()->utc()),
                'week' => $blocking($dayStart->utc(), $dayStart->addDays(7)->utc()),
                // Un praticien ne voit que ses propres clients : pas de compteur global pour lui.
                'new_customers' => $this->access->isPractitioner() ? null : Customer::query()->where('created_at', '>=', $now->subDays(7))->count(),
            ],
            'next' => collect($rows)->firstWhere('is_next', true),
            'appointments' => $rows,
            'truncated' => $today->count() > self::MAX_ROWS,
            'multi_site' => $locations->count() > 1,
            'sees_notes' => $this->access->seesNotes(),
        ];
    }
}
