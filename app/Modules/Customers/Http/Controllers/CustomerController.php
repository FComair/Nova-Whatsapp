<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSavedView;
use App\Modules\Customers\Services\CustomerListQuery;
use App\Modules\Privacy\Services\CustomerErasure;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\OrgMember;
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

    private const MAX_TAGS = 20;

    /** Colonnes que l'écran sait afficher (une vue enregistrée peut en choisir). */
    private const COLUMNS = ['contact', 'last', 'next', 'visits', 'value', 'whatsapp', 'tags', 'status', 'source', 'created'];

    public function __construct(private readonly CurrentTenant $current, private readonly PlanningAccess $access, private readonly CustomerListQuery $list) {}

    public function index(Request $request): Response
    {
        $search = trim($request->string('q')->toString());
        $filters = CustomerListQuery::cleanFilters($request->query());
        $sort = in_array($request->query('sort'), CustomerListQuery::SORTS, true) ? (string) $request->query('sort') : 'name';
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $page = $this->list->build($search, $filters, $sort, $direction)->paginate(self::PER_PAGE)->withQueryString();
        $userId = (string) auth()->id();
        $manages = in_array($this->access->role(), [OrgMember::ROLE_OWNER, OrgMember::ROLE_SITE_MANAGER], true);

        return Inertia::render('Customers/Index', [
            'q' => $search,
            'filters' => (object) $filters,
            'sort' => $sort,
            'dir' => $direction,
            'customers' => $this->list->rows($page),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'views' => CustomerSavedView::query()->where(fn (Builder $q) => $q->where('user_id', $userId)->orWhere('shared', true))->orderBy('name')->get()
                ->map(fn (CustomerSavedView $view): array => [
                    'id' => $view->id, 'name' => $view->name, 'filters' => (object) $view->filters, 'columns' => $view->columns,
                    'sort' => $view->sort, 'dir' => $view->direction, 'shared' => $view->shared, 'mine' => $view->user_id === $userId,
                ])->all(),
            'practitioners' => Practitioner::query()->orderBy('name')->get(['id', 'name'])->map(fn (Practitioner $p): array => ['value' => $p->id, 'label' => (string) $p->name])->all(),
            'tags' => DB::table('customers')->whereNull('deleted_at')->selectRaw('distinct jsonb_array_elements_text(tags) as tag')->orderBy('tag')->limit(200)->pluck('tag')->all(),
            'can' => ['tag' => ! $this->access->isPractitioner(), 'archive' => $manages, 'share_views' => $manages],
            'scoped' => $this->access->isPractitioner(),
        ]);
    }

    /**
     * Actions groupées sur la sélection : étiqueter, retirer une étiquette, archiver, rétablir. Chaque action est contrôlée côté
     * serveur (rôle, clients visibles) et journalisée une seule fois, avec le nombre de fiches mais jamais leurs données.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:tag,untag,archive,unarchive'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid'],
            'tag' => ['required_if:action,tag,untag', 'nullable', 'string', 'max:30'],
        ]);
        $action = (string) $data['action'];

        abort_if($this->access->isPractitioner(), 403);
        abort_if(in_array($action, ['archive', 'unarchive'], true) && $this->access->role() === OrgMember::ROLE_RECEPTIONIST, 403);

        $tag = isset($data['tag']) ? trim((string) $data['tag']) : null;
        $changed = 0;

        DB::transaction(function () use ($data, $action, $tag, &$changed): void {
            foreach ($this->list->build('', ['status' => 'all'])->whereIn('customers.id', $data['ids'])->get() as $customer) {
                $tags = $customer->tags;

                if ($action === 'tag' && $tag !== null && ! in_array($tag, $tags, true) && count($tags) < self::MAX_TAGS) {
                    $customer->forceFill(['tags' => [...$tags, $tag]])->save();
                    $changed++;
                } elseif ($action === 'untag' && in_array($tag, $tags, true)) {
                    $customer->forceFill(['tags' => array_values(array_diff($tags, [$tag]))])->save();
                    $changed++;
                } elseif ($action === 'archive' && $customer->status === 'active') {
                    $customer->setStatus('archived');
                    $changed++;
                } elseif ($action === 'unarchive' && $customer->status === 'archived') {
                    $customer->setStatus('active');
                    $changed++;
                }
            }
        });

        Audit::record('customer.bulk_'.$action, 'user', (string) auth()->id(), $this->current->organization()?->id, 'customers', null, ['count' => $changed, 'selected' => count($data['ids'])]);

        return back()->with('status', __('ui.customers.bulk.done_'.$action, ['count' => $changed]));
    }

    public function saveView(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'filters' => ['nullable', 'array'],
            'columns' => ['nullable', 'array', 'max:14'],
            'columns.*' => ['string', 'in:'.implode(',', self::COLUMNS)],
            'sort' => ['nullable', 'in:'.implode(',', CustomerListQuery::SORTS)],
            'dir' => ['nullable', 'in:asc,desc'],
            'shared' => ['nullable', 'boolean'],
        ]);
        $shared = (bool) ($data['shared'] ?? false) && in_array($this->access->role(), [OrgMember::ROLE_OWNER, OrgMember::ROLE_SITE_MANAGER], true);

        CustomerSavedView::query()->create([
            'org_id' => $this->current->organization()?->id,
            'user_id' => auth()->id(),
            'name' => trim($data['name']),
            'filters' => CustomerListQuery::cleanFilters($data['filters'] ?? []),
            'columns' => $data['columns'] ?? null,
            'sort' => $data['sort'] ?? null,
            'direction' => $data['dir'] ?? null,
            'shared' => $shared,
        ]);

        return back()->with('status', __('ui.customers.views.saved'));
    }

    public function deleteView(CustomerSavedView $view): RedirectResponse
    {
        abort_unless($view->user_id === (string) auth()->id() || ($view->shared && $this->access->role() === OrgMember::ROLE_OWNER), 403);
        $view->delete();

        return back()->with('status', __('ui.customers.views.deleted'));
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
