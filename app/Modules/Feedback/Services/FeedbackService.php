<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Services;

use App\Modules\Feedback\Models\NovaFeedback;
use App\Modules\Feedback\Models\ReviewLinkClick;
use App\Modules\Identity\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Avis privés des clients sur Nova et invitations après un échange avec le support (docs/26).
 *
 * - Un avis accepte toutes les notes de 1 à 5 : aucune note n'ouvre ou ne ferme quoi que ce soit.
 * - Rattaché à un ticket, il vaut « ticket » (résolu ou fermé) ou « intervention » (l'équipe a répondu, le ticket est en cours) ;
 *   une fois par personne et par ticket. Sans ticket : « général », à tout moment.
 * - L'invitation (sur la page du ticket) ne se montre qu'à l'auteur du ticket, au plus une fois par moment (réponse de l'équipe ;
 *   ticket résolu ou fermé), disparaît si l'avis est donné ou si la personne choisit « Pas maintenant », et n'est jamais exigée.
 */
final class FeedbackService
{
    public const COMMENT_MAX = 2000;

    /**
     * @throws UniqueConstraintViolationException si cette personne a déjà donné son avis sur ce ticket
     */
    public function submit(User $user, Organization $organization, int $rating, ?string $comment, ?SupportTicket $ticket): NovaFeedback
    {
        $comment = $comment === null ? null : trim($comment);

        return NovaFeedback::query()->create([
            'org_id' => $organization->id,
            'user_id' => $user->id,
            'ticket_id' => $ticket?->id,
            'source' => $this->source($ticket),
            'rating' => $rating,
            'comment' => $comment === '' ? null : $comment,
            'locale' => substr(app()->getLocale(), 0, 2),
        ]);
    }

    public function source(?SupportTicket $ticket): string
    {
        return match (true) {
            $ticket === null => 'general',
            in_array($ticket->status, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true) => 'ticket',
            default => 'intervention',
        };
    }

    /** Clé d'invitation : un moment précis d'un ticket (« ticket:… » une fois résolu ou fermé, « intervention:… » après une réponse). */
    public function promptKey(SupportTicket $ticket): ?string
    {
        if (in_array($ticket->status, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true)) {
            return "ticket:{$ticket->id}";
        }

        return $ticket->last_staff_message_at !== null ? "intervention:{$ticket->id}" : null;
    }

    /**
     * Invitation à donner son avis sur ce ticket, ou null. Seul l'auteur du ticket la voit ; rien n'est proposé s'il a déjà donné
     * son avis sur ce ticket ou refusé l'invitation de ce moment.
     *
     * @return array{key: string, context: string}|null
     */
    public function promptFor(User $user, SupportTicket $ticket): ?array
    {
        $key = $ticket->user_id === $user->id ? $this->promptKey($ticket) : null;

        if ($key === null
            || NovaFeedback::query()->where('user_id', $user->id)->where('ticket_id', $ticket->id)->exists()
            || DB::table('feedback_prompt_dismissals')->where('user_id', $user->id)->where('prompt_key', $key)->exists()) {
            return null;
        }

        return ['key' => $key, 'context' => $this->source($ticket)];
    }

    public function hasFeedbackFor(User $user, SupportTicket $ticket): bool
    {
        return NovaFeedback::query()->where('user_id', $user->id)->where('ticket_id', $ticket->id)->exists();
    }

    /** « Pas maintenant » : l'invitation de ce moment ne revient pas (l'avis reste possible à tout moment depuis l'aide). */
    public function dismiss(User $user, Organization $organization, string $key): void
    {
        DB::table('feedback_prompt_dismissals')->insertOrIgnore(['org_id' => $organization->id, 'user_id' => $user->id, 'prompt_key' => $key]);
    }

    /** Le client ouvre un lien d'avis public : on note la plateforme, rien d'autre. L'avis privé indiqué doit être le sien. */
    public function recordClick(User $user, Organization $organization, string $platform, ?string $feedbackId): void
    {
        $own = $feedbackId !== null && NovaFeedback::query()->whereKey($feedbackId)->where('user_id', $user->id)->exists();

        ReviewLinkClick::query()->create(['org_id' => $organization->id, 'user_id' => $user->id, 'feedback_id' => $own ? $feedbackId : null, 'platform' => $platform]);
    }
}
