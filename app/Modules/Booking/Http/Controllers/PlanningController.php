<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Http\Requests\MoveBookingRequest;
use App\Modules\Booking\Http\Requests\StoreBookingRequest;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingNotifier;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Booking\Support\Slot;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        // Vue semaine : du lundi au dimanche autour de la date choisie (la date choisie reste le jour mis en avant).
        $weekStart = CarbonImmutable::parse($date, $timezone)->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
        $days = array_map(fn (int $offset): string => $weekStart->addDays($offset)->toDateString(), range(0, 6));

        $practitioners = Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)
            ->whereHas('schedules', fn ($query) => $query->where('location_id', $location->id))
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioners.id', $this->access->practitionerId()))
            ->orderBy('position')->get();

        $schedules = PractitionerSchedule::query()->where('location_id', $location->id)->get();

        $bookings = Booking::query()->where('location_id', $location->id)
            ->where('starts_at', '>=', $weekStart->utc())->where('starts_at', '<', $weekStart->addDays(7)->utc())
            ->whereIn('status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioner_id', $this->access->practitionerId()))
            ->orderBy('starts_at')->get();

        $customers = DB::table('customers')->whereIn('id', $bookings->pluck('customer_id'))->get()->keyBy('id');
        $services = Service::query()->orderBy('position')->get();
        $serviceNames = $services->pluck('name', 'id');
        // Nom du praticien même s'il est devenu inactif ou supprimé depuis : le rendez-vous doit toujours dire à qui il est attribué.
        $practitionerNames = Practitioner::withTrashed()->whereIn('id', $bookings->pluck('practitioner_id'))->pluck('name', 'id');
        $seesNotes = $this->access->seesNotes();

        $open = BusinessHour::query()->where('location_id', $location->id)->get();
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $localMinutes = fn (CarbonImmutable $instant): int => (int) $instant->setTimezone($timezone)->format('G') * 60 + (int) $instant->setTimezone($timezone)->format('i');

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
            'payment_status' => $booking->payment_status,
            'amount_collected_cents' => $booking->amount_collected_cents,
            'notes' => $seesNotes ? $booking->notes_enc : null,
            'is_past' => $booking->starts_at <= now(),
        ])->values()->all();

        // Plage horaire affichée : ouverture du site, élargie aux plannings et aux rendez-vous de la semaine.
        $edges = collect([...$open->map(fn (BusinessHour $hour): array => [$minutes($hour->starts_at), $minutes($hour->ends_at)]), ...$schedules->map(fn (PractitionerSchedule $slot): array => [$minutes($slot->starts_at), $minutes($slot->ends_at)]), ...array_map(fn (array $row): array => [$row['start_minute'], $row['end_minute']], $bookingRows)]);
        $from = $edges->isEmpty() ? 9 * 60 : (int) (floor($edges->min(fn (array $edge): int => $edge[0]) / 60) * 60);
        $to = $edges->isEmpty() ? 18 * 60 : (int) (ceil($edges->max(fn (array $edge): int => $edge[1]) / 60) * 60);

        return Inertia::render('Planning/Index', [
            'locations' => $locations->map(fn (Location $item): array => ['id' => $item->id, 'name' => $item->name])->all(),
            'location' => ['id' => $location->id, 'name' => $location->name, 'timezone' => $timezone],
            'date' => $date,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'range' => ['from' => $from, 'to' => $to],
            'week' => [
                'start' => $days[0],
                'end' => $days[6],
                'days' => collect($days)->map(fn (string $day, int $index): array => [
                    'date' => $day,
                    'open' => $open->where('weekday', $index + 1)->map(fn (BusinessHour $hour): array => ['start' => $minutes($hour->starts_at), 'end' => $minutes($hour->ends_at)])->values()->all(),
                ])->all(),
            ],
            'practitioners' => $practitioners->map(fn (Practitioner $practitioner): array => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'color' => $practitioner->color,
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

    public function payment(Request $request, Booking $booking): RedirectResponse
    {
        $this->access->authorize($booking);
        $data = $request->validate([
            'payment_status' => ['required', 'in:unpaid,paid_onsite,partially_paid,waived,written_off'],
            'amount' => ['nullable', 'string', 'max:12'],
            'method' => ['nullable', 'in:cash,card,other'],
        ]);

        $amount = ($data['amount'] ?? '') === '' ? null : Money::parseToCents($data['amount']);

        if (($data['amount'] ?? '') !== '' && $amount === null) {
            return back()->withErrors(['amount' => __('ui.planning.errors.amount_invalid')]);
        }

        return $this->attempt(fn () => $this->core->recordPayment($booking, $data['payment_status'], $amount, $data['method'] ?? null, Actor::user((string) auth()->id())), 'ui.planning.updated');
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
