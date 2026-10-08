<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Customers\Models\Customer;
use App\Modules\Privacy\Services\CustomerErasure;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Fiches clients (docs/02 §1) : propriétaire, responsable de site et réceptionniste voient tous les clients de l'organisation ;
 * un praticien ne voit que ceux qui ont (eu) rendez-vous avec lui. Les notes de rendez-vous sont masquées aux réceptionnistes.
 */
final class CustomerController
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CurrentTenant $current, private readonly PlanningAccess $access) {}

    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());

        $page = $this->visible(Customer::query())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $digits = preg_replace('/\D+/', '', $search) ?? '';

                $query->where(function (Builder $inner) use ($like, $digits): void {
                    $inner->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like);

                    if ($digits !== '') {
                        $inner->orWhere('phone_e164', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByRaw('lower(coalesce(name, phone_e164))')
            ->paginate(self::PER_PAGE)->withQueryString();

        $lastVisit = DB::table('bookings')->whereIn('customer_id', $page->pluck('id'))->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->groupBy('customer_id')->selectRaw('customer_id, max(starts_at) as last_at')->pluck('last_at', 'customer_id');

        return Inertia::render('Customers/Index', [
            'q' => $search,
            'customers' => $page->getCollection()->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone_e164,
                'is_blocked' => $customer->is_blocked,
                'last_visit' => isset($lastVisit[$customer->id]) ? substr((string) $lastVisit[$customer->id], 0, 10) : null,
            ])->values()->all(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Customer $customer): Response
    {
        $this->authorizeCustomer($customer);

        $bookings = Booking::query()->where('customer_id', $customer->id)
            ->when($this->access->isPractitioner(), fn ($query) => $query->where('practitioner_id', $this->access->practitionerId()))
            ->orderByDesc('starts_at')->limit(50)->get();

        $services = DB::table('services')->whereIn('id', $bookings->pluck('service_id'))->pluck('name', 'id');
        $timezone = (string) DB::table('locations')->orderBy('created_at')->value('timezone');
        $seesNotes = $this->access->seesNotes();

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone_e164,
                'language' => $customer->language,
                'is_blocked' => $customer->is_blocked,
            ],
            'history' => $bookings->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'date' => $booking->starts_at->setTimezone($timezone ?: 'UTC')->format('Y-m-d'),
                'time' => $booking->starts_at->setTimezone($timezone ?: 'UTC')->format('H:i'),
                'service' => $services[$booking->service_id] ?? null,
                'status' => $booking->status,
                'notes' => $seesNotes ? $booking->notes_enc : null,
            ])->all(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'language' => ['nullable', 'in:fr,nl,en'],
        ]);

        $customer->fill([
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
            'email' => filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null,
            'language' => $data['language'] ?? null,
        ]);

        $changed = array_keys($customer->getDirty());
        $customer->save();

        if ($changed !== []) {
            // Le journal ne garde que les champs modifiés, jamais leurs valeurs (données personnelles).
            Audit::record('customer.updated', 'user', (string) auth()->id(), $this->current->organization()?->id, 'customers', $customer->id, ['fields' => $changed]);
        }

        return back()->with('status', __('ui.customers.saved'));
    }

    /**
     * Efface les données personnelles d'un client à sa demande (E14). Propriétaire et responsable de site seulement (route).
     * Refusé tant qu'un rendez-vous à venir existe : il faut d'abord l'annuler.
     */
    public function erase(Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);

        try {
            app(CustomerErasure::class)->erase($this->current->organization() ?? abort(403), $customer, (string) auth()->id());
        } catch (InvalidArgumentException) {
            return back()->withErrors(['erase' => __('ui.customers.erase_upcoming')]);
        }

        return to_route('customers.index')->with('status', __('ui.customers.erased'));
    }

    /** Bloque ou débloque un client : un client bloqué ne peut plus recevoir de rendez-vous. */
    public function block(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($customer);
        $data = $request->validate(['blocked' => ['required', 'boolean']]);
        $blocked = (bool) $data['blocked'];

        if ($customer->is_blocked !== $blocked) {
            $customer->setStatus($blocked ? 'blocked' : 'active');
            Audit::record($blocked ? 'customer.blocked' : 'customer.unblocked', 'user', (string) auth()->id(), $this->current->organization()?->id, 'customers', $customer->id);
        }

        return back()->with('status', __($blocked ? 'ui.customers.blocked' : 'ui.customers.unblocked'));
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function visible(Builder $query): Builder
    {
        if (! $this->access->isPractitioner()) {
            return $query;
        }

        $practitionerId = $this->access->practitionerId();

        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('bookings')->whereColumn('bookings.customer_id', 'customers.id')->where('bookings.practitioner_id', $practitionerId));
    }

    private function authorizeCustomer(Customer $customer): void
    {
        abort_unless($this->visible(Customer::query())->whereKey($customer->id)->exists(), 403);
    }
}
