<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CŒUR DE RÉSERVATION : le SEUL composant autorisé à écrire l'état des réservations (docs/01 §2).
 * Le dashboard, les commandes WhatsApp du personnel, le bot et l'API passent tous par ici : mêmes règles, même journal.
 *
 * Garanties :
 * - pas de double-booking, même en cas de requêtes simultanées (contrainte d'exclusion de la base, ultime garde-fou) ;
 * - chaque changement d'état est journalisé dans `booking_events` (ajout seul) ;
 * - la création est idempotente (clé d'idempotence) pour supporter les rejeux de webhooks.
 */
final class BookingCore
{
    /** Code SQLSTATE d'une violation de contrainte d'exclusion (double-booking). */
    private const EXCLUSION_VIOLATION = '23P01';

    /** Code SQLSTATE d'une violation d'unicité. */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PolicyService $policies,
    ) {}

    /**
     * @throws SlotUnavailable créneau non réservable (E10), avec les prochains créneaux libres
     * @throws BookingRuleViolation
     */
    public function create(Organization $organization, NewBooking $data): Booking
    {
        if ($data->idempotencyKey !== null && ($existing = Booking::query()->where('idempotency_key', $data->idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $location = Location::query()->findOrFail($data->locationId);
        $service = Service::query()->findOrFail($data->serviceId);
        $practitioner = Practitioner::query()->findOrFail($data->practitionerId);
        $customer = Customer::query()->findOrFail($data->customerId);

        if ($customer->is_blocked) {
            throw new BookingRuleViolation(BookingRuleViolation::CUSTOMER_BLOCKED);
        }

        if (! $service->is_active || ($data->enforcePolicies && ! $service->bookable_online)) {
            throw new BookingRuleViolation(BookingRuleViolation::SERVICE_UNAVAILABLE);
        }

        $offers = $practitioner->services()->where('services.id', $service->id)->exists();

        if ($practitioner->status !== Practitioner::STATUS_ACTIVE || ! $offers) {
            throw new BookingRuleViolation(BookingRuleViolation::PRACTITIONER_CANNOT_PERFORM);
        }

        $this->assertAvailable($organization, $location, $service, $practitioner, $data->startsAt, $data->enforcePolicies, null);

        [$duration, $before, $after] = $this->availability->durations($service, $practitioner);
        $start = $data->startsAt->utc();
        $now = CarbonImmutable::now();
        $price = $this->priceFor($service, $practitioner);

        try {
            return DB::transaction(function () use ($organization, $data, $service, $practitioner, $start, $duration, $before, $after, $now, $price): Booking {
                // Un blocage expiré occupe encore la contrainte de la base tant qu'il n'est pas libéré : on le libère d'abord.
                $this->releaseExpiredHolds($practitioner->id);

                $booking = Booking::query()->create([
                    'org_id' => $organization->id,
                    'location_id' => $data->locationId,
                    'customer_id' => $data->customerId,
                    'practitioner_id' => $practitioner->id,
                    'service_id' => $service->id,
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($duration),
                    'blocked_from' => $start->subMinutes($before),
                    'blocked_until' => $start->addMinutes($duration + $after),
                    'status' => $data->hold ? Booking::PENDING : Booking::CONFIRMED,
                    'source' => $data->source,
                    'price_cents' => $price,
                    'vat_rate' => $service->vat_rate,
                    'currency' => 'EUR',
                    'notes_enc' => $data->notes,
                    'expires_at' => $data->hold ? $now->addMinutes($this->policies->for($organization)->hold_minutes) : null,
                    'idempotency_key' => $data->idempotencyKey,
                    'created_by' => $data->actor->type === 'user' ? $data->actor->id : null,
                ]);

                $this->record($booking, $data->actor, 'created', ['status' => $booking->status, 'starts_at' => $start->toIso8601String(), 'practitioner_id' => $practitioner->id, 'source' => $data->source]);

                return $booking;
            });
        } catch (QueryException $e) {
            if ($this->sqlState($e) === self::EXCLUSION_VIOLATION) {
                throw $this->taken($organization, $location, $service, $practitioner, $data->enforcePolicies, null);
            }

            if ($this->sqlState($e) === self::UNIQUE_VIOLATION && $data->idempotencyKey !== null) {
                // Deux requêtes identiques simultanées : la seconde renvoie la réservation créée par la première.
                return Booking::query()->where('idempotency_key', $data->idempotencyKey)->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * Confirme un créneau bloqué en attente (le client a confirmé dans WhatsApp avant l'expiration).
     *
     * @throws BookingRuleViolation
     */
    public function confirm(Booking $booking, Actor $actor): Booking
    {
        if ($booking->status !== Booking::PENDING) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_TRANSITION);
        }

        if ($booking->expires_at !== null && $booking->expires_at <= CarbonImmutable::now()) {
            $this->expire($booking);

            throw new BookingRuleViolation(BookingRuleViolation::HOLD_EXPIRED);
        }

        $booking->forceFill(['status' => Booking::CONFIRMED, 'expires_at' => null])->save();
        $this->record($booking, $actor, 'confirmed', ['status' => [Booking::PENDING, Booking::CONFIRMED]]);

        return $booking;
    }

    /**
     * Annule un rendez-vous. Le client est soumis à la fenêtre d'annulation de la politique ; le commerce peut toujours annuler.
     *
     * @throws BookingRuleViolation
     */
    public function cancel(Organization $organization, Booking $booking, Actor $actor, ?string $reason = null): Booking
    {
        $this->assertBlocking($booking);
        $this->assertWithinCancellationWindow($organization, $booking, $actor);

        $status = $actor->isCustomer() ? Booking::CANCELLED_BY_CUSTOMER : Booking::CANCELLED_BY_BUSINESS;
        $before = $booking->status;

        $booking->forceFill([
            'status' => $status,
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => $reason !== null ? mb_substr(trim($reason), 0, 255) : null,
            'expires_at' => null,
        ])->save();

        $this->record($booking, $actor, 'cancelled', ['status' => [$before, $status], 'reason' => $booking->cancellation_reason]);

        return $booking;
    }

    /**
     * Déplace un rendez-vous (nouvelle heure, et éventuellement nouveau praticien). Atomique : le créneau d'origine est libéré
     * et le nouveau occupé en une seule opération.
     *
     * @throws SlotUnavailable
     * @throws BookingRuleViolation
     */
    public function move(Organization $organization, Booking $booking, CarbonImmutable $newStart, Actor $actor, ?string $practitionerId = null, bool $enforcePolicies = true): Booking
    {
        $this->assertBlocking($booking);
        $this->assertWithinCancellationWindow($organization, $booking, $actor);

        $location = Location::query()->findOrFail($booking->location_id);
        $service = Service::query()->findOrFail($booking->service_id);
        $practitioner = Practitioner::query()->findOrFail($practitionerId ?? $booking->practitioner_id);

        if ($practitioner->status !== Practitioner::STATUS_ACTIVE || ! $practitioner->services()->where('services.id', $service->id)->exists()) {
            throw new BookingRuleViolation(BookingRuleViolation::PRACTITIONER_CANNOT_PERFORM);
        }

        $this->assertAvailable($organization, $location, $service, $practitioner, $newStart, $enforcePolicies, $booking->id);

        [$duration, $before, $after] = $this->availability->durations($service, $practitioner);
        $start = $newStart->utc();
        $previous = ['starts_at' => $booking->starts_at->toIso8601String(), 'practitioner_id' => $booking->practitioner_id];

        try {
            DB::transaction(function () use ($booking, $practitioner, $start, $duration, $before, $after): void {
                $this->releaseExpiredHolds($practitioner->id);

                $booking->forceFill([
                    'practitioner_id' => $practitioner->id,
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($duration),
                    'blocked_from' => $start->subMinutes($before),
                    'blocked_until' => $start->addMinutes($duration + $after),
                    // Nouvel horaire : le client doit recevoir un rappel pour ce nouvel horaire.
                    'reminder_sent_at' => null,
                    'reminder_attempts' => 0,
                ])->save();
            });
        } catch (QueryException $e) {
            if ($this->sqlState($e) === self::EXCLUSION_VIOLATION) {
                $booking->refresh();

                throw $this->taken($organization, $location, $service, $practitioner, $enforcePolicies, $booking->id);
            }

            throw $e;
        }

        $this->record($booking, $actor, 'moved', ['from' => $previous, 'to' => ['starts_at' => $start->toIso8601String(), 'practitioner_id' => $practitioner->id]]);

        return $booking;
    }

    /**
     * Le client ne s'est pas présenté. Uniquement pour un rendez-vous confirmé dont l'heure est passée.
     *
     * @throws BookingRuleViolation
     */
    public function markNoShow(Booking $booking, Actor $actor): Booking
    {
        return $this->finish($booking, $actor, Booking::NO_SHOW, 'no_show');
    }

    /**
     * Le rendez-vous a eu lieu. Uniquement pour un rendez-vous confirmé dont l'heure est passée.
     *
     * @throws BookingRuleViolation
     */
    public function markCompleted(Booking $booking, Actor $actor): Booking
    {
        return $this->finish($booking, $actor, Booking::COMPLETED, 'completed');
    }

    /**
     * Suivi du paiement sur place (aucun paiement en ligne). Le montant encaissé ne peut pas dépasser le prix.
     *
     * @throws BookingRuleViolation
     */
    public function recordPayment(Booking $booking, string $paymentStatus, ?int $amountCents, ?string $method, Actor $actor): Booking
    {
        if (! in_array($booking->status, [Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW], true)) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_TRANSITION);
        }

        if (! in_array($paymentStatus, ['unpaid', 'paid_onsite', 'partially_paid', 'waived', 'written_off'], true)) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_PAYMENT_STATUS);
        }

        $amount = match ($paymentStatus) {
            'paid_onsite' => $amountCents ?? $booking->price_cents,
            'partially_paid' => $amountCents ?? 0,
            default => 0,
        };

        if ($amount > $booking->price_cents || ($paymentStatus === 'partially_paid' && ($amount <= 0 || $amount >= $booking->price_cents))) {
            throw new BookingRuleViolation(BookingRuleViolation::AMOUNT_EXCEEDS_PRICE);
        }

        $before = ['payment_status' => $booking->payment_status, 'amount_collected_cents' => $booking->amount_collected_cents];
        $booking->forceFill(['payment_status' => $paymentStatus, 'amount_collected_cents' => $amount, 'payment_method' => $method])->save();

        $this->record($booking, $actor, 'payment_updated', ['from' => $before, 'to' => ['payment_status' => $paymentStatus, 'amount_collected_cents' => $amount]]);

        return $booking;
    }

    /**
     * Met à jour les notes (chiffrées au repos). Le contenu n'est jamais copié dans le journal : seule sa longueur l'est.
     */
    public function updateNotes(Booking $booking, ?string $notes, Actor $actor): Booking
    {
        $booking->forceFill(['notes_enc' => $notes === null || trim($notes) === '' ? null : trim($notes)])->save();

        $this->record($booking, $actor, 'notes_updated', ['length' => mb_strlen((string) $booking->notes_enc)]);

        return $booking;
    }

    /**
     * Réserve l'envoi du rappel d'un rendez-vous confirmé : renvoie faux si un rappel est déjà parti (ou en cours d'envoi).
     * L'opération est atomique : deux exécutions simultanées ne peuvent pas toutes deux réussir.
     */
    public function claimReminder(Booking $booking): bool
    {
        $claimed = Booking::query()->whereKey($booking->id)->where('status', Booking::CONFIRMED)->whereNull('reminder_sent_at')
            ->update(['reminder_sent_at' => CarbonImmutable::now()]);

        if ($claimed === 0) {
            return false;
        }

        $booking->refresh();
        $this->record($booking, Actor::system(), 'reminder_sent');

        return true;
    }

    /** L'envoi du rappel a échoué : il sera retenté au prochain passage (3 tentatives au plus). */
    public function releaseReminder(Booking $booking, string $errorCode): void
    {
        $booking->forceFill(['reminder_sent_at' => null, 'reminder_attempts' => $booking->reminder_attempts + 1])->save();
        $this->record($booking, Actor::system(), 'reminder_failed', ['error' => $errorCode, 'attempts' => $booking->reminder_attempts]);
    }

    /**
     * Libère les créneaux en attente dont le blocage a expiré (tâche planifiée). Sans praticien : tous ceux de l'organisation.
     *
     * @return int nombre de blocages libérés
     */
    public function releaseExpiredHolds(?string $practitionerId = null): int
    {
        $expired = Booking::query()->where('status', Booking::PENDING)->where('expires_at', '<=', CarbonImmutable::now())
            ->when($practitionerId !== null, fn ($query) => $query->where('practitioner_id', $practitionerId))
            ->get();

        foreach ($expired as $booking) {
            $this->expire($booking);
        }

        return $expired->count();
    }

    private function expire(Booking $booking): void
    {
        $booking->forceFill(['status' => Booking::EXPIRED])->save();
        $this->record($booking, Actor::system(), 'expired', ['status' => [Booking::PENDING, Booking::EXPIRED]]);
    }

    private function finish(Booking $booking, Actor $actor, string $status, string $action): Booking
    {
        if ($booking->status !== Booking::CONFIRMED || $booking->starts_at > CarbonImmutable::now()) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_TRANSITION);
        }

        $booking->forceFill(['status' => $status])->save();
        $this->record($booking, $actor, $action, ['status' => [Booking::CONFIRMED, $status]]);

        return $booking;
    }

    private function assertBlocking(Booking $booking): void
    {
        if (! $booking->isBlocking()) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_TRANSITION);
        }
    }

    private function assertWithinCancellationWindow(Organization $organization, Booking $booking, Actor $actor): void
    {
        if (! $actor->isCustomer()) {
            return;
        }

        $limit = $booking->starts_at->subHours($this->policies->for($organization)->cancellation_window_hours);

        if (CarbonImmutable::now() > $limit) {
            throw new BookingRuleViolation(BookingRuleViolation::CANCELLATION_TOO_LATE);
        }
    }

    /**
     * @throws SlotUnavailable
     */
    private function assertAvailable(Organization $organization, Location $location, Service $service, Practitioner $practitioner, CarbonImmutable $startsAt, bool $enforcePolicies, ?string $ignoreBookingId): void
    {
        $reason = $this->availability->unavailabilityReason($organization, $location, $service, $practitioner, $startsAt, $enforcePolicies, $ignoreBookingId);

        if ($reason !== null) {
            throw $this->unavailable($reason, $organization, $location, $service, $practitioner, $enforcePolicies, $ignoreBookingId);
        }
    }

    private function taken(Organization $organization, Location $location, Service $service, Practitioner $practitioner, bool $enforcePolicies, ?string $ignoreBookingId): SlotUnavailable
    {
        return $this->unavailable('taken', $organization, $location, $service, $practitioner, $enforcePolicies, $ignoreBookingId);
    }

    /**
     * @param  'policy'|'outside_hours'|'taken'  $reason
     */
    private function unavailable(string $reason, Organization $organization, Location $location, Service $service, Practitioner $practitioner, bool $enforcePolicies, ?string $ignoreBookingId): SlotUnavailable
    {
        // E10 : on propose les 3 prochains créneaux libres (du même praticien).
        $suggestions = $this->availability->nextSlots($organization, $location, $service, 3, $practitioner, $enforcePolicies, $ignoreBookingId);

        return new SlotUnavailable($reason, $suggestions);
    }

    private function priceFor(Service $service, Practitioner $practitioner): int
    {
        $custom = DB::table('practitioner_services')->where('practitioner_id', $practitioner->id)->where('service_id', $service->id)->value('custom_price_cents');

        return (int) ($custom ?? $service->price_cents);
    }

    /**
     * @param  array<string, mixed>|null  $diff
     */
    private function record(Booking $booking, Actor $actor, string $action, ?array $diff = null): void
    {
        BookingEvent::query()->create([
            'org_id' => $booking->org_id,
            'booking_id' => $booking->id,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'diff' => $diff,
        ]);
    }

    private function sqlState(QueryException $e): ?string
    {
        return isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : null;
    }
}
