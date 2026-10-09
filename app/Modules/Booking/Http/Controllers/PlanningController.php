<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Http\Requests\MoveBookingRequest;
use App\Modules\Booking\Http\Requests\StoreBookingRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingTip;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingNotifier;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\BookingTips;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Booking\Support\PlanningRange;
use App\Modules\Booking\Support\PlanningWindow;
use App\Modules\Booking\Support\Slot;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Loyalty\Services\LoyaltyDirectory;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planning du dashboard (flowchart 2B) : vue semaine (lundi → dimanche), création, déplacement et annulation de rendez-vous.
 * Toutes les écritures passent par le cœur de réservation ; les droits suivent docs/02 §1.
 */
final class PlanningController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly PlanningAccess $access,
        private readonly BookingCore $core,
        private readonly CustomerDirectory $customers,
        private readonly BookingNotifier $notifier,
        private readonly BookingTips $tips,
    ) {}

    public function index(Request $request): Response
    {
        $locations = $this->access->locations();
        $location = $locations->firstWhere('id', $request->query('location')) ?? $locations->first();

        if ($location === null) {
            return Inertia::render('Planning/Index', ['locations' => [], 'location' => null]);
        }

        $timezone = $location->timezone;
        $date = $this->dateFrom($request->query('date'), $timezone);
        // Vue choisie (jour, 3 jours, semaine de travail, praticiens) : elle décide des jours chargés ; la date reste le jour mis en avant.
        $view = PlanningWindow::view($request->query('view'));
        $days = PlanningWindow::days($view, $date);
        $windowStart = CarbonImmutable::parse($days[0], $timezone)->startOfDay();
        $windowEnd = CarbonImmutable::parse($days[count($days) - 1], $timezone)->startOfDay()->addDay();

        $practitioners = Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)
            ->whereHas('schedules', fn ($query) => $query->where('location_id', $location->id))
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioners.id', $this->access->practitionerId()))
            ->orderBy('position')->get();

        $schedules = PractitionerSchedule::query()->where('location_id', $location->id)->get();

        $bookings = Booking::query()->where('location_id', $location->id)
            ->where('starts_at', '>=', $windowStart->utc())->where('starts_at', '<', $windowEnd->utc())
            ->whereIn('status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioner_id', $this->access->practitionerId()))
            ->orderBy('starts_at')->get();

        $customers = DB::table('customers')->whereIn('id', $bookings->pluck('customer_id'))->get()->keyBy('id');
        $services = Service::query()->orderBy('position')->get();
        $serviceNames = $services->pluck('name', 'id');
        // Nom du praticien même s'il est devenu inactif ou supprimé depuis : le rendez-vous doit toujours dire à qui il est attribué.
        // Pourboires des rendez-vous affichés : leurs bénéficiaires sont nommés comme les praticiens des rendez-vous.
        $tips = BookingTip::query()->with('allocations')->whereIn('booking_id', $bookings->pluck('id'))->get()->keyBy('booking_id');
        $recipientIds = $tips->flatMap(fn (BookingTip $tip) => $tip->allocations->pluck('practitioner_id'));
        $practitionerNames = Practitioner::withTrashed()->whereIn('id', $bookings->pluck('practitioner_id')->merge($recipientIds)->unique())->pluck('name', 'id');
        $seesNotes = $this->access->seesNotes();

        $open = BusinessHour::query()->where('location_id', $location->id)->get();
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $localMinutes = fn (CarbonImmutable $instant): int => (int) $instant->setTimezone($timezone)->format('G') * 60 + (int) $instant->setTimezone($timezone)->format('i');

        // Fidélité (docs/27) : progression du client et récompense appliquée, affichées dans le détail du rendez-vous.
        $loyaltyDirectory = app(LoyaltyDirectory::class);
        $loyalty = $loyaltyDirectory->forBookings($loyaltyDirectory->program(), $bookings->pluck('customer_id')->unique()->values()->all(), $bookings->pluck('id')->all());

        $bookingRows = $bookings->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'practitioner_id' => $booking->practitioner_id,
            'practitioner_name' => $practitionerNames[$booking->practitioner_id] ?? null,
            'service_id' => $booking->service_id,
            'customer' => ['id' => $booking->customer_id, 'name' => $customers[$booking->customer_id]->name ?? null, 'phone' => $customers[$booking->customer_id]->phone_e164 ?? null],
            'service' => $serviceNames[$booking->service_id] ?? null,
            'date' => $booking->starts_at->setTimezone($timezone)->toDateString(),
            'starts' => $booking->starts_at->setTimezone($timezone)->format('H:i'),
            'ends' => $booking->ends_at->setTimezone($timezone)->format('H:i'),
            'start_minute' => $localMinutes($booking->starts_at),
            'end_minute' => $localMinutes($booking->ends_at) ?: 1440,
            'status' => $booking->status,
            'price_cents' => $booking->price_cents,
            'currency' => trim($booking->currency),
            'payment_status' => $booking->payment_status,
            'amount_collected_cents' => $booking->amount_collected_cents,
            'tip' => $this->tipPayload($tips->get($booking->id), $practitionerNames),
            'loyalty' => $loyalty[$booking->id] ?? null,
            'notes' => $seesNotes ? $booking->notes_enc : null,
            'is_past' => $booking->starts_at <= now(),
        ])->values()->all();

        // Plage horaire affichée : 2 h avant l'ouverture et 2 h après la fermeture (horaires du site et des praticiens de la semaine),
        // élargie aux rendez-vous de la semaine pour qu'aucun ne soit caché.
        $shownWeekdays = array_map(fn (string $day): int => CarbonImmutable::parse($day)->isoWeekday(), $days);
        $range = PlanningRange::compute(
            [...$open->whereIn('weekday', $shownWeekdays)->map(fn (BusinessHour $hour): array => [$minutes($hour->starts_at), $minutes($hour->ends_at)])->all(), ...$schedules->whereIn('weekday', $shownWeekdays)->map(fn (PractitionerSchedule $slot): array => [$minutes($slot->starts_at), $minutes($slot->ends_at)])->all()],
            array_map(fn (array $row): array => [$row['start_minute'], $row['end_minute']], $bookingRows),
        );

        return Inertia::render('Planning/Index', [
            'locations' => $locations->map(fn (Location $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'location' => ['id' => $location->id, 'name' => $location->name, 'timezone' => $timezone],
            'date' => $date,
            'view' => $view,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'range' => $range,
            'week' => [
                'start' => $days[0],
                'end' => $days[count($days) - 1],
                'days' => collect($days)->map(fn (string $day): array => [
                    'date' => $day,
                    'open' => $open->where('weekday', CarbonImmutable::parse($day)->isoWeekday())->map(fn (BusinessHour $hour): array => ['start' => $minutes($hour->starts_at), 'end' => $minutes($hour->ends_at)])->values()->all(),
                ])->all(),
            ],
            'practitioners' => $practitioners->map(fn (Practitioner $practitioner): array => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'color' => $practitioner->color,
                'photo_version' => $practitioner->photoVersion(),
                // Jours et heures de travail du profil, par jour de la semaine ISO (1 = lundi … 7 = dimanche).
                'schedule' => $schedules->where('practitioner_id', $practitioner->id)->groupBy('weekday')
                    ->map(fn ($slots): array => $slots->map(fn (PractitionerSchedule $slot): array => ['start' => $minutes($slot->starts_at), 'end' => $minutes($slot->ends_at)])->values()->all())->all(),
            ])->values()->all(),
            'bookings' => $bookingRows,
            'services' => $services->where('is_active', true)->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'practitioner_ids' => DB::table('practitioner_services')->where('service_id', $service->id)->pluck('practitioner_id')->all(),
            ])->values()->all(),
            'canReassign' => ! $this->access->isPractitioner(),
            'canCreate' => ! $this->access->isPractitioner() || $this->access->practitionerId() !== null,
            'seesNotes' => $seesNotes,
        ]);
    }

    /** Nouveau rendez-vous saisi par le personnel : ignore délai minimum et horizon, respecte horaires et double réservation. */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $location = Location::query()->findOrFail($request->string('location_id')->toString());
        abort_unless($this->access->canAccessLocation($location->id), 403);

        $practitionerId = $request->string('practitioner_id')->toString();
        abort_if($this->access->isPractitioner() && $this->access->practitionerId() !== $practitionerId, 403);

        $customer = $this->customers->findOrCreate($this->organization(), $request->phoneE164(), $request->input('name'), app()->getLocale());

        $data = new NewBooking(
            locationId: $location->id,
            serviceId: $request->string('service_id')->toString(),
            practitionerId: $practitionerId,
            customerId: $customer->id,
            startsAt: $request->startsAt($location->timezone),
            source: 'dashboard',
            actor: Actor::user((string) auth()->id()),
            enforcePolicies: false,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return $this->attempt(fn () => $this->core->create($this->organization(), $data), 'ui.planning.created', $request->boolean('notify') ? 'confirmed' : null);
    }

    public function move(MoveBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);

        $practitionerId = $request->filled('practitioner_id') ? $request->string('practitioner_id')->toString() : null;
        abort_if($this->access->isPractitioner() && $practitionerId !== null && $practitionerId !== $booking->practitioner_id, 403);

        $location = Location::query()->findOrFail($booking->location_id);
        $start = $request->startsAt($location->timezone);

        return $this->attempt(fn () => $this->core->move($this->organization(), $booking, $start, Actor::user((string) auth()->id()), $practitionerId, false), 'ui.planning.moved', $request->boolean('notify') ? 'moved' : null);
    }

    /**
     * Remplace le praticien d'un rendez-vous sans toucher à l'heure. Mêmes règles qu'un déplacement (praticien actif qui réalise
     * le service, créneau libre) ; le praticien lui-même ne peut pas passer son rendez-vous à un autre (docs/02 §1).
     */
    public function reassign(Request $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);
        abort_if($this->access->isPractitioner(), 403);

        $data = $request->validate(['practitioner_id' => ['required', 'uuid']]);

        if ($data['practitioner_id'] === $booking->practitioner_id) {
            return back();
        }

        // Le changement est consigné dans booking_events (« moved », ancien et nouveau praticien) par le cœur de réservation.
        return $this->attempt(fn () => $this->core->move($this->organization(), $booking, $booking->starts_at, Actor::user((string) auth()->id()), $data['practitioner_id'], false), 'ui.planning.practitioner_changed');
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->core->cancel($this->organization(), $booking, Actor::user((string) auth()->id()), $data['reason'] ?? null), 'ui.planning.cancelled', $request->boolean('notify') ? 'cancelled' : null);
    }

    /** Marque le rendez-vous comme honoré ou comme absence du client. */
    public function finish(Request $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);
        $data = $request->validate(['outcome' => ['required', 'in:completed,no_show']]);
        $actor = Actor::user((string) auth()->id());

        return $this->attempt(
            fn () => $data['outcome'] === 'completed' ? $this->core->markCompleted($booking, $actor) : $this->core->markNoShow($booking, $actor),
            'ui.planning.updated',
        );
    }

    /**
     * Paiement du rendez-vous (prix encaissé, statut, moyen) et, s'il est envoyé, son pourboire : un montant SÉPARÉ, avec sa
     * répartition entre praticiens. Les deux s'enregistrent ensemble ou pas du tout. Mêmes droits que le paiement.
     */
    public function payment(Request $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);
        $data = $request->validate([
            'payment_status' => ['required', 'in:unpaid,paid_onsite,partially_paid,waived,written_off'],
            'amount' => ['nullable', 'string', 'max:12'],
            'method' => ['nullable', 'in:cash,card,other'],
            'tip' => ['nullable', 'string', 'max:12'],
            'tip_allocations' => ['nullable', 'array', 'max:20'],
            'tip_allocations.*.practitioner_id' => ['required', 'uuid'],
            'tip_allocations.*.amount' => ['required', 'string', 'max:12'],
        ]);

        $amount = ($data['amount'] ?? '') === '' ? null : Money::parseToCents($data['amount']);

        if (($data['amount'] ?? '') !== '' && $amount === null) {
            return back()->withErrors(['amount' => __('ui.planning.errors.amount_invalid')]);
        }

        // Pourboire : absent de la requête = on n'y touche pas ; vide ou 0 = on le supprime.
        $withTip = $request->has('tip');
        $tip = null;
        $allocations = [];

        if ($withTip && trim((string) ($data['tip'] ?? '')) !== '') {
            $tip = Money::parseToCents((string) $data['tip']);

            if ($tip === null) {
                return back()->withErrors(['tip' => __('ui.planning.errors.tip_invalid')]);
            }

            foreach ($data['tip_allocations'] ?? [] as $row) {
                $part = Money::parseToCents((string) $row['amount']);

                if ($part === null) {
                    return back()->withErrors(['tip_allocations' => __('ui.planning.errors.tip_invalid')]);
                }

                $allocations[] = ['practitioner_id' => (string) $row['practitioner_id'], 'amount_cents' => $part];
            }
        }

        $actor = Actor::user((string) auth()->id());

        return $this->attempt(fn () => DB::transaction(function () use ($booking, $data, $amount, $withTip, $tip, $allocations, $actor): Booking {
            $updated = $this->core->recordPayment($booking, $data['payment_status'], $amount, $data['method'] ?? null, $actor);

            if ($withTip) {
                $this->tips->record($updated, $tip, $allocations, $actor);
            }

            return $updated;
        }), 'ui.planning.updated');
    }

    /**
     * @param  Collection<string, string>  $names
     * @return array{amount_cents: int, paid_at: string, allocations: list<array{practitioner_id: string, practitioner_name: string|null, amount_cents: int}>}|null
     */
    private function tipPayload(?BookingTip $tip, Collection $names): ?array
    {
        if ($tip === null) {
            return null;
        }

        return [
            'amount_cents' => $tip->amount_cents,
            'paid_at' => $tip->paid_at->toIso8601String(),
            'allocations' => $tip->allocations->map(fn ($row): array => ['practitioner_id' => $row->practitioner_id, 'practitioner_name' => $names[$row->practitioner_id] ?? null, 'amount_cents' => $row->amount_cents])->values()->all(),
        ];
    }

    /**
     * Exécute une action du cœur de réservation et traduit ses refus en messages clairs.
     * E10 : quand le créneau n'est plus libre, les 3 prochains créneaux sont proposés.
     *
     * Si $notify est donné (confirmed, moved ou cancelled), le client est prévenu par WhatsApp APRÈS l'écriture ; un échec d'envoi
     * n'annule rien et est signalé à l'utilisateur.
     *
     * @param  callable(): Booking  $action
     * @param  'confirmed'|'moved'|'cancelled'|null  $notify
     */
    private function attempt(callable $action, string $successKey, ?string $notify = null): RedirectResponse
    {
        try {
            $booking = $action();
        } catch (SlotUnavailable $e) {
            return back()->withErrors(['slot' => __("ui.planning.errors.slot_{$e->reason}")])
                ->with('suggestions', array_map(fn (Slot $slot): array => $this->suggestion($slot), $e->suggestions));
        } catch (BookingRuleViolation $e) {
            return back()->withErrors(['booking' => __("ui.planning.errors.{$e->rule}")]);
        }

        if ($notify === null) {
            return back()->with('status', __($successKey));
        }

        return $this->notifier->{$notify}($this->organization(), $booking)
            ? back()->with('status', __($successKey).' '.__('ui.planning.notified'))
            : back()->with('status', __($successKey))->with('warning', __('ui.planning.notify_failed'));
    }

    /**
     * @return array{date: string, time: string, practitioner_id: string}
     */
    private function suggestion(Slot $slot): array
    {
        $timezone = Location::query()->whereKey($slot->locationId)->value('timezone') ?? 'UTC';
        $local = $slot->startsAt->setTimezone($timezone);

        return ['date' => $local->toDateString(), 'time' => $local->format('H:i'), 'practitioner_id' => $slot->practitionerId];
    }

    private function dateFrom(mixed $value, string $timezone): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && CarbonImmutable::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value) {
            return $value;
        }

        return CarbonImmutable::now($timezone)->toDateString();
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
