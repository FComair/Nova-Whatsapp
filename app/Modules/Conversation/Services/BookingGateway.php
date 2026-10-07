<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\PolicyService;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Conversation\Support\Turn;
use App\Modules\Staff\Models\Practitioner;
use Illuminate\Support\Facades\DB;

/**
 * Le seul point de contact de l'assistant avec le moteur de réservation de Nova.
 *
 * Il LIT les services, praticiens, créneaux et rendez-vous, et ÉCRIT uniquement par le cœur de réservation (BookingCore) :
 * mêmes règles que le dashboard et l'API (pas de double réservation, délai minimum, fenêtre d'annulation, journal des événements).
 * Le calendrier de référence est celui de Nova : aucune synchronisation Google ou Outlook.
 */
final class BookingGateway
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingCore $core,
        private readonly PolicyService $policies,
    ) {}

    /**
     * Services proposés au client : actifs, réservables en ligne, réalisés par au moins un praticien actif.
     *
     * @return list<Service>
     */
    public function services(): array
    {
        return Service::query()
            ->where('is_active', true)->where('bookable_online', true)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('practitioner_services')
                ->join('practitioners', 'practitioners.id', '=', 'practitioner_services.practitioner_id')
                ->whereColumn('practitioner_services.service_id', 'services.id')
                ->where('practitioners.status', Practitioner::STATUS_ACTIVE)->whereNull('practitioners.deleted_at'))
            ->orderBy('position')->orderBy('name')
            ->get()->all();
    }

    public function service(?string $id): ?Service
    {
        return $id === null ? null : Service::query()->withTrashed()->find($id);
    }

    public function practitioner(?string $id): ?Practitioner
    {
        return $id === null ? null : Practitioner::query()->withTrashed()->find($id);
    }

    /**
     * Praticiens actifs qui réalisent le service sur le site.
     *
     * @return list<Practitioner>
     */
    public function practitionersFor(Turn $turn, Service $service): array
    {
        return Practitioner::query()
            ->where('status', Practitioner::STATUS_ACTIVE)
            ->whereHas('services', fn ($query) => $query->where('services.id', $service->id))
            ->whereHas('schedules', fn ($query) => $query->where('location_id', $turn->location->id))
            ->orderBy('position')->orderBy('name')
            ->get()->all();
    }

    /**
     * Heures de début libres (« 09:00 »), heure locale du site, sans doublon, pour un jour.
     *
     * @return list<string>
     */
    public function times(Turn $turn, Service $service, ?Practitioner $only, string $date, ?string $ignoreBookingId = null): array
    {
        $slots = $this->availability->slots($turn->org, $turn->location, $service, $date, $date, $only, true, $ignoreBookingId, $turn->now);
        $times = [];

        foreach ($slots as $slot) {
            $times[$turn->local($slot->startsAt)->format('H:i')] = true;
        }

        $list = array_keys($times);
        sort($list);

        return array_map('strval', $list);
    }

    /**
     * Praticiens (dans l'ordre du planning) qui ont au moins un créneau libre ce jour-là.
     *
     * @return list<Practitioner>
     */
    public function practitionersWithTimes(Turn $turn, Service $service, string $date, ?string $ignoreBookingId = null): array
    {
        return array_values(array_filter(
            $this->practitionersFor($turn, $service),
            fn (Practitioner $practitioner): bool => $this->times($turn, $service, $practitioner, $date, $ignoreBookingId) !== [],
        ));
    }

    /**
     * Le praticien qui peut recevoir le client à ce moment : le préféré s'il est libre, sinon (« aucune préférence ») le premier libre.
     * Sinon la raison : `taken`, `outside_hours`, `policy` ou `cannot_perform`.
     *
     * @return array{0: Practitioner|null, 1: string|null}
     */
    public function practitionerAt(Turn $turn, Service $service, ?Practitioner $preferred, string $date, string $time, ?string $ignoreBookingId = null): array
    {
        $candidates = $preferred !== null ? [$preferred] : $this->practitionersFor($turn, $service);

        if ($preferred !== null && (! $preferred->services()->where('services.id', $service->id)->exists() || $preferred->status !== Practitioner::STATUS_ACTIVE)) {
            return [null, 'cannot_perform'];
        }

        $start = $turn->instant($date, $time);
        $reasons = [];

        foreach ($candidates as $candidate) {
            $reason = $this->availability->unavailabilityReason($turn->org, $turn->location, $service, $candidate, $start, true, $ignoreBookingId, $turn->now);

            if ($reason === null) {
                return [$candidate, null];
            }

            $reasons[] = $reason;
        }

        foreach (['taken', 'outside_hours', 'policy'] as $reason) {
            if (in_array($reason, $reasons, true)) {
                return [null, $reason];
            }
        }

        return [null, 'outside_hours'];
    }

    /**
     * Les prochains jours (dates locales) où il reste au moins un créneau, à partir de demain.
     *
     * @return list<string>
     */
    public function nextDays(Turn $turn, Service $service, ?Practitioner $only, int $count = 5, ?string $ignoreBookingId = null): array
    {
        $from = $turn->today()->addDay();
        $slots = $this->availability->slots($turn->org, $turn->location, $service, $from->toDateString(), $from->addDays(20)->toDateString(), $only, true, $ignoreBookingId, $turn->now);
        $days = [];

        foreach ($slots as $slot) {
            $days[$turn->local($slot->startsAt)->toDateString()] = true;
        }

        return array_slice(array_map('strval', array_keys($days)), 0, $count);
    }

    /**
     * Rendez-vous à venir du client (confirmés ou en attente), du plus proche au plus lointain.
     *
     * @return list<Booking>
     */
    public function upcoming(Turn $turn): array
    {
        return Booking::query()
            ->where('customer_id', $turn->customer->id)
            ->whereIn('status', Booking::BLOCKING)
            ->where('starts_at', '>', $turn->now)
            ->orderBy('starts_at')
            ->get()->all();
    }

    /** Prix en centimes (celui du praticien s'il a un tarif personnalisé). */
    public function price(Service $service, ?Practitioner $practitioner): int
    {
        $custom = $practitioner === null ? null : DB::table('practitioner_services')
            ->where('practitioner_id', $practitioner->id)->where('service_id', $service->id)->value('custom_price_cents');

        return (int) ($custom ?? $service->price_cents);
    }

    /** Durée en minutes (celle du praticien si elle est personnalisée). */
    public function duration(Service $service, ?Practitioner $practitioner): int
    {
        return $practitioner === null ? $service->duration_minutes : $this->availability->durations($service, $practitioner)[0];
    }

    /** Le client peut encore déplacer ou annuler ce rendez-vous (fenêtre d'annulation de l'établissement). */
    public function canChange(Turn $turn, Booking $booking): bool
    {
        return $turn->now <= $booking->starts_at->subHours($this->policies->for($turn->org)->cancellation_window_hours);
    }

    public function booking(Turn $turn, ?string $id): ?Booking
    {
        if ($id === null) {
            return null;
        }

        return Booking::query()->where('customer_id', $turn->customer->id)->whereIn('status', Booking::BLOCKING)->find($id);
    }

    /**
     * Crée le rendez-vous (confirmé tout de suite : le client vient de confirmer dans la conversation).
     *
     * @throws SlotUnavailable
     * @throws BookingRuleViolation
     */
    public function book(Turn $turn, Service $service, Practitioner $practitioner, string $date, string $time, ?string $notes, string $attempt): Booking
    {
        return $this->core->create($turn->org, new NewBooking(
            locationId: $turn->location->id,
            serviceId: $service->id,
            practitionerId: $practitioner->id,
            customerId: $turn->customer->id,
            startsAt: $turn->instant($date, $time),
            source: 'whatsapp',
            actor: Actor::customer($turn->customer->id),
            hold: false,
            enforcePolicies: true,
            notes: $notes,
            // Un message rejoué ne crée pas deux rendez-vous ; une nouvelle demande du même client (autre tentative) en crée un nouveau.
            idempotencyKey: 'bot-'.sha1($attempt.'|'.$practitioner->id.'|'.$date.'|'.$time),
        ));
    }

    /**
     * Déplace le rendez-vous (nouvelle heure et/ou nouveau praticien).
     *
     * @throws SlotUnavailable
     * @throws BookingRuleViolation
     */
    public function move(Turn $turn, Booking $booking, Practitioner $practitioner, string $date, string $time): Booking
    {
        return $this->core->move($turn->org, $booking, $turn->instant($date, $time), Actor::customer($turn->customer->id), $practitioner->id);
    }

    /**
     * Change le service : l'ancien rendez-vous est annulé et le nouveau créé en une seule opération (tout ou rien).
     *
     * @throws SlotUnavailable
     * @throws BookingRuleViolation
     */
    public function changeService(Turn $turn, Booking $booking, Service $service, Practitioner $practitioner, string $date, string $time, string $attempt): Booking
    {
        return DB::transaction(function () use ($turn, $booking, $service, $practitioner, $date, $time, $attempt): Booking {
            $notes = $booking->notes_enc;
            $this->core->cancel($turn->org, $booking, Actor::customer($turn->customer->id), __('conversation.service_change_reason', [], $turn->org->locale));

            return $this->book($turn, $service, $practitioner, $date, $time, $notes, $attempt);
        });
    }

    /**
     * @throws BookingRuleViolation
     */
    public function cancel(Turn $turn, Booking $booking): Booking
    {
        return $this->core->cancel($turn->org, $booking, Actor::customer($turn->customer->id), __('conversation.cancel_reason', [], $turn->org->locale));
    }
}
