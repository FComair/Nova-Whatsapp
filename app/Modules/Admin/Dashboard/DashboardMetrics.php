<?php

declare(strict_types=1);

namespace App\Modules\Admin\Dashboard;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\TicketMetrics;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres du tableau de bord du Super Admin (docs/33). Rien n'est inventé : chaque indicateur vient d'une table que la plateforme tient déjà,
 * et sa définition est fixe (docs/33 §4).
 *
 *  - INSTANTANÉS (état actuel, la période ne les limite pas, aucune comparaison : la plateforme ne garde pas l'historique de ces états) :
 *    clients par état et par formule, MRR, essais en cours, renouvellements à venir, impayés, tickets ouverts, limites de formule.
 *  - SUR LA PÉRIODE (comparés à la période précédente équivalente) : nouveaux clients, clients actifs, abonnements démarrés / résiliés,
 *    encaissements, paiements refusés, rendez-vous, messages WhatsApp, tickets, avis.
 *
 * Les recettes des clients (rendez-vous, pourboires) ne sont JAMAIS ajoutées au revenu de Nova (abonnements) : trois blocs distincts.
 */
class DashboardMetrics
{
    /** Part d'une limite de formule à partir de laquelle on prévient (comme la fiche client). */
    public const NEAR_LIMIT_PERCENT = 80;

    /** Un client sans activité depuis ce nombre de jours est « dormant » (comme la fiche client). */
    public const DORMANT_DAYS = 30;

    /** Avis jugé bas. */
    public const LOW_RATING = 2;

    private string $tz;

    public function __construct(private readonly TicketMetrics $tickets)
    {
        $this->tz = (string) config('app.timezone', 'UTC');
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(PlatformAdmin $admin, DashboardPeriod $p, ?Carbon $now = null): array
    {
        $now ??= now();
        $snapshot = $this->snapshot($now);
        $period = $this->periodFigures($p);
        $support = $this->support($admin, $p, $now);
        $usage = $this->usage($p, $now);
        $revenue = $this->revenue($p, $snapshot, $period);
        $clients = $this->clients($p, $snapshot, $period, $now);

        return [
            'period' => ['preset' => $p->preset, 'from' => $p->from->toDateString(), 'until' => $p->until->toDateString(), 'previous_from' => $p->previousFrom->toDateString(), 'previous_until' => $p->previousUntil->toDateString(), 'days' => $p->days(), 'bucket' => $p->bucket()],
            'generated_at' => $now->toIso8601String(),
            'kpis' => $this->kpis($snapshot, $period, $support),
            'revenue' => $revenue,
            'clients' => $clients,
            'usage' => $usage,
            'support' => $support,
            'alerts' => $this->alerts($snapshot, $period, $support, $usage, $now),
            'activity' => $this->activity(),
            'unavailable' => ['mrr_history', 'active_clients_history', 'storage', 'api_usage'],
        ];
    }

    // ---- Instantanés ---------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Carbon $now): array
    {
        $db = $this->db();
        $base = fn () => $db->query()->fromSub(ClientDirectory::query()->toBase(), 'c');
        $states = $base()->selectRaw('subscription_state as k, count(*) as n')->groupBy('subscription_state')->pluck('n', 'k');
        $byPlan = $base()->selectRaw("coalesce(plan_name, '—') as label, count(*) as total, count(*) filter (where subscription_state in ('active','past_due')) as paying, coalesce(sum(mrr_cents), 0) as mrr")->groupBy('plan_name')->orderByDesc('total')->get();
        $byCountry = $base()->selectRaw("coalesce(nullif(country, ''), '—') as label, count(*) as total")->groupBy('country')->orderByDesc('total')->limit(8)->get();
        $row = $base()->selectRaw(
            "count(*) as total,
             coalesce(sum(mrr_cents), 0) as mrr,
             count(*) filter (where subscription_state in ('active','past_due')) as paying,
             count(*) filter (where subscription_state = 'trial' and trial_ends_at between ? and ?) as trials_ending,
             count(*) filter (where subscription_state = 'active' and renewal_at between ? and ?) as renewals_7,
             coalesce(sum(mrr_cents) filter (where subscription_state = 'active' and renewal_at between ? and ?), 0) as renewals_7_mrr,
             count(*) filter (where subscription_state = 'active' and renewal_at between ? and ?) as renewals_30,
             coalesce(sum(mrr_cents) filter (where subscription_state = 'active' and renewal_at between ? and ?), 0) as renewals_30_mrr,
             count(*) filter (where subscription_state in ('active','past_due','trial','paused') and (last_activity_at is null or last_activity_at < ?) and created_at < ?) as dormant",
            [$now, $now->copy()->addDays(7), $now, $now->copy()->addDays(7), $now, $now->copy()->addDays(7), $now, $now->copy()->addDays(30), $now, $now->copy()->addDays(30), $now->copy()->subDays(self::DORMANT_DAYS), $now->copy()->subDays(7)]
        )->first();

        $dormant = $base()->whereIn('subscription_state', ['active', 'past_due', 'trial', 'paused'])->where('created_at', '<', $now->copy()->subDays(7))
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $now->copy()->subDays(self::DORMANT_DAYS)))
            ->orderByRaw('last_activity_at asc nulls first')->limit(5)->get(['id', 'name', 'last_activity_at', 'subscription_state']);

        $invoices = $db->table('invoices')->selectRaw("count(*) filter (where status = 'open') as open_n, coalesce(sum(total_cents) filter (where status = 'open'), 0) as open_cents, count(*) filter (where status = 'uncollectible') as lost_n, coalesce(sum(total_cents) filter (where status = 'uncollectible'), 0) as lost_cents")->first();

        return [
            'total' => (int) $row->total,
            'mrr_cents' => (int) $row->mrr,
            'paying' => (int) $row->paying,
            'by_state' => collect(ClientDirectory::STATES)->mapWithKeys(fn (string $s): array => [$s => (int) ($states[$s] ?? 0)])->all(),
            'by_plan' => $byPlan->map(fn (object $r): array => ['label' => (string) $r->label, 'total' => (int) $r->total, 'paying' => (int) $r->paying, 'mrr_cents' => (int) $r->mrr])->all(),
            'by_country' => $byCountry->map(fn (object $r): array => ['label' => (string) $r->label, 'total' => (int) $r->total])->all(),
            'trials_in_progress' => (int) ($states['trial'] ?? 0),
            'trials_ending' => (int) $row->trials_ending,
            'renewals_7' => ['count' => (int) $row->renewals_7, 'mrr_cents' => (int) $row->renewals_7_mrr],
            'renewals_30' => ['count' => (int) $row->renewals_30, 'mrr_cents' => (int) $row->renewals_30_mrr],
            'dormant' => (int) $row->dormant,
            'dormant_list' => $dormant->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => (string) $r->name, 'last_activity_at' => $r->last_activity_at !== null ? Carbon::parse($r->last_activity_at)->toIso8601String() : null, 'state' => (string) $r->subscription_state])->all(),
            'outstanding' => ['count' => (int) $invoices->open_n, 'cents' => (int) $invoices->open_cents],
            'uncollectible' => ['count' => (int) $invoices->lost_n, 'cents' => (int) $invoices->lost_cents],
            'past_due' => (int) ($states['past_due'] ?? 0),
        ];
    }

    // ---- Chiffres sur la période (et la précédente) --------------------------------------------------------------------------------------

    /**
     * @return array<string, array{current: float|int|null, previous: float|int|null}>
     */
    private function periodFigures(DashboardPeriod $p): array
    {
        $out = [];

        foreach ([['current', $p->from, $p->until], ['previous', $p->previousFrom, $p->previousUntil]] as [$key, $from, $until]) {
            $out['new_clients'][$key] = $this->db()->table('organizations')->whereNull('deleted_at')->whereBetween('created_at', [$from, $until])->count();
            $out['converted'][$key] = $this->db()->table('organizations')->whereNull('organizations.deleted_at')->whereBetween('organizations.created_at', [$from, $until])->whereExists(fn ($q) => $q->selectRaw('1')->from('subscriptions as s')->whereColumn('s.org_id', 'organizations.id'))->count();
            $out['active_clients'][$key] = $this->activeClients($from, $until);
            $out['revenue_cents'][$key] = (int) $this->db()->table('payments')->where('status', 'succeeded')->whereBetween('created_at', [$from, $until])->sum('amount_cents');
            $out['revenue_ex_vat_cents'][$key] = (int) $this->db()->table('invoices')->where('status', 'paid')->whereBetween('paid_at', [$from, $until])->sum('subtotal_cents');
            $out['failed_payments'][$key] = $this->db()->table('payments')->where('status', 'failed')->whereBetween('created_at', [$from, $until])->count();
            $out['failed_cents'][$key] = (int) $this->db()->table('payments')->where('status', 'failed')->whereBetween('created_at', [$from, $until])->sum('amount_cents');
            foreach (['subscribed' => 'billing.subscribed', 'upgraded' => 'billing.upgraded', 'downgrades' => 'billing.downgrade_scheduled', 'ended' => 'billing.subscription_ended', 'cancel_requested' => 'billing.cancel_requested'] as $name => $action) {
                $out[$name][$key] = $this->db()->table('audit_logs')->where('action', $action)->whereBetween('created_at', [$from, $until])->count();
            }
            $rating = $this->db()->table('nova_feedback')->whereBetween('created_at', [$from, $until])->selectRaw('count(*) as n, avg(rating) as avg, count(*) filter (where rating <= ?) as low', [self::LOW_RATING])->first();
            $out['rating'][$key] = $rating->avg !== null ? round((float) $rating->avg, 2) : null;
            $out['rating_count'][$key] = (int) $rating->n;
            $out['low_ratings'][$key] = (int) $rating->low;
        }

        return $out;
    }

    /** Clients actifs : au moins une connexion d'un membre, un rendez-vous créé ou un message WhatsApp dans l'intervalle. */
    private function activeClients(Carbon $from, Carbon $until): int
    {
        return (int) $this->db()->selectOne(
            "select count(distinct org_id) as n from (
                select org_id from bookings where created_at between ? and ?
                union select org_id from whatsapp_messages where created_at between ? and ?
                union select org_id from audit_logs where action = 'auth.login' and org_id is not null and created_at between ? and ?
             ) a where org_id in (select id from organizations where deleted_at is null)",
            [$from, $until, $from, $until, $from, $until]
        )->n;
    }

    // ---- KPI -----------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, array<string, mixed>>  $f
     * @param  array<string, mixed>  $support
     * @return list<array<string, mixed>>
     */
    private function kpis(array $s, array $f, array $support): array
    {
        $card = fn (string $key, string $format, float|int|null $value, ?array $cmp, array $link, ?string $note = null): array => ['key' => $key, 'format' => $format, 'value' => $value, 'comparison' => $cmp === null ? null : $this->compare($cmp['current'], $cmp['previous']), 'link' => $link, 'note' => $note];

        return [
            $card('active_clients', 'int', $f['active_clients']['current'], $f['active_clients'], ['target' => 'clients', 'query' => ['filters' => ['activity' => ['value' => 'active_7']]]]),
            $card('new_clients', 'int', $f['new_clients']['current'], $f['new_clients'], ['target' => 'clients']),
            $card('mrr', 'money', $s['mrr_cents'], null, ['target' => 'client_reports'], 'snapshot'),
            $card('revenue_collected', 'money', $f['revenue_cents']['current'], $f['revenue_cents'], ['target' => 'invoices']),
            $card('failed_payments', 'int', $f['failed_payments']['current'], $f['failed_payments'], ['target' => 'clients', 'query' => ['filters' => ['payment' => ['value' => 'failed']]]]),
            $card('active_subscriptions', 'int', $s['by_state']['active'], null, ['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['active']]]]], 'snapshot'),
            $card('trials_in_progress', 'int', $s['trials_in_progress'], null, ['target' => 'clients', 'query' => ['filters' => ['trial' => ['value' => 'in_trial']]]], 'snapshot'),
            $card('trials_ending', 'int', $s['trials_ending'], null, ['target' => 'clients', 'query' => ['filters' => ['trial' => ['value' => 'ending_soon']]]], 'snapshot'),
            $card('renewals', 'int', $s['renewals_7']['count'], null, ['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['active']]]]], 'snapshot'),
            $card('churned', 'int', $f['ended']['current'], $f['ended'], ['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['cancelled']]]]]),
            $card('open_tickets', 'int', $support['open'], null, ['target' => 'tickets'], 'snapshot'),
            $card('rating', 'rating', $f['rating']['current'], $f['rating'], ['target' => 'feedback'], $f['rating_count']['current'] === 0 ? 'no_data' : null),
        ];
    }

    /**
     * @return array{value: float|int|null, previous: float|int|null, delta: float|int|null, percent: float|null, direction: string}
     */
    public function compare(float|int|null $current, float|int|null $previous): array
    {
        if ($current === null || $previous === null) {
            return ['value' => $current, 'previous' => $previous, 'delta' => null, 'percent' => null, 'direction' => 'none'];
        }

        $delta = $current - $previous;
        $percent = $previous == 0 ? null : round($delta * 100 / $previous, 1);

        return ['value' => $current, 'previous' => $previous, 'delta' => $delta, 'percent' => $percent, 'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat')];
    }

    // ---- Revenu --------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, array<string, mixed>>  $f
     * @return array<string, mixed>
     */
    private function revenue(DashboardPeriod $p, array $s, array $f): array
    {
        $tz = $this->tz;
        $bookings = $this->db()->table('bookings')->whereBetween('starts_at', [$p->from, $p->until])->selectRaw('coalesce(sum(amount_collected_cents), 0) as cents, count(*) filter (where amount_collected_cents > 0) as n')->first();
        $tips = $this->db()->table('booking_tips')->whereBetween('paid_at', [$p->from, $p->until])->selectRaw('coalesce(sum(amount_cents), 0) as cents, count(*) as n')->first();
        $topClients = $this->db()->table('payments')->join('organizations as o', 'o.id', '=', 'payments.org_id')->where('payments.status', 'succeeded')->whereBetween('payments.created_at', [$p->from, $p->until])
            ->selectRaw('o.id, o.name, sum(payments.amount_cents) as cents')->groupBy('o.id', 'o.name')->orderByDesc('cents')->limit(5)->get();

        return [
            'trend' => $this->pair(fn (bool $prev) => $this->series($this->db()->table('payments')->where('status', 'succeeded'), 'created_at', 'coalesce(sum(amount_cents), 0)', $p, $prev)),
            'subscription' => [
                'collected_cents' => $f['revenue_cents']['current'], 'collected' => $this->compare($f['revenue_cents']['current'], $f['revenue_cents']['previous']),
                'collected_ex_vat_cents' => $f['revenue_ex_vat_cents']['current'], 'outstanding' => $s['outstanding'], 'uncollectible' => $s['uncollectible'],
            ],
            'by_plan' => $s['by_plan'],
            'movements' => $this->pair(fn (bool $prev) => ['subscribed' => $this->series($this->db()->table('audit_logs')->where('action', 'billing.subscribed'), 'created_at', 'count(*)', $p, $prev), 'ended' => $this->series($this->db()->table('audit_logs')->where('action', 'billing.subscription_ended'), 'created_at', 'count(*)', $p, $prev)])['current'],
            'new_subscriptions' => $this->compare($f['subscribed']['current'], $f['subscribed']['previous']),
            'cancellations' => $this->compare($f['ended']['current'], $f['ended']['previous']),
            'cancel_requests' => $this->compare($f['cancel_requested']['current'], $f['cancel_requested']['previous']),
            'upgrades' => $this->compare($f['upgraded']['current'], $f['upgraded']['previous']),
            'downgrades' => $this->compare($f['downgrades']['current'], $f['downgrades']['previous']),
            'trials_started' => $this->compare($f['new_clients']['current'], $f['new_clients']['previous']),
            'trials_converted' => $this->compare($f['converted']['current'], $f['converted']['previous']),
            'conversion_percent' => $f['new_clients']['current'] === 0 ? null : round($f['converted']['current'] * 100 / $f['new_clients']['current'], 1),
            'failed_payments' => $this->compare($f['failed_payments']['current'], $f['failed_payments']['previous']),
            'failed_cents' => $f['failed_cents']['current'],
            'past_due' => $s['past_due'],
            'renewals_7' => $s['renewals_7'], 'renewals_30' => $s['renewals_30'],
            'mrr_cents' => $s['mrr_cents'], 'paying' => $s['paying'],
            'arpc_cents' => $s['paying'] === 0 ? null : (int) round($s['mrr_cents'] / $s['paying']),
            'by_state' => $s['by_state'],
            'top_clients' => $topClients->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => (string) $r->name, 'cents' => (int) $r->cents])->all(),
            'client_money' => [
                'appointments_cents' => (int) $bookings->cents, 'appointments_count' => (int) $bookings->n,
                'tips_cents' => (int) $tips->cents, 'tips_count' => (int) $tips->n,
                'total_cents' => (int) $bookings->cents + (int) $tips->cents,
            ],
            'tz' => $tz,
        ];
    }

    // ---- Clients -------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, array<string, mixed>>  $f
     * @return array<string, mixed>
     */
    private function clients(DashboardPeriod $p, array $s, array $f, Carbon $now): array
    {
        $payingNow = (int) $s['paying'];
        $churnBase = $payingNow + (int) $f['ended']['current'];
        $retained = (int) $this->db()->selectOne(
            "select count(*) as n from (
                select org_id from (
                    select org_id from bookings where created_at between ? and ?
                    union select org_id from whatsapp_messages where created_at between ? and ?
                    union select org_id from audit_logs where action = 'auth.login' and org_id is not null and created_at between ? and ?
                ) prev
                intersect
                select org_id from (
                    select org_id from bookings where created_at between ? and ?
                    union select org_id from whatsapp_messages where created_at between ? and ?
                    union select org_id from audit_logs where action = 'auth.login' and org_id is not null and created_at between ? and ?
                ) cur
             ) both_periods where org_id in (select id from organizations where deleted_at is null)",
            [$p->previousFrom, $p->previousUntil, $p->previousFrom, $p->previousUntil, $p->previousFrom, $p->previousUntil, $p->from, $p->until, $p->from, $p->until, $p->from, $p->until]
        )->n;

        $perClient = $this->db()->select(
            'select o.id, o.name, coalesce(b.n, 0) + coalesce(m.n, 0) as score, coalesce(b.n, 0) as bookings, coalesce(m.n, 0) as messages
             from organizations o
             left join (select org_id, count(*) n from bookings where created_at between ? and ? group by org_id) b on b.org_id = o.id
             left join (select org_id, count(*) n from whatsapp_messages where created_at between ? and ? group by org_id) m on m.org_id = o.id
             where o.deleted_at is null and coalesce(b.n, 0) + coalesce(m.n, 0) > 0
             order by score desc, o.name limit 5',
            [$p->from, $p->until, $p->from, $p->until]
        );

        return [
            'new_series' => $this->pair(fn (bool $prev) => $this->series($this->db()->table('organizations')->whereNull('deleted_at'), 'created_at', 'count(*)', $p, $prev)),
            'active_series' => $this->activeSeries($p),
            'active' => $this->compare($f['active_clients']['current'], $f['active_clients']['previous']),
            'new' => $this->compare($f['new_clients']['current'], $f['new_clients']['previous']),
            'total' => $s['total'],
            'conversion_percent' => $f['new_clients']['current'] === 0 ? null : round($f['converted']['current'] * 100 / $f['new_clients']['current'], 1),
            'churn_percent' => $churnBase === 0 ? null : round($f['ended']['current'] * 100 / $churnBase, 1),
            'churned' => (int) $f['ended']['current'],
            'repeat_percent' => $f['active_clients']['previous'] === 0 ? null : round($retained * 100 / $f['active_clients']['previous'], 1),
            'repeat_base' => (int) $f['active_clients']['previous'],
            'by_plan' => $s['by_plan'],
            'by_state' => $s['by_state'],
            'by_country' => $s['by_country'],
            'most_active' => array_map(fn (object $r): array => ['id' => (string) $r->id, 'name' => (string) $r->name, 'score' => (int) $r->score, 'bookings' => (int) $r->bookings, 'messages' => (int) $r->messages], $perClient),
            'dormant' => $s['dormant'],
            'dormant_list' => $s['dormant_list'],
        ];
    }

    /**
     * @return array{current: array<string, int>, previous: array<string, int>}
     */
    private function activeSeries(DashboardPeriod $p): array
    {
        $out = [];

        foreach ([false, true] as $prev) {
            $from = $prev ? $p->previousFrom : $p->from;
            $until = $prev ? $p->previousUntil : $p->until;
            $step = $p->bucket();
            $rows = $this->db()->select(
                "select to_char(date_trunc('{$step}', t at time zone ?), 'YYYY-MM-DD') as k, count(distinct org_id) as n from (
                    select org_id, created_at as t from bookings where created_at between ? and ?
                    union all select org_id, created_at from whatsapp_messages where created_at between ? and ?
                    union all select org_id, created_at from audit_logs where action = 'auth.login' and org_id is not null and created_at between ? and ?
                 ) a group by k",
                [$this->tz, $from, $until, $from, $until, $from, $until]
            );
            $map = collect($rows)->pluck('n', 'k');
            $out[$prev ? 'previous' : 'current'] = $this->fill($p, $prev, fn (string $k): int => (int) ($map[$k] ?? 0));
        }

        return $out;
    }

    // ---- Usage ---------------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function usage(DashboardPeriod $p, Carbon $now): array
    {
        $db = $this->db();
        $cur = $this->bookingFigures($p->from, $p->until, $now);
        $prev = $this->bookingFigures($p->previousFrom, $p->previousUntil, $now);
        $messages = fn (Carbon $f, Carbon $u) => $db->table('whatsapp_messages')->whereBetween('created_at', [$f, $u])->selectRaw("count(*) filter (where direction = 'out') as sent, count(*) filter (where direction = 'in') as received, count(distinct customer_id) as conversations, count(*) filter (where status = 'failed') as failed")->first();
        $mc = $messages($p->from, $p->until);
        $mp = $messages($p->previousFrom, $p->previousUntil);
        $statuses = $db->table('bookings')->whereBetween('created_at', [$p->from, $p->until])->selectRaw('status as k, count(*) as n')->groupBy('status')->pluck('n', 'k');
        $heat = $db->select("select extract(isodow from starts_at at time zone ?)::int as d, extract(hour from starts_at at time zone ?)::int as h, count(*) as n from bookings where starts_at between ? and ? and status not in ('cancelled_by_customer','cancelled_by_business','pending') group by d, h", [$this->tz, $this->tz, $p->from, $p->until]);
        $matrix = array_fill(1, 7, array_fill(0, 24, 0));

        foreach ($heat as $r) {
            $matrix[(int) $r->d][(int) $r->h] = (int) $r->n;
        }

        $live = ['active', 'trial', 'past_due', 'paused'];
        $liveOrgs = fn () => $db->table('organizations')->whereNull('deleted_at')->whereIn('status', $live);
        $adoption = [
            'whatsapp' => $liveOrgs()->whereExists(fn ($q) => $q->selectRaw('1')->from('whatsapp_accounts as w')->whereColumn('w.org_id', 'organizations.id')->where('w.status', 'active'))->count(),
            'practitioner' => $liveOrgs()->whereExists(fn ($q) => $q->selectRaw('1')->from('practitioners as x')->whereColumn('x.org_id', 'organizations.id')->whereNull('x.deleted_at'))->count(),
            'service' => $liveOrgs()->whereExists(fn ($q) => $q->selectRaw('1')->from('services as x')->whereColumn('x.org_id', 'organizations.id')->whereNull('x.deleted_at')->where('x.is_active', true))->count(),
            'calendar' => $liveOrgs()->whereExists(fn ($q) => $q->selectRaw('1')->from('external_calendars as x')->whereColumn('x.org_id', 'organizations.id')->where('x.status', 'active'))->count(),
            'loyalty' => $liveOrgs()->whereExists(fn ($q) => $q->selectRaw('1')->from('loyalty_programs as x')->whereColumn('x.org_id', 'organizations.id')->where('x.status', 'active'))->count(),
        ];

        return [
            'created' => $this->compare($cur['created'], $prev['created']),
            'completed' => $this->compare($cur['completed'], $prev['completed']),
            'upcoming' => $cur['upcoming'],
            'cancellation_percent' => $cur['cancellation_percent'], 'cancellation_previous' => $prev['cancellation_percent'],
            'no_show_percent' => $cur['no_show_percent'], 'no_show_previous' => $prev['no_show_percent'],
            'messages_sent' => $this->compare((int) $mc->sent, (int) $mp->sent),
            'messages_received' => (int) $mc->received,
            'messages_failed' => (int) $mc->failed,
            'conversations' => $this->compare((int) $mc->conversations, (int) $mp->conversations),
            'practitioners' => $db->table('practitioners')->whereNull('deleted_at')->where('status', 'active')->count(),
            'locations' => $db->table('locations')->whereNull('deleted_at')->count(),
            'services' => $db->table('services')->whereNull('deleted_at')->where('is_active', true)->count(),
            'calendars' => ['active' => $db->table('external_calendars')->where('status', 'active')->count(), 'error' => $db->table('external_calendars')->where('status', 'error')->count()],
            'loyalty' => ['programs' => $db->table('loyalty_programs')->where('status', 'active')->count(), 'members' => $db->table('loyalty_members')->where('status', 'active')->count()],
            'appointments_series' => $this->pair(fn (bool $prev) => $this->series($db->table('bookings'), 'created_at', 'count(*)', $p, $prev)),
            'messages_series' => ['out' => $this->series($db->table('whatsapp_messages')->where('direction', 'out'), 'created_at', 'count(*)', $p, false), 'in' => $this->series($db->table('whatsapp_messages')->where('direction', 'in'), 'created_at', 'count(*)', $p, false)],
            'status_distribution' => collect(['pending', 'confirmed', 'completed', 'cancelled_by_customer', 'cancelled_by_business', 'no_show', 'expired'])->mapWithKeys(fn (string $s): array => [$s => (int) ($statuses[$s] ?? 0)])->filter(fn (int $n, string $s): bool => $n > 0 || in_array($s, ['confirmed', 'completed'], true))->all(),
            'heatmap' => $matrix,
            'heatmap_max' => max(1, ...array_map('max', $matrix)),
            'adoption' => $adoption, 'adoption_base' => $liveOrgs()->count(),
        ];
    }

    /**
     * Rendez-vous DONT L'HEURE tombe dans l'intervalle : terminés, absences, annulations (taux sur les rendez-vous hors « en attente »).
     *
     * @return array<string, mixed>
     */
    private function bookingFigures(Carbon $from, Carbon $until, Carbon $now): array
    {
        $created = $this->db()->table('bookings')->whereBetween('created_at', [$from, $until])->count();
        $r = $this->db()->table('bookings')->whereBetween('starts_at', [$from, $until])->selectRaw(
            "count(*) filter (where status = 'completed') as completed,
             count(*) filter (where status = 'no_show') as no_show,
             count(*) filter (where status in ('cancelled_by_customer','cancelled_by_business')) as cancelled,
             count(*) filter (where status in ('completed','confirmed','no_show','cancelled_by_customer','cancelled_by_business')) as decided"
        )->first();
        $upcoming = $this->db()->table('bookings')->where('starts_at', '>', $now)->whereIn('status', ['pending', 'confirmed'])->count();
        $held = (int) $r->completed + (int) $r->no_show;

        return [
            'created' => $created, 'completed' => (int) $r->completed, 'upcoming' => $upcoming,
            'cancellation_percent' => (int) $r->decided === 0 ? null : round((int) $r->cancelled * 100 / (int) $r->decided, 1),
            'no_show_percent' => $held === 0 ? null : round((int) $r->no_show * 100 / $held, 1),
        ];
    }

    // ---- Support -------------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function support(PlatformAdmin $admin, DashboardPeriod $p, Carbon $now): array
    {
        $m = $this->tickets->compute($admin, ['from' => $p->from->toDateString(), 'until' => $p->until->toDateString()], $now);
        $previous = $this->tickets->compute($admin, ['from' => $p->previousFrom->toDateString(), 'until' => $p->previousUntil->toDateString()], $now);
        $open = fn () => $this->db()->table('support_tickets')->whereIn('status', TicketStatus::ACTIVE);

        return [
            'open' => $m['open'], 'unassigned' => $m['unassigned'], 'waiting_on_client' => $m['waiting_on_client'], 'overdue' => $m['overdue'], 'at_risk' => $m['at_risk'],
            'waiting_internal' => (int) $this->db()->table('support_tickets')->where('status', TicketStatus::WAITING_INTERNAL)->count(),
            'urgent' => (int) $open()->whereRaw("coalesce(staff_priority, urgency) = 'urgent'")->count(),
            'created' => $this->compare($m['created'], $previous['created']),
            'resolved' => $this->compare($this->resolvedIn($p->from, $p->until), $this->resolvedIn($p->previousFrom, $p->previousUntil)),
            'reopened' => $this->compare($m['reopened'], $previous['reopened']),
            'first_response_hours' => $m['first_response_hours'], 'first_response_previous' => $previous['first_response_hours'],
            'resolution_hours' => $m['resolution_hours'], 'resolution_previous' => $previous['resolution_hours'],
            'first_response_on_time' => $m['first_response_on_time'],
            'by_status' => $m['by_status'], 'by_priority' => $m['by_priority'], 'by_agent' => $m['by_agent'],
            'volume_series' => $this->pair(fn (bool $prev) => $this->series($this->db()->table('support_tickets'), 'created_at', 'count(*)', $p, $prev)),
            'rating_series' => $this->series($this->db()->table('nova_feedback'), 'created_at', 'round(avg(rating)::numeric, 2)', $p, false, null),
        ];
    }

    private function resolvedIn(Carbon $from, Carbon $until): int
    {
        return (int) $this->db()->table('support_tickets')->whereBetween(DB::raw('coalesce(resolved_at, closed_at)'), [$from, $until])->count();
    }

    // ---- Alertes -------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, array<string, mixed>>  $f
     * @param  array<string, mixed>  $support
     * @param  array<string, mixed>  $usage
     * @return list<array{key: string, severity: string, count: int, link: array<string, mixed>, params: array<string, mixed>}>
     */
    private function alerts(array $s, array $f, array $support, array $usage, Carbon $now): array
    {
        $db = $this->db();
        $live = $db->table('organizations')->whereNull('deleted_at')->whereIn('status', ['active', 'trial', 'past_due']);
        $noWhatsapp = (clone $live)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('whatsapp_accounts as w')->whereColumn('w.org_id', 'organizations.id')->where('w.status', 'active'))->where('created_at', '<', $now->copy()->subDays(3))->count();
        $noPractitioner = (clone $live)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('practitioners as x')->whereColumn('x.org_id', 'organizations.id')->whereNull('x.deleted_at'))->where('created_at', '<', $now->copy()->subDays(3))->count();
        $limits = $this->nearLimit();

        $rows = [
            ['past_due', 'danger', $s['past_due'], ['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['past_due']]]]], []],
            ['failed_payments', 'danger', (int) $f['failed_payments']['current'], ['target' => 'clients', 'query' => ['filters' => ['payment' => ['value' => 'failed']]]], []],
            ['urgent_tickets', 'danger', (int) $support['urgent'], ['target' => 'tickets', 'query' => ['tab' => 'urgent']], []],
            ['overdue_tickets', 'danger', (int) $support['overdue'], ['target' => 'tickets', 'query' => ['tab' => 'due_first_response']], []],
            ['trials_ending', 'warning', $s['trials_ending'], ['target' => 'clients', 'query' => ['filters' => ['trial' => ['value' => 'ending_soon']]]], []],
            ['unassigned_tickets', 'warning', (int) $support['unassigned'], ['target' => 'tickets', 'query' => ['tab' => 'unassigned']], []],
            ['low_ratings', 'warning', (int) $f['low_ratings']['current'], ['target' => 'feedback'], ['rating' => self::LOW_RATING]],
            ['reopened_tickets', 'warning', (int) $support['reopened']['value'], ['target' => 'tickets'], []],
            ['limits', 'warning', $limits, ['target' => 'clients'], ['percent' => self::NEAR_LIMIT_PERCENT]],
            ['calendar_errors', 'warning', (int) $usage['calendars']['error'], ['target' => 'clients'], []],
            ['messages_failed', 'warning', (int) $usage['messages_failed'], ['target' => 'clients'], []],
            ['no_whatsapp', 'info', $noWhatsapp, ['target' => 'clients'], []],
            ['no_practitioner', 'info', $noPractitioner, ['target' => 'clients'], []],
            ['dormant', 'info', $s['dormant'], ['target' => 'clients', 'query' => ['filters' => ['activity' => ['value' => 'inactive_30']]]], ['days' => self::DORMANT_DAYS]],
            ['renewals', 'info', $s['renewals_7']['count'], ['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['active']]]]], ['amount_cents' => $s['renewals_7']['mrr_cents']]],
        ];
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];

        return collect($rows)->filter(fn (array $r): bool => $r[2] > 0)->map(fn (array $r): array => ['key' => $r[0], 'severity' => $r[1], 'count' => (int) $r[2], 'link' => $r[3], 'params' => $r[4]])
            ->sortBy(fn (array $a, int $i): string => $order[$a['severity']].str_pad((string) (1000 - min($a['count'], 999)), 4, '0', STR_PAD_LEFT).str_pad((string) $i, 2, '0', STR_PAD_LEFT))->values()->all();
    }

    /** Clients dont les praticiens ou les lieux actifs atteignent la part d'alerte d'une limite de formule. */
    private function nearLimit(): int
    {
        $threshold = self::NEAR_LIMIT_PERCENT / 100;

        return (int) $this->db()->selectOne(
            "select count(*) as n from organizations o
             join subscriptions s on s.org_id = o.id and s.status in ('active','past_due')
             join plans pl on pl.id = s.plan_id
             where o.deleted_at is null and (
                (nullif(pl.limits->>'practitioners', '')::int > 0 and (select count(*) from practitioners x where x.org_id = o.id and x.deleted_at is null and x.status = 'active') >= nullif(pl.limits->>'practitioners', '')::int * cast(? as numeric))
                or (nullif(pl.limits->>'locations', '')::int > 0 and (select count(*) from locations x where x.org_id = o.id and x.deleted_at is null) >= nullif(pl.limits->>'locations', '')::int * cast(? as numeric))
             )",
            [$threshold, $threshold]
        )->n;
    }

    // ---- Activité récente ----------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array{type: string, at: string, client: string|null, org_id: string|null, ticket_id: string|null, reference: string|null, detail: string|null}>
     */
    private function activity(): array
    {
        $db = $this->db();
        $events = [];

        foreach ($db->table('organizations')->whereNull('deleted_at')->orderByDesc('created_at')->limit(6)->get(['id', 'name', 'created_at']) as $r) {
            $events[] = $this->event('client_signed_up', $r->created_at, (string) $r->name, (string) $r->id);
        }

        $actions = ['billing.subscribed' => 'subscribed', 'billing.upgraded' => 'upgraded', 'billing.downgrade_scheduled' => 'downgrade_scheduled', 'billing.cancel_requested' => 'cancel_requested', 'billing.subscription_ended' => 'subscription_ended', 'billing.paid' => 'payment_received', 'billing.payment_failed' => 'payment_failed', 'organization.suspended' => 'suspended', 'organization.suspension_lifted' => 'suspension_lifted', 'organization.deletion_scheduled' => 'deletion_scheduled', 'client.updated' => 'details_updated', 'client.trial_extended' => 'trial_extended'];
        $rows = $db->table('audit_logs as a')->leftJoin('organizations as o', 'o.id', '=', 'a.org_id')->whereIn('a.action', array_keys($actions))->orderByDesc('a.created_at')->limit(14)->get(['a.action', 'a.created_at', 'a.org_id', 'o.name', 'a.diff']);

        foreach ($rows as $r) {
            $diff = $r->diff !== null ? (array) json_decode((string) $r->diff, true) : [];
            $events[] = $this->event($actions[$r->action], $r->created_at, $r->name !== null ? (string) $r->name : null, $r->org_id !== null ? (string) $r->org_id : null, null, null, isset($diff['plan']) ? (string) $diff['plan'] : null);
        }

        foreach ($db->table('support_tickets as t')->join('organizations as o', 'o.id', '=', 't.org_id')->orderByDesc('t.created_at')->limit(5)->get(['t.id', 't.reference_number', 't.created_at', 't.org_id', 'o.name']) as $r) {
            $events[] = $this->event('ticket_created', $r->created_at, (string) $r->name, (string) $r->org_id, (string) $r->id, 'NWS-'.$r->reference_number);
        }

        foreach ($db->table('support_tickets as t')->join('organizations as o', 'o.id', '=', 't.org_id')->whereNotNull('t.resolved_at')->orderByDesc('t.resolved_at')->limit(5)->get(['t.id', 't.reference_number', 't.resolved_at', 't.org_id', 'o.name']) as $r) {
            $events[] = $this->event('ticket_resolved', $r->resolved_at, (string) $r->name, (string) $r->org_id, (string) $r->id, 'NWS-'.$r->reference_number);
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, 12);
    }

    /**
     * @return array{type: string, at: string, client: string|null, org_id: string|null, ticket_id: string|null, reference: string|null, detail: string|null}
     */
    private function event(string $type, mixed $at, ?string $client, ?string $orgId, ?string $ticketId = null, ?string $reference = null, ?string $detail = null): array
    {
        return ['type' => $type, 'at' => Carbon::parse($at)->toIso8601String(), 'client' => $client, 'org_id' => $orgId, 'ticket_id' => $ticketId, 'reference' => $reference, 'detail' => $detail];
    }

    // ---- Outils --------------------------------------------------------------------------------------------------------------------------

    private function db(): Connection
    {
        return DB::connection(PlatformAudit::CONNECTION);
    }

    /**
     * @param  callable(bool): array<string, mixed>  $make
     * @return array{current: array<string, mixed>, previous: array<string, mixed>}
     */
    private function pair(callable $make): array
    {
        return ['current' => $make(false), 'previous' => $make(true)];
    }

    /**
     * Série par tranche (jour ou mois) : clé = début de tranche (AAAA-MM-JJ), sans trou. Les valeurs manquantes valent 0 (ou `$empty`).
     *
     * @return array<string, int|float|null>
     */
    private function series(Builder $query, string $column, string $value, DashboardPeriod $p, bool $previous, int|float|null $empty = 0): array
    {
        $from = $previous ? $p->previousFrom : $p->from;
        $until = $previous ? $p->previousUntil : $p->until;
        $step = $p->bucket();
        $rows = $query->whereBetween($column, [$from, $until])
            ->selectRaw("to_char(date_trunc('{$step}', {$column} at time zone ?), 'YYYY-MM-DD') as k, {$value} as v", [$this->tz])->groupBy('k')->pluck('v', 'k');

        return $this->fill($p, $previous, fn (string $k): int|float|null => isset($rows[$k]) ? (str_contains((string) $rows[$k], '.') ? (float) $rows[$k] : (int) $rows[$k]) : $empty);
    }

    /**
     * @param  callable(string): (int|float|null)  $value
     * @return array<string, int|float|null>
     */
    private function fill(DashboardPeriod $p, bool $previous, callable $value): array
    {
        $out = [];

        foreach ($p->buckets($previous) as $bucket) {
            $key = $bucket->format('Y-m-d');
            $out[$key] = $value($key);
        }

        return $out;
    }
}
