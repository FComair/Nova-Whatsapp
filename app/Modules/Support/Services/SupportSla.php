<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Support\Carbon;

/**
 * Délai cible de première réponse (docs/29 §6). Un seul engagement mesuré : le temps entre la création du ticket et la première réponse
 * publique de l'équipe. Les cibles viennent de `platform_settings` (heures écoulées, horloge continue, selon la priorité) ; jamais en dur.
 *
 * États : `null` (dans les temps, ou déjà répondu), « at_risk » (la part `support_at_risk_percent` du délai est écoulée), « overdue » (échu).
 */
final class SupportSla
{
    public const AT_RISK = 'at_risk';

    public const OVERDUE = 'overdue';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function targetHours(string $priority): int
    {
        return $this->settings->integer('support_sla_'.(in_array($priority, SupportTicket::PRIORITIES, true) ? $priority : 'medium').'_hours');
    }

    public function dueAt(Carbon $from, string $priority): Carbon
    {
        return $from->copy()->addHours($this->targetHours($priority));
    }

    /** « at_risk », « overdue » ou null à l'instant donné. */
    public function stateOf(SupportTicket $ticket, ?Carbon $now = null): ?string
    {
        $now ??= now();

        if ($ticket->first_response_at !== null || $ticket->first_response_due_at === null || ! TicketStatus::isOpenWork($ticket->status)) {
            return null;
        }

        if ($now->greaterThanOrEqualTo($ticket->first_response_due_at)) {
            return self::OVERDUE;
        }

        $total = max(1, $ticket->created_at->diffInSeconds($ticket->first_response_due_at, true));
        $elapsed = max(0, $ticket->created_at->diffInSeconds($now, true));

        return $elapsed * 100 >= $total * $this->settings->integer('support_at_risk_percent') ? self::AT_RISK : null;
    }
}
