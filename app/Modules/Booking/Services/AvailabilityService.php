<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\Intervals;
use App\Modules\Booking\Support\Slot;
use App\Modules\Calendars\Models\ExternalBusySlot;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\BusinessHourException;
use App\Modules\Catalog\Models\Service;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Staff\Models\PractitionerTimeOff;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de disponibilité (docs/01 §6) : calcul des créneaux à la volée.
 *
 *   disponibilité d'un praticien = (horaires d'ouverture du site ∩ planning du praticien + disponibilités exceptionnelles)
 *                                  − indisponibilités − rendez-vous existants (marges comprises)
 *
 * Tout est calculé en UTC ; les heures locales du site (fuseau IANA) sont converties une fois par jour, ce qui rend
 * le passage à l'heure d'été/hiver correct : un jour de 23 h ou 25 h a simplement moins ou plus de créneaux.
 * La base reste l'ultime garde-fou contre le double-booking (contrainte d'exclusion) : ce service ne fait qu'anticiper.
 */
final class AvailabilityService
{
    public function __construct(private readonly PolicyService $policies) {}

    /**
     * Créneaux réservables pour un service sur un site, entre deux dates locales (incluses).
     *
     * @param  bool  $applyPolicies  délai minimum et horizon de réservation (clients) ; faux pour la saisie par le personnel
     * @return list<Slot>
     */
    public function slots(
        Organization $organization,
        Location $location,
        Service $service,
        string $fromDate,
        string $toDate,
        ?Practitioner $only = null,
        bool $applyPolicies = true,
        ?string $ignoreBookingId = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();
        $slots = [];

        foreach ($this->practitioners($service, $location, $only) as $practitioner) {
            foreach ($this->slotsFor($organization, $location, $service, $practitioner, $fromDate, $toDate, $applyPolicies, $ignoreBookingId, $now) as $slot) {
                $slots[] = $slot;
            }
        }

        usort($slots, fn (Slot $a, Slot $b): int => [$a->startsAt->getTimestamp(), $a->practitionerId] <=> [$b->startsAt->getTimestamp(), $b->practitionerId]);

        return $slots;
    }

    /**
     * Les N prochains créneaux libres à partir de maintenant (proposés quand le créneau choisi vient d'être pris : E10).
     *
     * @return list<Slot>
     */
    public function nextSlots(
        Organization $organization,
        Location $location,
        Service $service,
        int $count = 3,
        ?Practitioner $only = null,
        bool $applyPolicies = true,
        ?string $ignoreBookingId = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();
        $tz = $location->timezone;
        $found = [];

        // Recherche par tranches de 14 jours, sur au plus un an.
        for ($offset = 0; $offset < 365 && count($found) < $count; $offset += 14) {
            $from = $now->setTimezone($tz)->startOfDay()->addDays($offset);
            $slots = $this->slots($organization, $location, $service, $from->toDateString(), $from->addDays(13)->toDateString(), $only, $applyPolicies, $ignoreBookingId, $now);
            $found = [...$found, ...array_filter($slots, fn (Slot $slot): bool => $slot->startsAt > $now)];
        }

        return array_slice($found, 0, $count);
    }

    /**
     * Ce début précis est-il réservable ? Contrôle direct : il n'exige pas d'être aligné sur la grille des créneaux
     * proposés (le personnel peut saisir 10 h 10).
     */
    public function isAvailable(
        Organization $organization,
        Location $location,
        Service $service,
        Practitioner $practitioner,
        CarbonImmutable $startsAt,
        bool $applyPolicies = true,
        ?string $ignoreBookingId = null,
        ?CarbonImmutable $now = null,
    ): bool {
        return $this->unavailabilityReason($organization, $location, $service, $practitioner, $startsAt, $applyPolicies, $ignoreBookingId, $now) === null;
    }

    /**
     * Raison pour laquelle un début n'est pas réservable, ou null s'il l'est : `policy` (délai minimum / horizon),
     * `outside_hours` (hors horaires d'ouverture ou du praticien, ou en congé), `taken` (déjà occupé).
     *
     * @return 'policy'|'outside_hours'|'taken'|null
     */
    public function unavailabilityReason(
        Organization $organization,
        Location $location,
        Service $service,
        Practitioner $practitioner,
        CarbonImmutable $startsAt,
        bool $applyPolicies = true,
        ?string $ignoreBookingId = null,
        ?CarbonImmutable $now = null,
    ): ?string {
        $now ??= CarbonImmutable::now();
        [$duration, $before, $after] = $this->durations($service, $practitioner);
        $start = $startsAt->getTimestamp();
        $end = $start + $duration * 60;

        if ($applyPolicies && $this->violatesPolicy($organization, $start, $now)) {
            return 'policy';
        }

        $date = $startsAt->setTimezone($location->timezone)->toDateString();
        $context = $this->load($location, $practitioner, $date, $date, $ignoreBookingId, $now);

        $insideWindow = collect($this->windows($location, $date, $context))->contains(fn (array $w): bool => $start >= $w[0] && $end <= $w[1]);

        if (! $insideWindow) {
            return 'outside_hours';
        }

        return $this->isBlocked($context['blocked'], $start - $before * 60, $end + $after * 60) ? 'taken' : null;
    }

    /**
     * Temps de travail d'un praticien sur un site, jour par jour (jour local du site) : plages où il peut recevoir, c'est-à-dire
     * horaires d'ouverture ∩ planning du praticien, fermetures exceptionnelles et indisponibilités déduites, disponibilités
     * exceptionnelles ajoutées. Les rendez-vous ne sont PAS déduits : c'est le temps de travail prévu, pas le temps libre.
     * `scheduled` dit si le planning habituel du praticien prévoit ce jour de la semaine.
     *
     * @return array<string, array{minutes: int, scheduled: bool}> date locale => temps de travail prévu
     */
    public function workingDays(Location $location, Practitioner $practitioner, string $fromDate, string $toDate): array
    {
        $context = $this->load($location, $practitioner, $fromDate, $toDate, null, CarbonImmutable::now(), false);
        $days = [];

        for ($day = CarbonImmutable::parse($fromDate, $location->timezone); $day->toDateString() <= $toDate; $day = $day->addDay()) {
            $date = $day->toDateString();
            $minutes = 0;

            foreach ($this->windows($location, $date, $context) as [$start, $end]) {
                $minutes += intdiv($end - $start, 60);
            }

            $days[$date] = ['minutes' => $minutes, 'scheduled' => ($context['schedule'][$day->isoWeekday()] ?? []) !== []];
        }

        return $days;
    }

    /**
     * @return list<Slot>
     */
    private function slotsFor(
        Organization $organization,
        Location $location,
        Service $service,
        Practitioner $practitioner,
        string $fromDate,
        string $toDate,
        bool $applyPolicies,
        ?string $ignoreBookingId,
        CarbonImmutable $now,
    ): array {
        [$duration, $before, $after] = $this->durations($service, $practitioner);
        $step = (int) config('nova.booking.slot_step_minutes', 15) * 60;
        $context = $this->load($location, $practitioner, $fromDate, $toDate, $ignoreBookingId, $now);
        $slots = [];

        for ($day = CarbonImmutable::parse($fromDate, $location->timezone); $day->toDateString() <= $toDate; $day = $day->addDay()) {
            foreach ($this->windows($location, $day->toDateString(), $context) as [$windowStart, $windowEnd]) {
                for ($start = $windowStart; $start + $duration * 60 <= $windowEnd; $start += $step) {
                    $end = $start + $duration * 60;

                    if ($start < $now->getTimestamp()) {
                        continue; // jamais de créneau dans le passé, même pour le personnel
                    }

                    if ($applyPolicies && $this->violatesPolicy($organization, $start, $now)) {
                        continue;
                    }

                    if ($this->isBlocked($context['blocked'], $start - $before * 60, $end + $after * 60)) {
                        continue;
                    }

                    $slots[] = new Slot(CarbonImmutable::createFromTimestampUTC($start), CarbonImmutable::createFromTimestampUTC($end), $practitioner->id, $location->id);
                }
            }
        }

        return $slots;
    }

    /**
     * Praticiens qui réalisent le service sur ce site : actifs, avec au moins une plage de planning sur le site.
     *
     * @return list<Practitioner>
     */
    private function practitioners(Service $service, Location $location, ?Practitioner $only): array
    {
        return Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->whereHas('services', fn ($query) => $query->where('services.id', $service->id))
            ->whereHas('schedules', fn ($query) => $query->where('location_id', $location->id))
            ->when($only !== null, fn ($query) => $query->where('practitioners.id', $only?->id))
            ->orderBy('position')
            ->get()
            ->all();
    }

    /**
     * Durée (minutes), marge avant et marge après : la durée peut être personnalisée par praticien.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function durations(Service $service, Practitioner $practitioner): array
    {
        $custom = DB::table('practitioner_services')
            ->where('practitioner_id', $practitioner->id)->where('service_id', $service->id)->value('custom_duration_minutes');

        return [(int) ($custom ?? $service->duration_minutes), $service->buffer_before_minutes, $service->buffer_after_minutes];
    }

    private function violatesPolicy(Organization $organization, int $start, CarbonImmutable $now): bool
    {
        $policy = $this->policies->for($organization);

        return $start < $now->getTimestamp() + $policy->min_notice_hours * 3600
            || $start > $now->getTimestamp() + $policy->max_horizon_days * 86400;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $blocked
     */
    private function isBlocked(array $blocked, int $from, int $until): bool
    {
        foreach ($blocked as [$blockedFrom, $blockedUntil]) {
            if (Intervals::overlaps($from, $until, $blockedFrom, $blockedUntil)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Données nécessaires au calcul, chargées une fois pour toute la période.
     *
     * @return array{hours: array<int, list<array{0: string, 1: string}>>, exceptions: array<string, list<BusinessHourException>>, schedule: array<int, list<array{0: string, 1: string}>>, timeOff: list<PractitionerTimeOff>, blocked: list<array{0: int, 1: int}>}
     */
    private function load(Location $location, Practitioner $practitioner, string $fromDate, string $toDate, ?string $ignoreBookingId, CarbonImmutable $now, bool $withBusy = true): array
    {
        $tz = $location->timezone;
        $rangeStart = CarbonImmutable::parse($fromDate, $tz)->startOfDay()->utc();
        $rangeEnd = CarbonImmutable::parse($toDate, $tz)->addDay()->startOfDay()->utc();

        $hours = [];
        BusinessHour::query()->where('location_id', $location->id)->orderBy('starts_at')->get()
            ->each(function (BusinessHour $hour) use (&$hours): void {
                $hours[$hour->weekday][] = [substr($hour->starts_at, 0, 5), substr($hour->ends_at, 0, 5)];
            });

        $schedule = [];
        PractitionerSchedule::query()->where('practitioner_id', $practitioner->id)->where('location_id', $location->id)->orderBy('starts_at')->get()
            ->each(function (PractitionerSchedule $slot) use (&$schedule): void {
                $schedule[$slot->weekday][] = [substr($slot->starts_at, 0, 5), substr($slot->ends_at, 0, 5)];
            });

        $exceptions = BusinessHourException::query()->where('location_id', $location->id)->whereBetween('date', [$fromDate, $toDate])->get()
            ->groupBy(fn (BusinessHourException $exception): string => $exception->date->format('Y-m-d'))->map(fn ($group): array => $group->all())->all();

        $timeOff = PractitionerTimeOff::query()->where('practitioner_id', $practitioner->id)
            ->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)->get()->all();

        if (! $withBusy) {
            return ['hours' => $hours, 'exceptions' => $exceptions, 'schedule' => $schedule, 'timeOff' => $timeOff, 'blocked' => []];
        }

        // Un créneau en attente dont le blocage a expiré est libre (il sera libéré par la tâche planifiée).
        $blocked = Booking::query()->where('practitioner_id', $practitioner->id)
            ->whereIn('status', Booking::BLOCKING)
            ->where('blocked_until', '>', $rangeStart)->where('blocked_from', '<', $rangeEnd)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->get()
            ->reject(fn (Booking $booking): bool => $booking->status === Booking::PENDING && $booking->expires_at !== null && $booking->expires_at <= $now)
            ->map(fn (Booking $booking): array => [$booking->blocked_from->getTimestamp(), $booking->blocked_until->getTimestamp()])
            ->values()->all();

        // Temps occupé dans les calendriers externes du praticien (lecture seule, synchronisés) : même effet qu'un rendez-vous.
        $external = ExternalBusySlot::query()
            ->whereIn('calendar_id', ExternalCalendar::query()->where('practitioner_id', $practitioner->id)->where('status', '!=', 'paused')->select('id'))
            ->where('ends_at', '>', $rangeStart)->where('starts_at', '<', $rangeEnd)->get()
            ->map(fn (ExternalBusySlot $slot): array => [$slot->starts_at->getTimestamp(), $slot->ends_at->getTimestamp()])->values()->all();

        $blocked = [...$blocked, ...$external];

        return ['hours' => $hours, 'exceptions' => $exceptions, 'schedule' => $schedule, 'timeOff' => $timeOff, 'blocked' => $blocked];
    }

    /**
     * Fenêtres de disponibilité (UTC) d'un praticien pour une date locale du site.
     *
     * @param  array{hours: array<int, list<array{0: string, 1: string}>>, exceptions: array<string, list<BusinessHourException>>, schedule: array<int, list<array{0: string, 1: string}>>, timeOff: list<PractitionerTimeOff>, blocked: list<array{0: int, 1: int}>}  $context
     * @return list<array{0: int, 1: int}>
     */
    private function windows(Location $location, string $date, array $context): array
    {
        $tz = $location->timezone;
        $day = CarbonImmutable::parse($date, $tz);
        $weekday = $day->isoWeekday();
        $dayBounds = [[$day->startOfDay()->getTimestamp(), $day->addDay()->startOfDay()->getTimestamp()]];

        $toUnix = fn (array $ranges): array => array_map(
            fn (array $range): array => [CarbonImmutable::parse("{$date} {$range[0]}", $tz)->getTimestamp(), CarbonImmutable::parse("{$date} {$range[1]}", $tz)->getTimestamp()],
            $ranges,
        );

        // Exception du jour : fermeture, ou plages exceptionnelles qui remplacent les horaires habituels.
        $exceptions = $context['exceptions'][$date] ?? [];

        if (collect($exceptions)->contains(fn (BusinessHourException $exception): bool => $exception->is_closed)) {
            return [];
        }

        $open = $exceptions !== []
            ? array_map(fn (BusinessHourException $exception): array => [substr((string) $exception->starts_at, 0, 5), substr((string) $exception->ends_at, 0, 5)], $exceptions)
            : ($context['hours'][$weekday] ?? []);

        $openIntervals = Intervals::normalize($toUnix($open));
        $available = Intervals::intersect($openIntervals, $toUnix($context['schedule'][$weekday] ?? []));

        $extra = [];
        $unavailable = [];

        foreach ($context['timeOff'] as $off) {
            $interval = [$off->starts_at->getTimestamp(), $off->ends_at->getTimestamp()];
            $off->kind === PractitionerTimeOff::EXTRA ? $extra[] = $interval : $unavailable[] = $interval;
        }

        // Une disponibilité exceptionnelle ajoute du temps de travail, mais le site doit être ouvert.
        $available = Intervals::union($available, Intervals::intersect(Intervals::intersect($extra, $dayBounds), $openIntervals));

        return Intervals::subtract(Intervals::intersect($available, $dayBounds), $unavailable);
    }
}
