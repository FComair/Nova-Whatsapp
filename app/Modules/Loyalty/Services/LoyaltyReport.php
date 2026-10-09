<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres de fidélité sur une période (docs/27), filtrables par dates, service, site et programme. Définitions :
 * - tampons gagnés / retirés : lignes du journal de la période (rendez-vous du service et du site choisis) ; net = gagnés − retirés ;
 * - récompenses obtenues : attribuées pendant la période ; utilisées : dont l'utilisation date de la période (une utilisation annulée ne compte plus) ;
 * - en attente : récompenses disponibles et non expirées MAINTENANT (indépendant de la période) ;
 * - taux d'utilisation : parmi les récompenses obtenues pendant la période, la part déjà utilisée aujourd'hui ;
 * - clients inscrits : ayant rejoint pendant la période (les filtres service et site ne s'y appliquent pas) ;
 * - retour : parmi les clients ayant eu au moins un rendez-vous terminé dans la période, la part qui en a eu au moins deux, séparément
 *   pour les inscrits et les non inscrits.
 * Le retour des inscrits est DESCRIPTIF : les clients inscrits sont déjà ceux qui reviennent le plus. Nous ne disons jamais que le programme
 * a causé une hausse de visites ou de chiffre d'affaires, et nous ne calculons aucun chiffre d'affaires « dû » à la fidélité.
 * Les ajustements manuels n'ont ni service ni site : ils sont exclus dès qu'un de ces filtres est choisi.
 */
final class LoyaltyReport
{
    public function __construct(private readonly CurrentTenant $tenant) {}

    /**
     * @param  array{from?: string|null, to?: string|null, service?: string|null, location?: string|null, program?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $timezone = $this->tenant->organization()->timezone ?? 'UTC';
        $to = $this->date($filters['to'] ?? null, $timezone)?->endOfDay() ?? CarbonImmutable::now($timezone)->endOfDay();
        $from = $this->date($filters['from'] ?? null, $timezone)?->startOfDay() ?? $to->subDays(89)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        $service = $this->uuid($filters['service'] ?? null);
        $location = $this->uuid($filters['location'] ?? null);
        $program = $this->uuid($filters['program'] ?? null);
        $scoped = $service !== null || $location !== null;
        $start = $from->utc();
        $end = $to->utc();

        $ledger = fn (string $kind): Builder => $this->ledger($kind, $start, $end, $program, $service, $location, $scoped);

        $earned = (clone $ledger('stamp_earned'))->count();
        $reversed = (clone $ledger('stamp_reversed'))->count();
        $issued = $this->rewardsIssued($start, $end, $program, $service, $location, $scoped);
        $redeemed = $this->rewardsRedeemed($start, $end, $program, $service, $location);
        $issuedNow = $issued->count();
        $usedOfIssued = (clone $issued)->where('r.status', 'redeemed')->count();
        $days = (int) $from->diffInDays($to) + 1;
        $granularity = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'granularity' => $granularity, 'timezone' => $timezone],
            'filters' => ['service' => $service, 'location' => $location, 'program' => $program],
            'scoped' => $scoped,
            'metrics' => [
                'enrolled' => DB::table('loyalty_members')->whereBetween('joined_at', [$start, $end])->when($program, fn (Builder $q) => $q->where('program_id', $program))->count(),
                'stamps_earned' => $earned,
                'stamps_reversed' => $reversed,
                'stamps_net' => $earned - $reversed,
                'rewards_earned' => $issuedNow,
                'rewards_redeemed' => $redeemed->count(),
                'rewards_outstanding' => DB::table('loyalty_rewards')->where('status', 'available')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->when($program, fn (Builder $q) => $q->where('program_id', $program))->count(),
                'redemption_rate' => $issuedNow === 0 ? null : round($usedOfIssued / $issuedNow, 4),
                'redemption_rate_base' => $issuedNow,
            ],
            'series' => $this->series($granularity, $timezone, $start, $end, $ledger('stamp_earned'), $redeemed),
            'returning' => $this->returning($start, $end, $program, $service, $location),
            'programs' => DB::table('loyalty_programs')->orderByDesc('created_at')->get(['id', 'status', 'name', 'created_at'])
                ->map(fn (object $row): array => ['id' => (string) $row->id, 'status' => (string) $row->status, 'name' => (string) (json_decode((string) $row->name, true)['fr'] ?? ''), 'created_at' => CarbonImmutable::parse((string) $row->created_at)->toDateString()])->all(),
        ];
    }

    private function ledger(string $kind, CarbonImmutable $start, CarbonImmutable $end, ?string $program, ?string $service, ?string $location, bool $scoped): Builder
    {
        $query = DB::table('loyalty_ledger as l')->where('l.kind', $kind)->whereBetween('l.created_at', [$start, $end])->when($program, fn (Builder $q) => $q->where('l.program_id', $program));

        if ($scoped) {
            $query->join('bookings as b', 'b.id', '=', 'l.booking_id')->when($service, fn (Builder $q) => $q->where('b.service_id', $service))->when($location, fn (Builder $q) => $q->where('b.location_id', $location));
        }

        return $query;
    }

    private function rewardsIssued(CarbonImmutable $start, CarbonImmutable $end, ?string $program, ?string $service, ?string $location, bool $scoped): Builder
    {
        $query = DB::table('loyalty_rewards as r')->where('r.status', '!=', 'void')->whereBetween('r.earned_at', [$start, $end])->when($program, fn (Builder $q) => $q->where('r.program_id', $program));

        if ($scoped) {
            $query->join('bookings as b', 'b.id', '=', 'r.earned_booking_id')->when($service, fn (Builder $q) => $q->where('b.service_id', $service))->when($location, fn (Builder $q) => $q->where('b.location_id', $location));
        }

        return $query;
    }

    private function rewardsRedeemed(CarbonImmutable $start, CarbonImmutable $end, ?string $program, ?string $service, ?string $location): Builder
    {
        $query = DB::table('loyalty_rewards as r')->where('r.status', 'redeemed')->whereBetween('r.redeemed_at', [$start, $end])->when($program, fn (Builder $q) => $q->where('r.program_id', $program));

        if ($service !== null || $location !== null) {
            $query->join('bookings as b', 'b.id', '=', 'r.redeemed_booking_id')->when($service, fn (Builder $q) => $q->where('b.service_id', $service))->when($location, fn (Builder $q) => $q->where('b.location_id', $location));
        }

        return $query;
    }

    /**
     * @return list<array{key: string, earned: int, redeemed: int}>
     */
    private function series(string $granularity, string $timezone, CarbonImmutable $start, CarbonImmutable $end, Builder $earned, Builder $redeemed): array
    {
        $bucket = fn (string $column): string => "to_char(date_trunc('{$granularity}', {$column} at time zone '{$timezone}'), 'YYYY-MM-DD')";
        $earnedBy = (clone $earned)->selectRaw("{$bucket('l.created_at')} as bucket, count(*) as n")->groupBy('bucket')->pluck('n', 'bucket');
        $redeemedBy = (clone $redeemed)->selectRaw("{$bucket('r.redeemed_at')} as bucket, count(*) as n")->groupBy('bucket')->pluck('n', 'bucket');
        $keys = $earnedBy->keys()->merge($redeemedBy->keys())->unique()->sort()->values();

        return $keys->map(fn ($key): array => ['key' => (string) $key, 'earned' => (int) ($earnedBy[$key] ?? 0), 'redeemed' => (int) ($redeemedBy[$key] ?? 0)])->all();
    }

    /**
     * @return array{enrolled: array{customers: int, repeat: int, rate: float|null}, others: array{customers: int, repeat: int, rate: float|null}}
     */
    private function returning(CarbonImmutable $start, CarbonImmutable $end, ?string $program, ?string $service, ?string $location): array
    {
        $counts = DB::table('bookings as b')->where('b.status', 'completed')->whereBetween('b.starts_at', [$start, $end])
            ->when($service, fn (Builder $q) => $q->where('b.service_id', $service))->when($location, fn (Builder $q) => $q->where('b.location_id', $location))
            ->select('b.customer_id')->selectRaw('count(*) as visits')->groupBy('b.customer_id');
        $enrolled = DB::table('loyalty_members')->where('status', 'active')->when($program, fn (Builder $q) => $q->where('program_id', $program))->pluck('customer_id')->flip();

        $groups = ['enrolled' => ['customers' => 0, 'repeat' => 0], 'others' => ['customers' => 0, 'repeat' => 0]];

        foreach ($counts->get() as $row) {
            $group = isset($enrolled[$row->customer_id]) ? 'enrolled' : 'others';
            $groups[$group]['customers']++;
            $groups[$group]['repeat'] += (int) $row->visits >= 2 ? 1 : 0;
        }

        return [
            'enrolled' => [...$groups['enrolled'], 'rate' => $groups['enrolled']['customers'] === 0 ? null : round($groups['enrolled']['repeat'] / $groups['enrolled']['customers'], 4)],
            'others' => [...$groups['others'], 'rate' => $groups['others']['customers'] === 0 ? null : round($groups['others']['repeat'] / $groups['others']['customers'], 4)],
        ];
    }

    private function date(?string $value, string $timezone): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value, $timezone) : null;
    }

    private function uuid(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }
}
