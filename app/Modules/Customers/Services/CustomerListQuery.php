<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Customers\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Liste des clients : recherche, filtres, tri et chiffres de la ligne (dernier et prochain rendez-vous, visites, valeur).
 *
 * La même requête sert l'écran, les actions groupées et (plus tard) l'export : on ne construit jamais un fichier à partir de
 * ce qui est affiché. Un praticien ne voit que ses clients et que ses propres rendez-vous.
 */
final class CustomerListQuery
{
    public const SORTS = ['name', 'last', 'next', 'visits', 'value', 'created'];

    public const STATUSES = ['active', 'archived', 'blocked', 'all'];

    public const APPOINTMENTS = ['upcoming', 'none', 'inactive', 'no_show'];

    public const CONSENTS = ['opted_in', 'opted_out', 'unknown'];

    public const SOURCES = ['whatsapp_qr', 'whatsapp', 'website', 'walk_in', 'referral', 'social', 'manual', 'other'];

    public function __construct(private readonly PlanningAccess $access) {}

    /**
     * Ne garde que des filtres connus et bien formés (ce que l'écran envoie, ce qu'une vue enregistrée stocke).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>
     */
    public static function cleanFilters(array $input): array
    {
        $filters = [];
        $pick = static fn (string $key, array $allowed): ?string => isset($input[$key]) && is_string($input[$key]) && in_array($input[$key], $allowed, true) ? $input[$key] : null;

        foreach (['status' => self::STATUSES, 'appt' => self::APPOINTMENTS, 'consent' => self::CONSENTS, 'source' => self::SOURCES] as $key => $allowed) {
            if (($value = $pick($key, $allowed)) !== null) {
                $filters[$key] = $value;
            }
        }

        if (isset($input['practitioner']) && is_string($input['practitioner']) && preg_match('/^[0-9a-f-]{36}$/i', $input['practitioner']) === 1) {
            $filters['practitioner'] = strtolower($input['practitioner']);
        }

        if (isset($input['tag']) && is_string($input['tag']) && trim($input['tag']) !== '') {
            $filters['tag'] = mb_substr(trim($input['tag']), 0, 30);
        }

        foreach (['days' => [1, 730], 'created_days' => [1, 730], 'visits_min' => [1, 1000]] as $key => [$min, $max]) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $filters[$key] = max($min, min($max, (int) $input[$key]));
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string|int>  $filters
     * @return Builder<Customer>
     */
    public function build(string $search = '', array $filters = [], string $sort = 'name', string $direction = 'asc'): Builder
    {
        $query = Customer::query()->select('customers.*')
            ->selectSub($this->bookings()->whereIn('b.status', [Booking::CONFIRMED, Booking::COMPLETED])->where('b.starts_at', '<=', now())->selectRaw('max(b.starts_at)'), 'last_at')
            ->selectSub($this->bookings()->whereIn('b.status', Booking::BLOCKING)->where('b.starts_at', '>', now())->selectRaw('min(b.starts_at)'), 'next_at')
            ->selectSub($this->bookings()->where('b.status', Booking::COMPLETED)->selectRaw('count(*)'), 'visits')
            ->selectSub($this->bookings()->where('b.status', Booking::NO_SHOW)->selectRaw('count(*)'), 'no_shows')
            ->selectSub($this->bookings()->where('b.status', Booking::COMPLETED)->selectRaw('coalesce(sum(case when b.amount_collected_cents > 0 then b.amount_collected_cents else b.price_cents end), 0)'), 'value_cents')
            ->selectSub($this->marketingStatus(), 'marketing_status');

        $this->scopeToPractitioner($query);
        $this->applySearch($query, $search);
        $this->applyFilters($query, $filters);

        $direction = $direction === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'last' => $query->orderByRaw("last_at {$direction} nulls last"),
            'next' => $query->orderByRaw("next_at {$direction} nulls last"),
            'visits' => $query->orderByRaw("visits {$direction}"),
            'value' => $query->orderByRaw("value_cents {$direction}"),
            'created' => $query->orderBy('customers.created_at', $direction),
            default => $query->orderByRaw("lower(coalesce(customers.name, customers.phone_e164)) {$direction}"),
        };

        return $query->orderBy('customers.id');
    }

    /**
     * Dernier et prochain rendez-vous (date, service, état, praticien) des clients d'une page.
     *
     * @param  list<string>  $customerIds
     * @return array{last: array<string, object>, next: array<string, object>, timezone: string}
     */
    public function appointmentsFor(array $customerIds): array
    {
        $timezone = (string) (DB::table('locations')->orderBy('created_at')->value('timezone') ?: 'UTC');

        $base = fn () => DB::table('bookings as b')
            ->leftJoin('services as s', 's.id', '=', 'b.service_id')
            ->leftJoin('practitioners as p', 'p.id', '=', 'b.practitioner_id')
            ->whereIn('b.customer_id', $customerIds)
            ->when($this->access->isPractitioner(), fn ($q) => $q->where('b.practitioner_id', $this->access->practitionerId()))
            ->select('b.customer_id', 'b.starts_at', 'b.status', 's.name as service', 'p.name as practitioner')
            ->distinct('b.customer_id');

        $last = $base()->whereIn('b.status', [Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])->where('b.starts_at', '<=', now())
            ->orderBy('b.customer_id')->orderByDesc('b.starts_at')->get()->keyBy('customer_id')->all();
        $next = $base()->whereIn('b.status', Booking::BLOCKING)->where('b.starts_at', '>', now())
            ->orderBy('b.customer_id')->orderBy('b.starts_at')->get()->keyBy('customer_id')->all();

        return ['last' => $last, 'next' => $next, 'timezone' => $timezone];
    }

    /**
     * @param  LengthAwarePaginator<int, Customer>  $page
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $page): array
    {
        $ids = collect($page->items())->pluck('id')->all();
        $appointments = $ids === [] ? ['last' => [], 'next' => [], 'timezone' => 'UTC'] : $this->appointmentsFor($ids);
        $local = static fn (?string $utc, string $timezone): ?string => $utc === null ? null : Carbon::parse($utc, 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i');
        $rows = [];

        foreach ($page->items() as $customer) {
            $last = $appointments['last'][$customer->id] ?? null;
            $next = $appointments['next'][$customer->id] ?? null;

            $rows[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'reference' => $customer->reference(),
                'phone' => $customer->phone_e164,
                'email' => $customer->email,
                'status' => $customer->status,
                'tags' => $customer->tags,
                'source' => $customer->acquisition_source,
                'whatsapp' => $customer->whatsapp_opted_out_at !== null ? 'opted_out' : match ($customer->getAttribute('marketing_status')) {
                    'granted' => 'opted_in',
                    'withdrawn' => 'opted_out',
                    default => 'unknown',
                },
                'last' => $last === null ? null : ['at' => $local($last->starts_at, $appointments['timezone']), 'service' => $last->service, 'status' => $last->status],
                'next' => $next === null ? null : ['at' => $local($next->starts_at, $appointments['timezone']), 'service' => $next->service, 'practitioner' => $next->practitioner],
                'visits' => (int) $customer->getAttribute('visits'),
                'no_shows' => (int) $customer->getAttribute('no_shows'),
                'value_cents' => (int) $customer->getAttribute('value_cents'),
                'created_at' => $customer->created_at?->format('Y-m-d'),
            ];
        }

        return $rows;
    }

    /**
     * Un praticien ne voit que les clients qui ont (eu) rendez-vous avec lui.
     *
     * @param  Builder<Customer>  $query
     */
    private function scopeToPractitioner(Builder $query): void
    {
        if ($this->access->isPractitioner()) {
            $query->whereExists($this->bookings());
        }
    }

    /** Sous-requête : rendez-vous du client de la ligne (limités à ceux du praticien connecté s'il en est un). */
    private function bookings(): QueryBuilder
    {
        return DB::table('bookings as b')->whereColumn('b.customer_id', 'customers.id')
            ->when($this->access->isPractitioner(), fn ($q) => $q->where('b.practitioner_id', $this->access->practitionerId()));
    }

    private function marketingStatus(): QueryBuilder
    {
        return DB::table('customer_consents as cc')->whereColumn('cc.customer_id', 'customers.id')->where('cc.purpose', 'marketing')
            ->orderByDesc('cc.recorded_at')->orderByDesc('cc.id')->limit(1)->select('cc.status');
    }

    /**
     * Texte : nom, e-mail, référence, étiquettes sans accents ni majuscules et tolérant aux fautes. Saisie qui ressemble à un
     * numéro : chiffres seuls, quel que soit le format (0470 12 34 56, +32 470 12 34 56, 0032470123456).
     *
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', $search) ?? '';

        if (preg_match('/^[\d\s+().\-\/]+$/', $search) === 1 && strlen($digits) >= 3) {
            $national = ltrim($digits, '0');
            $query->where(fn (Builder $q) => $q->where('customers.phone_e164', 'like', '%'.$national.'%')->orWhereRaw('customers.reference_number::text = ?', [$digits]));

            return;
        }

        $like = '%'.addcslashes($search, '\\%_').'%';
        // Tolérance aux fautes seulement pour un mot d'au moins 4 caractères sans chiffre : « c-1002 » ne doit jamais ressembler à « c-1001 ».
        $fuzzy = mb_strlen($search) >= 4 && preg_match('/\d/', $search) !== 1;

        $query->where(function (Builder $q) use ($like, $search, $fuzzy): void {
            $q->whereRaw('customers.search_text like btrim(lower(unaccent(?)))', [$like]);

            if ($fuzzy) {
                $q->orWhereRaw('word_similarity(btrim(lower(unaccent(?))), customers.search_text) >= 0.55', [$search]);
            }
        });
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, string|int>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        match ($filters['status'] ?? null) {
            'all' => null,
            'active', 'archived', 'blocked' => $query->where('customers.status', $filters['status']),
            default => $query->whereIn('customers.status', ['active', 'blocked']),
        };

        match ($filters['appt'] ?? null) {
            'upcoming' => $query->whereExists($this->bookings()->whereIn('b.status', Booking::BLOCKING)->where('b.starts_at', '>', now())),
            'none' => $query->whereNotExists($this->bookings()),
            'no_show' => $query->whereExists($this->bookings()->where('b.status', Booking::NO_SHOW)),
            'inactive' => $query
                ->whereExists($this->bookings()->whereIn('b.status', [Booking::CONFIRMED, Booking::COMPLETED])->where('b.starts_at', '<=', now()))
                ->whereNotExists($this->bookings()->whereIn('b.status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED])->where('b.starts_at', '>=', now()->subDays((int) ($filters['days'] ?? 90)))),
            default => null,
        };

        $marketing = '(select cc.status from customer_consents cc where cc.customer_id = customers.id and cc.purpose = \'marketing\' order by cc.recorded_at desc, cc.id desc limit 1)';

        match ($filters['consent'] ?? null) {
            'opted_out' => $query->where(fn (Builder $q) => $q->whereNotNull('customers.whatsapp_opted_out_at')->orWhereRaw("{$marketing} = 'withdrawn'")),
            'opted_in' => $query->whereNull('customers.whatsapp_opted_out_at')->whereRaw("{$marketing} = 'granted'"),
            'unknown' => $query->whereNull('customers.whatsapp_opted_out_at')->whereRaw("{$marketing} is null"),
            default => null,
        };

        if (isset($filters['practitioner'])) {
            $practitioner = (string) $filters['practitioner'];
            $query->where(fn (Builder $q) => $q->where('customers.assigned_practitioner_id', $practitioner)
                ->orWhereExists(DB::table('bookings as bp')->whereColumn('bp.customer_id', 'customers.id')->where('bp.practitioner_id', $practitioner)));
        }

        if (isset($filters['tag'])) {
            $query->whereJsonContains('customers.tags', (string) $filters['tag']);
        }

        if (isset($filters['source'])) {
            $query->where('customers.acquisition_source', (string) $filters['source']);
        }

        if (isset($filters['created_days'])) {
            $query->where('customers.created_at', '>=', now()->subDays((int) $filters['created_days']));
        }

        if (isset($filters['visits_min'])) {
            $visits = $this->bookings()->where('b.status', Booking::COMPLETED)->selectRaw('count(*)');
            $query->whereRaw('('.$visits->toSql().') >= ?', [...$visits->getBindings(), (int) $filters['visits_min']]);
        }
    }
}
