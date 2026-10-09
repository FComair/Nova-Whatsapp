<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use App\Modules\Support\Models\SupportTicket;

/**
 * États d'un ticket et règles de passage (docs/22 §8).
 */
final class TicketStatus
{
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_ON_YOU = 'waiting_on_you';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const ALL = [self::OPEN, self::IN_PROGRESS, self::WAITING_ON_YOU, self::RESOLVED, self::CLOSED];

    /** États dans lesquels le client peut fermer le ticket lui-même. */
    public const ACTIVE = [self::OPEN, self::IN_PROGRESS, self::WAITING_ON_YOU];

    /** États que l'équipe peut choisir en répondant. */
    public const STAFF_CHOICES = [self::IN_PROGRESS, self::WAITING_ON_YOU, self::RESOLVED];

    /** Jours pendant lesquels un ticket résolu peut être rouvert. */
    public const REOPEN_DAYS = 14;

    /**
     * État après une réponse du client : « En attente de vous » repasse « En cours » (l'équipe a déjà commencé), « Résolu » se
     * rouvre, les autres ne changent pas. Un ticket fermé n'accepte plus de réponse.
     */
    public static function afterClientReply(string $status): string
    {
        return match ($status) {
            self::WAITING_ON_YOU => self::IN_PROGRESS,
            self::RESOLVED => self::OPEN,
            default => $status,
        };
    }

    public static function canClientReply(SupportTicket $ticket): bool
    {
        return $ticket->status !== self::CLOSED;
    }

    public static function canClientClose(SupportTicket $ticket): bool
    {
        return in_array($ticket->status, self::ACTIVE, true);
    }

    /** « Cela a résolu mon problème » : seulement sur un ticket résolu. */
    public static function canConfirmSolved(SupportTicket $ticket): bool
    {
        return $ticket->status === self::RESOLVED;
    }

    public static function canReopen(SupportTicket $ticket): bool
    {
        return $ticket->status === self::RESOLVED && $ticket->resolved_at !== null && $ticket->resolved_at->greaterThan(now()->subDays(self::REOPEN_DAYS));
    }
}
