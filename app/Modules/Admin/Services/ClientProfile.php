<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Billing\Support\PlanLimits;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lecture d'un client pour sa fiche Super Admin (docs/31) : résumé, abonnement, usage et santé du compte, historique, membres.
 * Tout se lit avec le rôle `nova_platform` (lecture transverse) ; jamais de jeton de paiement, de mot de passe ni de secret :
 * le moyen de paiement se limite à la marque, aux quatre derniers chiffres et à l'échéance.
 */
final class ClientProfile
{
    /** Seuil « proche de la limite » (part de la limite utilisée). */
    public const NEAR_LIMIT_PERCENT = 80;

    /** Jours sans activité avant l'indicateur « pas d'activité récente ». */
    public const INACTIVE_DAYS = 30;

    private function db(): Connection
    {
        return DB::connection(PlatformAudit::CONNECTION);
    }

    /**
     * Ligne de la liste pour un client : état d'abonnement, formule, échéance, MRR, contact, membres, dernière activité.
     */
    public function row(PlatformOrganization $org): ?PlatformOrganization
    {
        return ClientDirectory::query()->where('organizations.id', $org->id)->first();
    }

    /**
     * Chiffres de la fiche : ancienneté, abonnement, moyen de paiement, équipe, rendez-vous, support, solde dû.
     *
     * @return array<string, mixed>
     */
    public function summary(PlatformOrganization $org): array
    {
        $row = $this->row($org);
        $id = $org->id;
        $now = now();

        $appointments = $this->db()->table('bookings')->where('org_id', $id)->selectRaw(
            "count(*) as total,
             count(*) filter (where starts_at > ? and status in ('pending','confirmed')) as upcoming,
             count(*) filter (where status = 'completed') as completed,
             count(*) filter (where created_at >= ?) as this_month",
            [$now, $now->copy()->startOfMonth()]
        )->first();

        $tickets = $this->db()->table('support_tickets')->where('org_id', $id)->whereIn('status', TicketStatus::ACTIVE)->selectRaw(
            "count(*) as open, count(*) filter (where coalesce(staff_priority, urgency) in ('urgent','high')) as urgent"
        )->first();

        $messages = $this->db()->table('whatsapp_messages')->where('org_id', $id)->selectRaw(
            "count(*) filter (where direction = 'out') as sent, count(*) filter (where created_at >= ?) as this_month, count(distinct customer_id) filter (where created_at >= ?) as conversations_month",
            [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()]
        )->first();

        return [
            'since' => $org->created_at,
            'plan' => $row?->getAttribute('plan_name'),
            'state' => (string) ($row?->getAttribute('subscription_state') ?? 'none'),
            'renewal_at' => $this->date($row?->getAttribute('renewal_at')),
            'mrr_cents' => (int) ($row?->getAttribute('mrr_cents') ?? 0),
            'currency' => (string) ($row?->getAttribute('plan_currency') ?? 'EUR'),
            'payment_method' => $this->paymentMethod($id),
            'members' => (int) ($row?->getAttribute('members_count') ?? 0),
            'locations' => $this->db()->table('locations')->where('org_id', $id)->whereNull('deleted_at')->count(),
            'practitioners' => $this->db()->table('practitioners')->where('org_id', $id)->whereNull('deleted_at')->count(),
            'services' => $this->db()->table('services')->where('org_id', $id)->count(),
            'appointments_total' => (int) $appointments->total,
            'appointments_upcoming' => (int) $appointments->upcoming,
            'appointments_completed' => (int) $appointments->completed,
            'appointments_month' => (int) $appointments->this_month,
            'messages_sent' => (int) $messages->sent,
            'messages_month' => (int) $messages->this_month,
            'conversations_month' => (int) $messages->conversations_month,
            'last_activity' => $this->date($row?->getAttribute('last_activity_at')),
            'open_tickets' => (int) $tickets->open,
            'urgent_tickets' => (int) $tickets->urgent,
            'outstanding_cents' => (int) $this->db()->table('invoices')->where('org_id', $id)->where('status', 'open')->sum('total_cents'),
            'owner' => ['email' => $row?->getAttribute('owner_email'), 'phone' => $row?->getAttribute('owner_phone')],
            'whatsapp_connected' => $this->db()->table('whatsapp_accounts')->where('org_id', $id)->where('status', 'active')->exists(),
        ];
    }

    /**
     * Moyen de paiement résumé : « ok », « expiring » (échéance dans les 60 jours), « expired » ou « none ». Jamais le jeton du prestataire.
     *
     * @return array{status: string, brand: string|null, last4: string|null, expires: string|null}
     */
    public function paymentMethod(string $orgId): array
    {
        $method = $this->db()->table('payment_methods')->where('org_id', $orgId)->orderByDesc('created_at')->first(['brand', 'last4', 'exp_month', 'exp_year']);

        if ($method === null) {
            return ['status' => 'none', 'brand' => null, 'last4' => null, 'expires' => null];
        }

        $status = 'ok';

        if ($method->exp_month !== null && $method->exp_year !== null) {
            $end = Carbon::create((int) $method->exp_year, (int) $method->exp_month, 1)->endOfMonth();
            $status = $end->isPast() ? 'expired' : ($end->lt(now()->addDays(60)) ? 'expiring' : 'ok');
        }

        return [
            'status' => $status, 'brand' => $method->brand, 'last4' => $method->last4,
            'expires' => $method->exp_month === null ? null : sprintf('%02d/%d', $method->exp_month, $method->exp_year),
        ];
    }

    /**
     * Abonnement en détail.
     *
     * @return array<string, mixed>
     */
    public function subscription(PlatformOrganization $org): array
    {
        $row = $this->row($org);
        $sub = $this->db()->table('subscriptions')->where('org_id', $org->id)->first();
        $plan = $row?->getAttribute('plan_code') === null ? null : $this->db()->table('plans')->where('code', $row->getAttribute('plan_code'))->first();
        $invoice = $this->db()->table('invoices')->where('org_id', $org->id)->orderByDesc('issued_at')->first(['vat_rate', 'total_cents', 'status', 'number']);
        $pending = $sub?->pending_plan_id === null ? null : $this->db()->table('plans')->where('id', $sub->pending_plan_id)->value('name');

        return [
            'state' => (string) ($row?->getAttribute('subscription_state') ?? 'none'),
            'plan' => $row?->getAttribute('plan_name'),
            'pending_plan' => $pending,
            'interval' => 'monthly',
            'started_at' => $this->date($sub?->created_at),
            'period_start' => $this->date($sub?->current_period_start),
            'period_end' => $this->date($sub?->current_period_end),
            'next_billing' => $sub !== null && $sub->status === 'active' && $sub->cancel_at === null ? $this->date($sub->current_period_end) : null,
            'cancel_at' => $this->date($sub?->cancel_at),
            'trial_start' => $org->created_at,
            'trial_end' => $org->trial_ends_at,
            'auto_renewal' => $sub !== null && $sub->status === 'active' && $sub->cancel_at === null,
            'failed_attempts' => (int) ($sub->failed_attempts ?? 0),
            'next_attempt_at' => $this->date($sub?->next_attempt_at),
            'currency' => (string) ($plan->currency ?? 'EUR'),
            'price_monthly_cents' => $plan?->price_monthly_cents,
            'amount_due_cents' => (int) $this->db()->table('invoices')->where('org_id', $org->id)->where('status', 'open')->sum('total_cents'),
            'vat_rate' => $invoice?->vat_rate,
            'payment_method' => $this->paymentMethod($org->id),
            'limits' => (array) json_decode((string) ($plan->limits ?? '[]'), true),
            'provider' => $sub?->provider,
            'subscription_id' => $sub?->id,
        ];
    }

    /**
     * Usage mesuré face aux limites de la formule. Chaque ligne : valeur, limite (null = illimitée), part utilisée.
     *
     * @param  array<string, mixed>|null  $summary
     * @return list<array{key: string, value: int, limit: int|null, percent: int|null}>
     */
    public function usage(PlatformOrganization $org, ?array $summary = null): array
    {
        $summary ??= $this->summary($org);
        $limits = app(PlanLimits::class);
        $rows = [];

        foreach ([
            'practitioners' => $summary['practitioners'], 'locations' => $summary['locations'],
            'appointments' => $summary['appointments_month'], 'conversations' => $summary['conversations_month'],
        ] as $key => $value) {
            $limit = $limits->limit($org, $key);
            $rows[] = ['key' => $key, 'value' => (int) $value, 'limit' => $limit, 'percent' => $limit === null || $limit === 0 ? null : (int) round($value * 100 / $limit)];
        }

        return $rows;
    }

    /**
     * Indicateurs de santé du compte. `level` : « info » (à savoir), « watch » (à suivre), « action » (à traiter). Chaque indicateur a un texte
     * qui explique ce qu'il signifie (clients.health.*).
     *
     * @param  array<string, mixed>|null  $summary
     * @return list<array{key: string, level: string, params: array<string, string|int>}>
     */
    public function health(PlatformOrganization $org, ?array $summary = null): array
    {
        $summary ??= $this->summary($org);
        $flags = [];
        $add = function (string $key, string $level, array $params = []) use (&$flags): void {
            $flags[] = ['key' => $key, 'level' => $level, 'params' => $params];
        };

        if (in_array($org->status, ['suspended', 'pending_deletion'], true)) {
            $add($org->status === 'suspended' ? 'suspended' : 'pending_deletion', 'action');
        }

        if ($summary['state'] === 'paused') {
            $add('paused', 'info');
        }

        if ($summary['state'] === 'trial' && $org->trial_ends_at !== null && $org->trial_ends_at->between(now(), now()->addDays(3))) {
            $add('trial_ending', 'watch', ['date' => $org->trial_ends_at->format('d/m/Y')]);
        }

        if ($summary['state'] === 'expired') {
            $add('trial_expired', 'action');
        }

        if ($summary['state'] === 'past_due') {
            $add('past_due', 'action');
        }

        if ($this->db()->table('payments')->where('org_id', $org->id)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->exists() && $summary['state'] !== 'past_due') {
            $add('failed_payment', 'watch');
        }

        if (in_array($summary['state'], ['active', 'past_due'], true) && $summary['payment_method']['status'] === 'none') {
            $add('no_payment_method', 'action');
        } elseif (in_array($summary['payment_method']['status'], ['expiring', 'expired'], true)) {
            $add('card_'.$summary['payment_method']['status'], 'watch');
        }

        foreach ($this->usage($org, $summary) as $row) {
            if ($row['percent'] === null) {
                continue;
            }

            if ($row['value'] > $row['limit']) {
                $add('limit_exceeded', 'action', ['item' => $row['key'], 'value' => $row['value'], 'limit' => (int) $row['limit']]);
            } elseif ($row['percent'] >= self::NEAR_LIMIT_PERCENT) {
                $add('limit_near', 'watch', ['item' => $row['key'], 'value' => $row['value'], 'limit' => (int) $row['limit']]);
            }
        }

        $last = $summary['last_activity'];

        if ($org->created_at->lt(now()->subDays(7)) && ($last === null || $last->lt(now()->subDays(self::INACTIVE_DAYS)))) {
            $add('inactive', 'watch', ['days' => self::INACTIVE_DAYS]);
        }

        if ($summary['practitioners'] === 0) {
            $add('no_practitioner', 'watch');
        }

        if (! $summary['whatsapp_connected']) {
            $add('no_whatsapp', 'watch');
        }

        if ($summary['urgent_tickets'] > 0) {
            $add('urgent_tickets', 'action', ['count' => $summary['urgent_tickets']]);
        }

        return $flags;
    }

    /**
     * Historique de l'abonnement, du plus récent au plus ancien : événements de facturation et de compte (journal d'audit), factures émises,
     * paiements réussis ou refusés et relances. Chaque ligne : date, type, détail, acteur et référence.
     *
     * @return list<array{at: Carbon, type: string, detail: string, actor: string, reference: string|null}>
     */
    public function history(PlatformOrganization $org, int $limit = 60): array
    {
        $events = [];
        $admins = $this->db()->table('platform_admins')->pluck('email', 'id');

        $audits = $this->db()->table('audit_logs')->where('org_id', $org->id)
            ->where(fn ($q) => $q->where('action', 'like', 'billing.%')->orWhere('action', 'like', 'organization.%')->orWhere('action', 'like', 'client.trial%')->orWhere('action', 'like', 'client.updated'))
            ->orderByDesc('created_at')->limit($limit)->get(['action', 'actor_type', 'actor_id', 'diff', 'reason', 'created_at', 'entity_id']);

        foreach ($audits as $audit) {
            $diff = (array) json_decode((string) $audit->diff, true);
            $events[] = [
                'at' => Carbon::parse($audit->created_at),
                'type' => $audit->action,
                'detail' => $this->summarise($diff).($audit->reason !== null ? ' — '.$audit->reason : ''),
                'actor' => $audit->actor_type === 'platform_admin' ? (string) ($admins[$audit->actor_id] ?? 'Super Admin') : $audit->actor_type,
                'reference' => null,
            ];
        }

        foreach ($this->db()->table('invoices')->where('org_id', $org->id)->orderByDesc('issued_at')->limit($limit)->get(['number', 'issued_at', 'total_cents', 'currency', 'status']) as $invoice) {
            $events[] = ['at' => Carbon::parse($invoice->issued_at), 'type' => 'invoice.issued', 'detail' => $this->money($invoice->total_cents, $invoice->currency).' · '.$invoice->status, 'actor' => 'system', 'reference' => $invoice->number];
        }

        foreach ($this->db()->table('payments as p')->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')->where('p.org_id', $org->id)->orderByDesc('p.created_at')->limit($limit)->get(['p.status', 'p.amount_cents', 'p.failure_code', 'p.created_at', 'i.number', 'i.currency']) as $payment) {
            $events[] = [
                'at' => Carbon::parse($payment->created_at), 'type' => $payment->status === 'succeeded' ? 'payment.succeeded' : 'payment.failed',
                'detail' => $this->money($payment->amount_cents, $payment->currency ?? 'EUR').($payment->failure_code !== null ? ' · '.$payment->failure_code : ''), 'actor' => 'billing_provider', 'reference' => $payment->number,
            ];
        }

        foreach ($this->db()->table('dunning_events as d')->leftJoin('invoices as i', 'i.id', '=', 'd.invoice_id')->where('d.org_id', $org->id)->orderByDesc('d.sent_at')->limit($limit)->get(['d.attempt', 'd.channel', 'd.sent_at', 'i.number']) as $dunning) {
            $events[] = ['at' => Carbon::parse($dunning->sent_at), 'type' => 'dunning.sent', 'detail' => "#{$dunning->attempt} · {$dunning->channel}", 'actor' => 'system', 'reference' => $dunning->number];
        }

        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }

    /**
     * Membres du compte (Super Admin : jamais de secret).
     *
     * @return list<object{user_id: string, email: string, phone: string|null, locale: string|null, role: string, member_status: string, user_status: string, two_factor: bool, email_verified: bool, last_login_at: string|null, joined_at: string}>
     */
    public function members(PlatformOrganization $org): array
    {
        return $this->db()->select('select * from org_member_directory(?)', [$org->id]);
    }

    /**
     * Notes internes de l'équipe, de la plus récente à la plus ancienne.
     *
     * @return list<array{at: Carbon, author: string, body: string}>
     */
    public function notes(PlatformOrganization $org): array
    {
        $admins = $this->db()->table('platform_admins')->pluck('email', 'id');

        return $this->db()->table('client_notes')->where('org_id', $org->id)->orderByDesc('created_at')->limit(50)->get()
            ->map(fn (object $note): array => ['at' => Carbon::parse($note->created_at), 'author' => (string) ($admins[$note->author_id] ?? '—'), 'body' => $note->body])->all();
    }

    /**
     * @return list<string>
     */
    public function tags(PlatformOrganization $org): array
    {
        return $this->db()->table('client_tags')->where('org_id', $org->id)->orderBy('tag')->pluck('tag')->all();
    }

    private function date(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    private function money(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2, ',', ' ').' '.$currency;
    }

    /**
     * @param  array<array-key, mixed>  $diff
     */
    private function summarise(array $diff): string
    {
        $parts = [];

        foreach ($diff as $key => $value) {
            if (is_array($value) && count($value) === 2 && ! is_array($value[0] ?? null)) {
                $parts[] = "{$key}: ".($value[0] ?? '—').' → '.($value[1] ?? '—');
            } elseif (is_scalar($value)) {
                $parts[] = "{$key}: {$value}";
            }
        }

        return implode(' · ', $parts);
    }
}
