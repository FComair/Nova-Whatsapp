<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres du tableau de bord du support (docs/29 §9). Chaque indicateur a une définition fixe, affichée à l'écran :
 *
 *  - INSTANTANÉS (état actuel, la période ne les limite pas) : tickets ouverts, non assignés, en attente du client, en retard / à risque,
 *    résolus aujourd'hui / cette semaine / ce mois.
 *  - SUR LA PÉRIODE (tickets créés entre « du » et « au ») : répartition par état, priorité, catégorie, agent ; délai moyen de première
 *    réponse ; délai moyen de résolution ; part des premières réponses dans les temps ; réouvertures (événements « rouvert » de la période).
 *
 * Filtres communs : agent (ou « non assigné »), catégorie, priorité (celle de l'équipe, sinon celle du client), compte client.
 * Un agent de support ne voit que ses tickets et les non assignés ; les chiffres sont calculés sur cette portée.
 */
final class TicketMetrics
{
    /**
     * @param  array{from?: string|null, until?: string|null, agent?: string|null, category?: string|null, priority?: string|null, organization?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function compute(PlatformAdmin $admin, array $filters, ?Carbon $now = null): array
    {
        $now ??= now();
        [$from, $until] = $this->period($filters);

        $period = fn (): Builder => $this->base($admin, $filters)->whereBetween('support_tickets.created_at', [$from, $until]);
        $open = fn (): Builder => $this->base($admin, $filters)->whereIn('support_tickets.status', TicketStatus::ACTIVE);
        $effective = 'coalesce(support_tickets.staff_priority, support_tickets.urgency)';

        return [
            'period' => ['from' => $from->toDateString(), 'until' => $until->toDateString()],
            'open' => $open()->count(),
            'unassigned' => $open()->whereNull('support_tickets.assigned_admin_id')->count(),
            'waiting_on_client' => $this->base($admin, $filters)->where('support_tickets.status', TicketStatus::WAITING_ON_YOU)->count(),
            'overdue' => $this->overdue($open(), $now, atRisk: false)->count(),
            'at_risk' => $this->overdue($open(), $now, atRisk: true)->count(),
            'resolved_today' => $this->resolvedSince($admin, $filters, $now->copy()->startOfDay()),
            'resolved_week' => $this->resolvedSince($admin, $filters, $now->copy()->startOfWeek()),
            'resolved_month' => $this->resolvedSince($admin, $filters, $now->copy()->startOfMonth()),
            'created' => $period()->count(),
            'by_status' => $this->grouped($period(), 'support_tickets.status', TicketStatus::ALL),
            'by_priority' => $this->grouped($period(), $effective, ['urgent', 'high', 'medium', 'low']),
            'by_category' => $this->grouped($period(), 'support_tickets.category', ['technical', 'feature', 'billing', 'access', 'other']),
            'by_agent' => $this->byAgent($period()),
            'first_response_hours' => $this->averageHours($period()->whereNotNull('support_tickets.first_response_at'), 'support_tickets.first_response_at'),
            'resolution_hours' => $this->averageHours($period()->whereRaw('coalesce(support_tickets.resolved_at, support_tickets.closed_at) is not null'), 'coalesce(support_tickets.resolved_at, support_tickets.closed_at)'),
            'first_response_on_time' => $this->onTimeShare($period()),
            'reopened' => $this->reopened($admin, $filters, $from, $until),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(array $filters): array
    {
        $until = isset($filters['until']) && $filters['until'] !== '' ? Carbon::parse((string) $filters['until'])->endOfDay() : now()->endOfDay();
        $from = isset($filters['from']) && $filters['from'] !== '' ? Carbon::parse((string) $filters['from'])->startOfDay() : $until->copy()->subDays(29)->startOfDay();

        return [$from, $until];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PlatformSupportTicket>
     */
    private function base(PlatformAdmin $admin, array $filters): Builder
    {
        $query = SupportAccess::scope(PlatformSupportTicket::query(), $admin);
        $agent = (string) ($filters['agent'] ?? '');

        return $query
            ->when($agent === 'none', fn (Builder $q) => $q->whereNull('support_tickets.assigned_admin_id'))
            ->when($agent !== '' && $agent !== 'none', fn (Builder $q) => $q->where('support_tickets.assigned_admin_id', $agent))
            ->when(($filters['category'] ?? '') !== '', fn (Builder $q) => $q->where('support_tickets.category', $filters['category']))
            ->when(($filters['priority'] ?? '') !== '', fn (Builder $q) => $q->whereRaw('coalesce(support_tickets.staff_priority, support_tickets.urgency) = ?', [$filters['priority']]))
            ->when(($filters['organization'] ?? '') !== '', fn (Builder $q) => $q->where('support_tickets.org_id', $filters['organization']));
    }

    /**
     * Tickets sans première réponse : « en retard » après l'échéance, « à risque » une fois la part de délai écoulée (sans être en retard).
     *
     * @param  Builder<PlatformSupportTicket>  $query
     * @return Builder<PlatformSupportTicket>
     */
    private function overdue(Builder $query, Carbon $now, bool $atRisk): Builder
    {
        $query->whereNull('support_tickets.first_response_at')->whereNotNull('support_tickets.first_response_due_at');

        if (! $atRisk) {
            return $query->where('support_tickets.first_response_due_at', '<=', $now);
        }

        $percent = app(PlatformSettings::class)->integer('support_at_risk_percent');

        return $query->where('support_tickets.first_response_due_at', '>', $now)
            ->whereRaw('extract(epoch from (?::timestamptz - support_tickets.created_at)) * 100 >= extract(epoch from (support_tickets.first_response_due_at - support_tickets.created_at)) * ?', [$now->toDateTimeString(), $percent]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolvedSince(PlatformAdmin $admin, array $filters, Carbon $since): int
    {
        return $this->base($admin, $filters)->whereNotNull('support_tickets.resolved_at')->where('support_tickets.resolved_at', '>=', $since)->count();
    }

    /**
     * @param  Builder<PlatformSupportTicket>  $query
     * @param  list<string>  $order
     * @return array<string, int>
     */
    private function grouped(Builder $query, string $column, array $order): array
    {
        $counts = $query->selectRaw("{$column} as bucket, count(*) as total")->groupByRaw($column)->pluck('total', 'bucket');

        return collect($order)->mapWithKeys(fn (string $key): array => [$key => (int) ($counts[$key] ?? 0)])->all();
    }

    /**
     * @param  Builder<PlatformSupportTicket>  $query
     * @return list<array{agent: string|null, email: string|null, total: int, open: int}>
     */
    private function byAgent(Builder $query): array
    {
        $rows = $query->select('support_tickets.assigned_admin_id', DB::raw('count(*) as total'), DB::raw('count(*) filter (where support_tickets.status in (\''.implode("','", TicketStatus::ACTIVE).'\')) as open'))
            ->groupBy('support_tickets.assigned_admin_id')->orderByDesc('total')->toBase()->get();
        $emails = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id');

        return $rows->map(fn ($row): array => [
            'agent' => $row->assigned_admin_id,
            'email' => $row->assigned_admin_id === null ? null : ($emails[$row->assigned_admin_id] ?? null),
            'total' => (int) $row->total,
            'open' => (int) $row->open,
        ])->all();
    }

    /**
     * @param  Builder<PlatformSupportTicket>  $query
     */
    private function averageHours(Builder $query, string $until): ?float
    {
        $value = $query->selectRaw("avg(extract(epoch from ({$until} - support_tickets.created_at))) / 3600 as hours")->value('hours');

        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * Part des premières réponses données avant l'échéance, parmi les tickets de la période qui ont une réponse et une échéance.
     *
     * @param  Builder<PlatformSupportTicket>  $query
     */
    private function onTimeShare(Builder $query): ?float
    {
        $row = $query->whereNotNull('support_tickets.first_response_at')->whereNotNull('support_tickets.first_response_due_at')
            ->selectRaw('count(*) as total, count(*) filter (where support_tickets.first_response_at <= support_tickets.first_response_due_at) as on_time')->toBase()->first();

        return $row === null || (int) $row->total === 0 ? null : round((int) $row->on_time * 100 / (int) $row->total, 1);
    }

    /**
     * Réouvertures de la période : événements « rouvert » (par le client ou l'équipe) de tickets dans la portée et les filtres.
     *
     * @param  array<string, mixed>  $filters
     */
    private function reopened(PlatformAdmin $admin, array $filters, Carbon $from, Carbon $until): int
    {
        return DB::connection(PlatformAudit::CONNECTION)->table('support_ticket_events')
            ->where('action', 'reopened')->whereBetween('created_at', [$from, $until])
            ->whereIn('ticket_id', $this->base($admin, $filters)->select('support_tickets.id'))
            ->count();
    }
}
