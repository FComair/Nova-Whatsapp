<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

use App\Modules\Support\Models\SupportTicket;

/**
 * États d'un ticket et règles de passage (docs/22 §8, docs/29 §2).
 *
 * Huit états côté équipe ; le client n'en voit que cinq (`forClient`) : « Nouveau » et « Rouvert » s'affichent « Ouvert »,
 * « En attente de l'équipe interne » s'affiche « En cours ».
 */
final class TicketStatus
{
    /** Créé, pas encore pris en charge (ni assigné, ni répondu). */
    public const NEW = 'new';

    /** Trié ou assigné, pas encore en traitement actif. */
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    /** Rendivoo attend une information ou une action du client (code historique : « waiting_on_you »). */
    public const WAITING_ON_YOU = 'waiting_on_you';

    /** Rendivoo attend une autre équipe interne (facturation, technique…). Invisible pour le client. */
    public const WAITING_INTERNAL = 'waiting_internal';

    /** L'équipe considère le problème résolu ; le ticket n'est pas encore fermé et peut être rouvert. */
    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    /** Rouvert par le client ou par l'équipe après une résolution ou une fermeture. */
    public const REOPENED = 'reopened';

    public const ALL = [self::NEW, self::OPEN, self::IN_PROGRESS, self::WAITING_ON_YOU, self::WAITING_INTERNAL, self::RESOLVED, self::CLOSED, self::REOPENED];

    /** États dans lesquels le client peut fermer le ticket lui-même. */
    public const ACTIVE = [self::NEW, self::OPEN, self::IN_PROGRESS, self::WAITING_ON_YOU, self::WAITING_INTERNAL, self::REOPENED];

    /** Pas encore de réponse de l'équipe : le délai de première réponse court. */
    public const UNTOUCHED = [self::NEW, self::OPEN, self::REOPENED];

    /** États que l'équipe peut choisir en répondant. */
    public const STAFF_CHOICES = [self::IN_PROGRESS, self::WAITING_ON_YOU, self::WAITING_INTERNAL, self::RESOLVED];

    /** États que l'équipe peut fixer sans message (la fermeture et la réouverture ont leurs propres actions). */
    public const STAFF_SETTABLE = [self::OPEN, self::IN_PROGRESS, self::WAITING_ON_YOU, self::WAITING_INTERNAL, self::RESOLVED];

    /** États côté client. */
    public const CLIENT = ['open', 'in_progress', 'waiting_on_you', 'resolved', 'closed'];

    /** Jours pendant lesquels un ticket résolu peut être rouvert. */
    public const REOPEN_DAYS = 14;

    /** L'état que voit le client. */
    public static function forClient(string $status): string
    {
        return match ($status) {
            self::NEW, self::REOPENED => self::OPEN,
            self::WAITING_INTERNAL => self::IN_PROGRESS,
            default => $status,
        };
    }

    /**
     * Les états de l'équipe qui s'affichent sous l'état client donné (filtres de la liste du client).
     *
     * @return list<string>
     */
    public static function staffStatesFor(string $clientStatus): array
    {
        return array_values(array_filter(self::ALL, fn (string $status): bool => self::forClient($status) === $clientStatus));
    }

    /**
     * État après une réponse du client : « En attente du client » repasse « En cours » (l'équipe a déjà commencé), « Résolu » se
     * rouvre, les autres ne changent pas. Un ticket fermé n'accepte plus de réponse du client.
     */
    public static function afterClientReply(string $status): string
    {
        return match ($status) {
            self::WAITING_ON_YOU => self::IN_PROGRESS,
            self::RESOLVED => self::REOPENED,
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

    /** Un ticket ouvert à traiter par l'équipe (ni résolu, ni fermé). */
    public static function isOpenWork(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
