<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Concerns\RespondsWithJson;
use App\Modules\Api\Models\ApiClient;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Réservations pour le bot et n8n. Les écritures passent TOUTES par le cœur de réservation (jamais par la base).
 *
 * Création : en-tête `Idempotency-Key` obligatoire (rejeu d'un webhook = même réservation).
 * Agir « pour le client » (`acting_as` = customer, par défaut) exige le numéro du client : il est comparé à celui de la
 * réservation, et la fenêtre d'annulation de la politique s'applique. `business` agit comme l'établissement.
 */
final class BookingApiController
{
    use RespondsWithJson;

    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/';

    private const PHONE = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        private readonly CurrentTenant $current,
        private readonly BookingCore $core,
        private readonly CustomerDirectory $customers,
    ) {}

    /** Prochains rendez-vous d'un client (« mes rendez-vous »). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_phone' => ['required', 'string', 'regex:'.self::PHONE]]);

        $customer = Customer::query()->where('phone_e164', $data['customer_phone'])->first();

        $bookings = $customer === null ? collect() : Booking::query()->where('customer_id', $customer->id)
            ->whereIn('status', [Booking::CONFIRMED, Booking::PENDING])->where('starts_at', '>=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('starts_at')->limit(20)->get();

        return new JsonResponse(['data' => $bookings->map(fn (Booking $booking): array => $this->booking($booking))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '' || mb_strlen($key) > 60) {
            return $this->error('idempotency_key_required', 'L\'en-tête Idempotency-Key (60 caractères maximum) est obligatoire.', 400);
        }

        $data = $request->validate([
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
            'starts_at' => ['required', 'string', 'regex:'.self::ISO_WITH_OFFSET],
            'customer_phone' => ['required', 'string', 'regex:'.self::PHONE],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_language' => ['nullable', 'in:fr,nl,en'],
            'hold' => ['nullable', 'boolean'],
            'source' => ['nullable', 'in:whatsapp,api'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = $this->client($request);
        $organization = $this->current->organization() ?? abort(403);
        $customer = $this->customers->findOrCreate($organization, $data['customer_phone'], $data['customer_name'] ?? null, $data['customer_language'] ?? null);
        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();

        try {
            $booking = $this->core->create($organization, new NewBooking(
                locationId: $data['location_id'],
                serviceId: $data['service_id'],
                practitionerId: $data['practitioner_id'],
                customerId: $customer->id,
                startsAt: $startsAt,
                source: $data['source'] ?? 'api',
                actor: $this->customerActor($customer),
                hold: (bool) ($data['hold'] ?? false),
                enforcePolicies: true,
                notes: $data['notes'] ?? null,
                idempotencyKey: $client->id.':'.$key,
            ));
        } catch (SlotUnavailable $e) {
            return $this->slotUnavailable($e);
        } catch (BookingRuleViolation $e) {
            return $this->ruleViolation($e);
        }

        // Même clé mais demande différente : ce n'est pas un rejeu, c'est une erreur du client.
        if ($booking->customer_id !== $customer->id || ! $booking->starts_at->equalTo($startsAt) || $booking->service_id !== $data['service_id'] || $booking->practitioner_id !== $data['practitioner_id']) {
            return $this->error('idempotency_conflict', 'Cette clé d\'idempotence a déjà servi pour une autre demande.', 409);
        }

        return new JsonResponse(['data' => $this->booking($booking)], $booking->wasRecentlyCreated ? 201 : 200);
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        return $this->act($request, $booking, fn (Actor $actor) => $this->core->confirm($booking, $actor));
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->act($request, $booking, fn (Actor $actor) => $this->core->cancel($this->current->organization() ?? abort(403), $booking, $actor, $reason));
    }

    public function move(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'string', 'regex:'.self::ISO_WITH_OFFSET],
            'practitioner_id' => ['nullable', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
        ]);

        return $this->act($request, $booking, fn (Actor $actor) => $this->core->move(
            $this->current->organization() ?? abort(403),
            $booking,
            CarbonImmutable::parse($data['starts_at'])->utc(),
            $actor,
            $data['practitioner_id'] ?? null,
            true,
        ));
    }

    /**
     * @param  callable(Actor): mixed  $action
     */
    private function act(Request $request, Booking $booking, callable $action): JsonResponse
    {
        $actor = $this->actorFor($request, $booking);

        if ($actor === null) {
            return $this->error('customer_mismatch', 'Ce rendez-vous n\'appartient pas à ce client.', 403);
        }

        try {
            $action($actor);
        } catch (SlotUnavailable $e) {
            return $this->slotUnavailable($e);
        } catch (BookingRuleViolation $e) {
            return $this->ruleViolation($e);
        }

        return new JsonResponse(['data' => $this->booking($booking->refresh())]);
    }

    /** Acteur de l'opération, ou null quand le numéro fourni n'est pas celui du client de la réservation. */
    private function actorFor(Request $request, Booking $booking): ?Actor
    {
        $data = $request->validate([
            'acting_as' => ['nullable', 'in:customer,business'],
            'customer_phone' => ['nullable', 'string', 'regex:'.self::PHONE],
        ]);

        if (($data['acting_as'] ?? 'customer') === 'business') {
            return Actor::api($this->client($request)->id);
        }

        $customer = Customer::query()->find($booking->customer_id);

        return $customer !== null && ($data['customer_phone'] ?? null) === $customer->phone_e164 ? Actor::customer($customer->id) : null;
    }

    private function customerActor(Customer $customer): Actor
    {
        return Actor::customer($customer->id);
    }

    private function client(Request $request): ApiClient
    {
        $client = $request->attributes->get('api_client');

        return $client instanceof ApiClient ? $client : abort(401);
    }
}
