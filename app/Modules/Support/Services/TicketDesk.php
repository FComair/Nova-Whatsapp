<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketEvent;
use App\Modules\Support\Notifications\TicketCreatedNotification;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Modules\Support\Notifications\TicketResolvedNotification;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cycle de vie d'un ticket de support (docs/22 §8) : création, réponses, fermeture, réouverture, et côté équipe réponse publique,
 * note interne et changement d'état. Tout changement d'état laisse une ligne dans l'historique (ajout seul).
 *
 * Les e-mails sont envoyés après l'écriture et au mieux : un échec d'envoi n'annule jamais l'action.
 */
final class TicketDesk
{
    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * Crée un ticket et son premier message. Le même jeton de formulaire ne crée jamais deux tickets (rechargement de page).
     *
     * @param  array{subject: string, category: string, related_to?: string|null, urgency: string, description: string, email: string, token: string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(User $user, Organization $organization, array $data, array $files = []): SupportTicket
    {
        $existing = SupportTicket::query()->where('user_id', $user->id)->where('submission_token', $data['token'])->first();

        if ($existing !== null) {
            return $existing;
        }

        $ticket = DB::transaction(function () use ($user, $organization, $data, $files): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'org_id' => $organization->id,
                'user_id' => $user->id,
                'submission_token' => $data['token'],
                'reply_email' => mb_strtolower(trim($data['email'])),
                'locale' => in_array($user->locale, ['fr', 'nl', 'en'], true) ? $user->locale : 'fr',
                'subject' => trim($data['subject']),
                'category' => $data['category'],
                'related_to' => $data['related_to'] ?? null,
                'urgency' => $data['urgency'],
                'status' => TicketStatus::OPEN,
                'last_message_at' => now(),
                'client_read_at' => now(),
            ])->refresh();

            $this->addMessage($ticket, 'client', $user->id, trim($data['description']), $files);
            $this->event($ticket, 'client', $user->id, 'created', null, TicketStatus::OPEN);

            return $ticket;
        });

        $this->send($ticket, new TicketCreatedNotification($ticket->reference(), $ticket->subject, $ticket->category, $ticket->urgency, $this->url($ticket)));

        return $ticket;
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketRefused ticket fermé
     */
    public function clientReply(SupportTicket $ticket, User $user, string $body, array $files = []): SupportMessage
    {
        if (! TicketStatus::canClientReply($ticket)) {
            throw TicketRefused::closed();
        }

        return DB::transaction(function () use ($ticket, $user, $body, $files): SupportMessage {
            $message = $this->addMessage($ticket, 'client', $user->id, trim($body), $files);
            $from = $ticket->status;
            $to = TicketStatus::afterClientReply($from);

            $ticket->forceFill([
                'status' => $to,
                'last_message_at' => now(),
                'client_read_at' => $user->id === $ticket->user_id ? now() : $ticket->client_read_at,
                'resolved_at' => $from === TicketStatus::RESOLVED ? null : $ticket->resolved_at,
            ])->save();

            if ($from !== $to) {
                $this->event($ticket, 'client', $user->id, $from === TicketStatus::RESOLVED ? 'reopened' : 'status_changed', $from, $to);
            }

            return $message;
        });
    }

    /**
     * Le client ferme lui-même un ticket ouvert, en cours ou en attente de sa réponse.
     *
     * @throws TicketRefused
     */
    public function close(SupportTicket $ticket, User $user): void
    {
        if (! TicketStatus::canClientClose($ticket)) {
            throw TicketRefused::state('close');
        }

        $this->closeTicket($ticket, $user->id, 'client');
    }

    /**
     * « Cela a résolu mon problème » : ferme un ticket résolu.
     *
     * @throws TicketRefused
     */
    public function confirmSolved(SupportTicket $ticket, User $user): void
    {
        if (! TicketStatus::canConfirmSolved($ticket)) {
            throw TicketRefused::state('solved');
        }

        $this->closeTicket($ticket, $user->id, 'client');
    }

    /**
     * Rouvre un ticket résolu depuis moins de 14 jours.
     *
     * @throws TicketRefused
     */
    public function reopen(SupportTicket $ticket, User $user): void
    {
        if (! TicketStatus::canReopen($ticket)) {
            throw TicketRefused::state('reopen');
        }

        DB::transaction(function () use ($ticket, $user): void {
            $ticket->forceFill(['status' => TicketStatus::OPEN, 'resolved_at' => null, 'last_message_at' => now()])->save();
            $this->event($ticket, 'client', $user->id, 'reopened', TicketStatus::RESOLVED, TicketStatus::OPEN);
        });
    }

    /** L'auteur a vu la conversation : la pastille « réponse non lue » disparaît. */
    public function markRead(SupportTicket $ticket): void
    {
        SupportTicket::query()->whereKey($ticket->id)->update(['client_read_at' => now()]);
        $ticket->client_read_at = now();
    }

    /**
     * Réponse publique de l'équipe. L'état par défaut passe « Ouvert » à « En cours » ; l'équipe peut aussi choisir « En attente
     * de vous » ou « Résolu » (e-mail correspondant au client).
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketRefused ticket fermé, ou état demandé non permis
     */
    public function staffReply(SupportTicket $ticket, ?string $adminId, string $body, ?string $status = null, array $files = []): SupportMessage
    {
        if ($ticket->status === TicketStatus::CLOSED) {
            throw TicketRefused::closed();
        }

        if ($status !== null && ! in_array($status, TicketStatus::STAFF_CHOICES, true)) {
            throw TicketRefused::state('status');
        }

        $to = $status ?? ($ticket->status === TicketStatus::OPEN ? TicketStatus::IN_PROGRESS : $ticket->status);
        $from = $ticket->status;

        $message = DB::transaction(function () use ($ticket, $adminId, $body, $files, $from, $to): SupportMessage {
            $message = $this->addMessage($ticket, 'staff', $adminId, trim($body), $files);

            $ticket->forceFill([
                'status' => $to,
                'last_message_at' => now(),
                'last_staff_message_at' => now(),
                'resolved_at' => $to === TicketStatus::RESOLVED ? now() : ($from === TicketStatus::RESOLVED ? null : $ticket->resolved_at),
            ])->save();

            $this->event($ticket, 'staff', $adminId, $from === $to ? 'staff_reply' : 'status_changed', $from, $to);

            return $message;
        });

        $this->send($ticket, $to === TicketStatus::RESOLVED
            ? new TicketResolvedNotification($ticket->reference(), $ticket->subject, $this->url($ticket), TicketStatus::REOPEN_DAYS)
            : new TicketReplyNotification($ticket->reference(), $ticket->subject, Str::limit(trim($body), 300), $this->url($ticket)));

        return $message;
    }

    /** Note interne : jamais visible par le client, ne change ni l'état ni l'heure de dernière activité. */
    public function staffNote(SupportTicket $ticket, ?string $adminId, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $adminId, $body): SupportMessage {
            $message = SupportMessage::query()->create([
                'org_id' => $ticket->org_id, 'ticket_id' => $ticket->id, 'author_type' => 'staff', 'author_id' => $adminId,
                'visibility' => 'internal', 'body' => trim($body),
            ]);
            $this->event($ticket, 'staff', $adminId, 'internal_note', null, null);

            return $message;
        });
    }

    /**
     * Change l'état sans message (par exemple « En cours » dès que l'équipe prend le ticket).
     *
     * @throws TicketRefused
     */
    public function staffSetStatus(SupportTicket $ticket, ?string $adminId, string $status): void
    {
        if ($ticket->status === TicketStatus::CLOSED || ! in_array($status, [...TicketStatus::STAFF_CHOICES, TicketStatus::OPEN], true)) {
            throw TicketRefused::state('status');
        }

        $from = $ticket->status;

        if ($from === $status) {
            return;
        }

        DB::transaction(function () use ($ticket, $adminId, $from, $status): void {
            $ticket->forceFill([
                'status' => $status,
                'resolved_at' => $status === TicketStatus::RESOLVED ? now() : null,
            ])->save();
            $this->event($ticket, 'staff', $adminId, 'status_changed', $from, $status);
        });

        if ($status === TicketStatus::RESOLVED) {
            $this->send($ticket, new TicketResolvedNotification($ticket->reference(), $ticket->subject, $this->url($ticket), TicketStatus::REOPEN_DAYS));
        }
    }

    /** Fermeture (par le client, ou par l'automatisme avec une autre raison). */
    public function closeTicket(SupportTicket $ticket, ?string $actorId, string $reason): void
    {
        $from = $ticket->status;

        DB::transaction(function () use ($ticket, $actorId, $reason, $from): void {
            $ticket->forceFill(['status' => TicketStatus::CLOSED, 'closed_at' => now(), 'closed_reason' => $reason])->save();
            $this->event($ticket, $reason === 'client' ? 'client' : 'system', $reason === 'client' ? $actorId : null, 'closed', $from, TicketStatus::CLOSED, $reason);
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(SupportTicket $ticket, string $authorType, ?string $authorId, string $body, array $files): SupportMessage
    {
        $message = SupportMessage::query()->create([
            'org_id' => $ticket->org_id, 'ticket_id' => $ticket->id, 'author_type' => $authorType, 'author_id' => $authorId, 'visibility' => 'public', 'body' => $body,
        ]);
        $stored = [];

        try {
            foreach ($files as $file) {
                $info = $this->attachments->store($file, $ticket->org_id, $ticket->id);
                $stored[] = $info['path'];
                SupportAttachment::query()->create(['org_id' => $ticket->org_id, 'message_id' => $message->id, ...$info]);
            }
        } catch (Throwable $e) {
            // Rien ne reste sur le disque d'un message qui n'aboutit pas.
            Storage::disk(AttachmentStore::DISK)->delete($stored);

            throw $e;
        }

        return $message;
    }

    private function event(SupportTicket $ticket, string $actorType, ?string $actorId, string $action, ?string $from, ?string $to, ?string $note = null): void
    {
        SupportTicketEvent::query()->create([
            'org_id' => $ticket->org_id, 'ticket_id' => $ticket->id, 'actor_type' => $actorType, 'actor_id' => $actorId,
            'action' => $action, 'from_status' => $from, 'to_status' => $to, 'note' => $note,
        ]);
    }

    private function url(SupportTicket $ticket): string
    {
        return url('/aide/tickets/'.$ticket->reference());
    }

    /** Envoi au mieux, dans la langue du ticket. */
    private function send(SupportTicket $ticket, BaseNotification $notification): void
    {
        try {
            Notification::route('mail', $ticket->reply_email)->notify($notification->locale($ticket->locale));
        } catch (Throwable $e) {
            Log::warning('Support : e-mail non envoyé.', ['ticket' => $ticket->reference(), 'error' => $e::class]);
        }
    }
}
