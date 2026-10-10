<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports sur les clients (docs/31 §11). Chaque indicateur a une définition fixe, affichée sur la page.
 *
 *  - INSTANTANÉS (état actuel) : clients par formule et par état d'abonnement, MRR, renouvellements à venir, activité.
 *  - SUR LA PÉRIODE (du … au …) : nouveaux clients par mois, essais commencés et convertis, abonnements terminés, paiements refusés,
 *    tickets par client.
 * Filtres : période, formule, état d'abonnement, pays (ils s'appliquent aux indicateurs liés aux clients ; les paiements refusés et les
 * tickets suivent les clients retenus). L'historique du MRR n'est pas conservé : seule sa valeur actuelle est donnée.
 */
final class ClientReport
{
    /**
     * @param  array{from?: string|null, until?: string|null, plan?: string|null, state?: string|null, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function compute(array $filters, ?Carbon $now = null): array
    {
        $now ??= now();
        $until = ! empty($filters['until']) ? Carbon::parse((string) $filters['until'])->endOfDay() : $now->copy()->endOfDay();
        $from = ! empty($filters['from']) ? Carbon::parse((string) $filters['from'])->startOfDay() : $until->copy()->subMonths(11)->startOfMonth();
        $db = DB::connection(PlatformAudit::CONNECTION);

        $base = fn () => $db->query()->fromSub($this->filtered($filters)->toBase(), 'c');
        $ids = fn () => $this->filtered($filters)->toBase()->select('organizations.id');

        $byPlan = $base()->selectRaw("coalesce(plan_name, '—') as label, count(*) as total, coalesce(sum(mrr_cents), 0) as mrr")->groupBy('plan_name')->orderByDesc('total')->get();
        $byState = $base()->selectRaw('subscription_state as label, count(*) as total')->groupBy('subscription_state')->pluck('total', 'label');

        $months = $base()->whereBetween('created_at', [$from, $until])->selectRaw("to_char(date_trunc('month', created_at), 'YYYY-MM') as month, count(*) as total")->groupByRaw("date_trunc('month', created_at)")->orderByRaw("date_trunc('month', created_at)")->pluck('total', 'month');

        $started = (int) $base()->whereBetween('created_at', [$from, $until])->count();
        $converted = (int) $base()->whereBetween('created_at', [$from, $until])->whereNotNull('subscription_id')->count();

        return [
            'period' => ['from' => $from->toDateString(), 'until' => $until->toDateString()],
            'total' => (int) $base()->count(),
            'by_plan' => $byPlan->map(fn (object $row): array => ['label' => $row->label, 'total' => (int) $row->total, 'mrr_cents' => (int) $row->mrr])->all(),
            'by_state' => collect(ClientDirectory::STATES)->mapWithKeys(fn (string $state): array => [$state => (int) ($byState[$state] ?? 0)])->all(),
            'new_by_month' => $months->map(fn ($total): int => (int) $total)->all(),
            'trials_started' => $started,
            'trials_converted' => $converted,
            'conversion_percent' => $started === 0 ? null : round($converted * 100 / $started, 1),
            'mrr_cents' => (int) $base()->sum('mrr_cents'),
            'renewals_7' => $this->renewals($base, 7, $now),
            'renewals_30' => $this->renewals($base, 30, $now),
            'cancelled' => (int) $db->table('audit_logs')->where('action', 'billing.subscription_ended')->whereBetween('created_at', [$from, $until])->whereIn('org_id', $ids())->count(),
            'failed_payments' => (int) $db->table('payments')->where('status', 'failed')->whereBetween('created_at', [$from, $until])->whereIn('org_id', $ids())->count(),
            'failed_clients' => (int) $db->table('payments')->where('status', 'failed')->whereBetween('created_at', [$from, $until])->whereIn('org_id', $ids())->distinct()->count('org_id'),
            'active_7' => (int) $base()->where('last_activity_at', '>=', $now->copy()->subDays(7))->count(),
            'active_30' => (int) $base()->where('last_activity_at', '>=', $now->copy()->subDays(30))->count(),
            'dormant' => (int) $base()->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $now->copy()->subDays(30)))->count(),
            'tickets_by_client' => $db->table('support_tickets as t')->join('organizations as o', 'o.id', '=', 't.org_id')->whereIn('t.org_id', $ids())->whereBetween('t.created_at', [$from, $until])
                ->selectRaw('o.name, count(*) as total')->groupBy('o.id', 'o.name')->orderByDesc('total')->limit(10)->get()->map(fn (object $row): array => ['name' => $row->name, 'total' => (int) $row->total])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PlatformOrganization>
     */
    private function filtered(array $filters): Builder
    {
        return ClientDirectory::query()
            ->when(! empty($filters['plan']), fn ($q) => $q->whereRaw('pl.id = ?', [$filters['plan']]))
            ->when(! empty($filters['state']), fn ($q) => $q->whereRaw('('.ClientDirectory::STATE_SQL.') = ?', [$filters['state']]))
            ->when(! empty($filters['country']), fn ($q) => $q->where('organizations.country', $filters['country']));
    }

    /**
     * Clients actifs dont l'échéance tombe dans les N prochains jours : nombre et MRR concerné.
     *
     * @return array{count: int, mrr_cents: int}
     */
    private function renewals(callable $base, int $days, Carbon $now): array
    {
        $row = $base()->where('subscription_state', 'active')->whereBetween('renewal_at', [$now, $now->copy()->addDays($days)])->selectRaw('count(*) as total, coalesce(sum(mrr_cents), 0) as mrr')->first();

        return ['count' => (int) $row->total, 'mrr_cents' => (int) $row->mrr];
    }
}
