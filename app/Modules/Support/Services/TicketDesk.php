<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\SupportNotifier;
use App\Modules\Identity\Models\User;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketLink;
use App\Modules\Support\Models\SupportTicketTag;
use App\Modules\Support\Notifications\TicketClosedNotification;
use App\Modules\Support\Notifications\TicketCreatedNotification;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Modules\Support\Notifications\TicketResolvedNotification;
use App\Modules\Support\Notifications\TicketStatusNotification;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cycle de vie d'un ticket de support (docs/22 §8, docs/29) : création, réponses, fermeture, réouverture, et côté équipe réponse publique,
 * note interne, état, priorité, assignation, étiquettes, liens, demande d'aide interne. Tout changement laisse une ligne dans l'historique
 * (ajout seul) ; les lignes réservées à l'équipe sont invisibles pour le client **au niveau de la base**.
 *
 * Les méthodes de l'équipe reçoivent un ticket lu avec le rôle `nova_platform` (PlatformSupportTicket) : messages, événements et pièces
 * jointes suivent la connexion du ticket. Les méthodes du client reçoivent un ticket lu avec le rôle applicatif (RLS).
 *
 * Les e-mails sont envoyés après l'écriture et au mieux : un échec d'envoi n'annule jamais l'action.
 */
final class TicketDesk
{
    /** Équipes internes à qui l'on peut demander de l'aide (docs/29 §6). */
    public const TEAMS = ['billing', 'engineering', 'product', 'management', 'other'];

    public function __construct(
        private readonly AttachmentStore $attachments,
        private readonly SupportSla $sla,
        private readonly SupportNotifier $agents,
    ) {}

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

        $ticket = SupportTicket::query()->getConnection()->transaction(function () use ($user, $organization, $data, $files): SupportTicket {
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
                'status' => TicketStatus::NEW,
                'source' => 'contact_form',
                'last_message_at' => now(),
                'last_client_message_at' => now(),
                'first_response_due_at' => $this->sla->dueAt(now(), $data['urgency']),
                'client_read_at' => now(),
            ])->refresh();

            $this->addMessage($ticket, 'client', $user->id, trim($data['description']), $files);
            $this->event($ticket, 'client', $user->id, 'created', null, TicketStatus::NEW);

            return $ticket;
        });

        $this->send($ticket, new TicketCreatedNotification($ticket->reference(), $ticket->subject, $ticket->category, $ticket->urgency, $this->url($ticket)));
        $this->agents->notify('new_ticket', $ticket);

        return $ticket;
    }

    /**
     * Ouvre un ticket au nom d'un client (création manuelle par l'équipe, par exemple après un appel ou un e-mail). Le message du client
     * est saisi par l'équipe ; l'historique garde la trace de qui l'a créé.
     *
     * @param  array{org_id: string, user_id: string, email: string, locale: string, subject: string, category: string, priority: string, description: string, source?: string, assigned_admin_id?: string|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function createByStaff(string $adminId, array $data, array $files = []): PlatformSupportTicket
    {
        $assigned = $data['assigned_admin_id'] ?? null;
        $source = in_array($data['source'] ?? 'manual', SupportTicket::SOURCES, true) ? ($data['source'] ?? 'manual') : 'manual';

        $ticket = (new PlatformSupportTicket)->getConnection()->transaction(function () use ($adminId, $data, $files, $assigned, $source): PlatformSupportTicket {
            $ticket = PlatformSupportTicket::query()->create([
                'org_id' => $data['org_id'],
                'user_id' => $data['user_id'],
                'reply_email' => mb_strtolower(trim($data['email'])),
                'locale' => in_array($data['locale'], ['fr', 'nl', 'en'], true) ? $data['locale'] : 'fr',
                'subject' => trim($data['subject']),
                'category' => $data['category'],
                'urgency' => in_array($data['priority'], SupportTicket::URGENCIES, true) ? $data['priority'] : 'high',
                'staff_priority' => $data['priority'],
                'status' => TicketStatus::OPEN,
                'source' => $source,
                'assigned_admin_id' => $assigned,
                'last_message_at' => now(),
                'last_client_message_at' => now(),
                'first_response_due_at' => $this->sla->dueAt(now(), $data['priority']),
                'client_read_at' => now(),
            ])->refresh();

            $this->addMessage($ticket, 'client', $data['user_id'], trim($data['description']), $files);
            $this->event($ticket, 'staff', $adminId, 'created', null, TicketStatus::OPEN, 'manual', meta: ['source' => $source]);

            return $ticket;
        });

        $this->send($ticket, new TicketCreatedNotification($ticket->reference(), $ticket->subject, $ticket->category, $ticket->priority(), $this->url($ticket)));

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

        $message = $ticket->getConnection()->transaction(function () use ($ticket, $user, $body, $files): SupportMessage {
            $message = $this->addMessage($ticket, 'client', $user->id, trim($body), $files);
            $from = $ticket->status;
            $to = TicketStatus::afterClientReply($from);

            $ticket->forceFill([
                'status' => $to,
                'last_message_at' => now(),
                'last_client_message_at' => now(),
                'client_read_at' => $user->id === $ticket->user_id ? now() : $ticket->client_read_at,
                'resolved_at' => $from === TicketStatus::RESOLVED ? null : $ticket->resolved_at,
            ])->save();

            if ($from !== $to) {
                $this->event($ticket, 'client', $user->id, $from === TicketStatus::RESOLVED ? 'reopened' : 'status_changed', $from, $to, visibility: $this->visibilityFor($from, $to));
            }

            return $message;
        });

        $this->agents->notify('client_reply', $ticket);

        return $message;
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
        $this->agents->notify('ticket_done', $ticket, extra: ['state' => 'closed']);
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
        $this->agents->notify('ticket_done', $ticket, extra: ['state' => 'closed']);
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

        $ticket->getConnection()->transaction(function () use ($ticket, $user): void {
            $ticket->forceFill(['status' => TicketStatus::REOPENED, 'resolved_at' => null, 'last_message_at' => now()])->save();
            $this->event($ticket, 'client', $user->id, 'reopened', TicketStatus::RESOLVED, TicketStatus::REOPENED, visibility: 'public');
        });

        $this->agents->notify('client_reply', $ticket);
    }

    /** L'auteur a vu la conversation : la pastille « réponse non lue » disparaît. */
    public function markRead(SupportTicket $ticket): void
    {
        SupportTicket::query()->whereKey($ticket->id)->update(['client_read_at' => now()]);
        $ticket->client_read_at = now();
    }

    /**
     * Réponse publique de l'équipe. L'état par défaut passe « Nouveau », « Ouvert » ou « Rouvert » à « En cours » ; l'équipe peut aussi
     * choisir « En attente du client », « En attente de l'équipe interne » ou « Résolu » (e-mail correspondant au client).
     * La première réponse publique fixe la date de première réponse.
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

        $from = $ticket->status;
        $to = $status ?? (in_array($from, TicketStatus::UNTOUCHED, true) ? TicketStatus::IN_PROGRESS : $from);

        $message = $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $body, $files, $from, $to): SupportMessage {
            $message = $this->addMessage($ticket, 'staff', $adminId, trim($body), $files);

            $ticket->forceFill([
                'status' => $to,
                'last_message_at' => now(),
                'last_staff_message_at' => now(),
                'first_response_at' => $ticket->first_response_at ?? now(),
                'sla_alert' => null,
                'resolved_at' => $to === TicketStatus::RESOLVED ? now() : ($from === TicketStatus::RESOLVED ? null : $ticket->resolved_at),
            ])->save();

            $this->event($ticket, 'staff', $adminId, $from === $to ? 'staff_reply' : 'status_changed', $from, $to, visibility: $from === $to ? 'internal' : $this->visibilityFor($from, $to));

            return $message;
        });

        $this->send($ticket, $to === TicketStatus::RESOLVED
            ? new TicketResolvedNotification($ticket->reference(), $ticket->subject, $this->url($ticket), TicketStatus::REOPEN_DAYS)
            : new TicketReplyNotification($ticket->reference(), $ticket->subject, Str::limit(trim($body), 300), $this->url($ticket)));

        return $message;
    }

    /**
     * Note interne : jamais visible par le client, ne change ni l'état ni l'heure de dernière activité.
     *
     * @param  list<UploadedFile>  $files
     */
    public function staffNote(SupportTicket $ticket, ?string $adminId, string $body, array $files = []): SupportMessage
    {
        return $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $body, $files): SupportMessage {
            $message = $ticket->messages()->create([
                'org_id' => $ticket->org_id, 'author_type' => 'staff', 'author_id' => $adminId, 'visibility' => 'internal', 'body' => trim($body),
            ]);
            $this->storeFiles($ticket, $message, $files);
            $this->event($ticket, 'staff', $adminId, 'internal_note', null, null, visibility: 'internal');

            return $message;
        });
    }

    /**
     * Change l'état sans message. Le client est prévenu quand l'état qu'il voit change (résolu, en attente de lui, en cours, ouvert).
     *
     * @throws TicketRefused
     */
    public function staffSetStatus(SupportTicket $ticket, ?string $adminId, string $status): void
    {
        if ($ticket->status === TicketStatus::CLOSED || ! in_array($status, TicketStatus::STAFF_SETTABLE, true)) {
            throw TicketRefused::state('status');
        }

        $from = $ticket->status;

        if ($from === $status) {
            return;
        }

        $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $from, $status): void {
            $ticket->forceFill([
                'status' => $status,
                'resolved_at' => $status === TicketStatus::RESOLVED ? now() : null,
            ])->save();
            $this->event($ticket, 'staff', $adminId, 'status_changed', $from, $status, visibility: $this->visibilityFor($from, $status));
        });

        if ($status === TicketStatus::RESOLVED) {
            $this->send($ticket, new TicketResolvedNotification($ticket->reference(), $ticket->subject, $this->url($ticket), TicketStatus::REOPEN_DAYS));
        } elseif (TicketStatus::forClient($from) !== TicketStatus::forClient($status)) {
            $this->send($ticket, new TicketStatusNotification($ticket->reference(), $ticket->subject, TicketStatus::forClient($status), $this->url($ticket)));
        }
    }

    /**
     * Ferme un ticket (résolu ou non). Le client reçoit un e-mail : il ne peut plus répondre, il ouvre une nouvelle demande.
     *
     * @throws TicketRefused déjà fermé
     */
    public function staffClose(SupportTicket $ticket, ?string $adminId): void
    {
        if ($ticket->status === TicketStatus::CLOSED) {
            throw TicketRefused::state('close');
        }

        $this->closeTicket($ticket, $adminId, 'staff', actor: 'staff');
        $this->send($ticket, new TicketClosedNotification($ticket->reference(), $ticket->subject, $this->url($ticket)));
    }

    /**
     * Rouvre un ticket résolu ou fermé (par exemple le client a répondu par un autre canal, ou le problème est revenu).
     *
     * @throws TicketRefused ticket ni résolu ni fermé
     */
    public function staffReopen(SupportTicket $ticket, ?string $adminId): void
    {
        $from = $ticket->status;

        if (! in_array($from, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true)) {
            throw TicketRefused::state('reopen');
        }

        $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $from): void {
            $ticket->forceFill([
                'status' => TicketStatus::REOPENED, 'resolved_at' => null, 'closed_at' => null, 'closed_reason' => null,
                'last_message_at' => now(),
            ])->save();
            $this->event($ticket, 'staff', $adminId, 'reopened', $from, TicketStatus::REOPENED, visibility: 'public');
        });

        $this->send($ticket, new TicketStatusNotification($ticket->reference(), $ticket->subject, TicketStatus::forClient(TicketStatus::REOPENED), $this->url($ticket)));
    }

    /**
     * Assigne le ticket (null = le remettre dans la file sans agent). Un ticket « Nouveau » devient « Ouvert » dès qu'il a un agent (tri fait).
     *
     * @throws TicketRefused ticket fermé
     */
    public function staffAssign(SupportTicket $ticket, ?string $adminId, ?string $assigneeId): void
    {
        if ($ticket->status === TicketStatus::CLOSED) {
            throw TicketRefused::closed();
        }

        $from = $ticket->assigned_admin_id;

        if ($from === $assigneeId) {
            return;
        }

        $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $from, $assigneeId): void {
            $fromStatus = $ticket->status;
            $toStatus = $assigneeId !== null && $fromStatus === TicketStatus::NEW ? TicketStatus::OPEN : $fromStatus;

            $ticket->forceFill(['assigned_admin_id' => $assigneeId, 'status' => $toStatus])->save();
            $this->event($ticket, 'staff', $adminId, $assigneeId === null ? 'unassigned' : ($from === null ? 'assigned' : 'reassigned'), $fromStatus, $toStatus, visibility: 'internal', meta: ['from' => $from, 'to' => $assigneeId]);
        });

        if ($assigneeId !== null) {
            $this->agents->notify('assigned', $ticket, $adminId, $assigneeId);
        }
    }

    /**
     * Fixe la priorité de l'équipe. Tant qu'il n'y a pas eu de première réponse, l'échéance suit la nouvelle priorité.
     *
     * @throws TicketRefused priorité inconnue ou ticket fermé
     */
    public function staffSetPriority(SupportTicket $ticket, ?string $adminId, string $priority): void
    {
        if (! in_array($priority, SupportTicket::PRIORITIES, true) || $ticket->status === TicketStatus::CLOSED) {
            throw TicketRefused::state('priority');
        }

        $from = $ticket->priority();

        if ($from === $priority && $ticket->staff_priority === $priority) {
            return;
        }

        $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $from, $priority): void {
            $attributes = ['staff_priority' => $priority];

            if ($ticket->first_response_at === null) {
                $attributes['first_response_due_at'] = $this->sla->dueAt($ticket->created_at, $priority);
                $attributes['sla_alert'] = null;
            }

            $ticket->forceFill($attributes)->save();
            $this->event($ticket, 'staff', $adminId, 'priority_changed', null, null, visibility: 'internal', meta: ['from' => $from, 'to' => $priority]);
        });

        if ($from !== $priority) {
            $this->agents->notify('priority_changed', $ticket, $adminId, extra: ['priority' => __('tickets.priorities.'.$priority)]);
        }
    }

    /**
     * Ajoute une étiquette interne (30 caractères, minuscules, sans espace en bordure). Sans effet si elle existe déjà.
     *
     * @throws TicketRefused étiquette vide
     */
    public function staffAddTag(SupportTicket $ticket, ?string $adminId, string $tag): void
    {
        $clean = self::cleanTag($tag);

        if ($clean === '') {
            throw TicketRefused::state('tag');
        }

        $created = SupportTicketTag::query()->firstOrCreate(['ticket_id' => $ticket->id, 'tag' => $clean], ['org_id' => $ticket->org_id, 'created_by' => $adminId]);

        if ($created->wasRecentlyCreated) {
            $this->event($ticket, 'staff', $adminId, 'tag_added', null, null, $clean, 'internal');
        }
    }

    public function staffRemoveTag(SupportTicket $ticket, ?string $adminId, string $tag): void
    {
        $clean = self::cleanTag($tag);

        if (SupportTicketTag::query()->where('ticket_id', $ticket->id)->where('tag', $clean)->delete() > 0) {
            $this->event($ticket, 'staff', $adminId, 'tag_removed', null, null, $clean, 'internal');
        }
    }

    /**
     * Relie le ticket à un autre ticket ou à un enregistrement (rendez-vous, client, service, conversation WhatsApp).
     *
     * @throws TicketRefused type inconnu, libellé vide ou ticket relié à lui-même
     */
    public function staffLink(SupportTicket $ticket, ?string $adminId, string $kind, string $label, ?string $refId = null): SupportTicketLink
    {
        $label = trim($label);

        if (! in_array($kind, SupportTicketLink::KINDS, true) || $label === '' || ($kind === 'ticket' && $refId === $ticket->id)) {
            throw TicketRefused::state('link');
        }

        $link = SupportTicketLink::query()->firstOrCreate(
            ['ticket_id' => $ticket->id, 'kind' => $kind, 'label' => mb_substr($label, 0, 120)],
            ['org_id' => $ticket->org_id, 'ref_id' => $refId, 'created_by' => $adminId],
        );

        if ($link->wasRecentlyCreated) {
            $this->event($ticket, 'staff', $adminId, 'link_added', null, null, mb_substr($label, 0, 200), 'internal', ['kind' => $kind]);
        }

        return $link;
    }

    public function staffUnlink(SupportTicket $ticket, ?string $adminId, string $linkId): void
    {
        $link = SupportTicketLink::query()->where('ticket_id', $ticket->id)->whereKey($linkId)->first();

        if ($link !== null) {
            $link->delete();
            $this->event($ticket, 'staff', $adminId, 'link_removed', null, null, mb_substr($link->label, 0, 200), 'internal', ['kind' => $link->kind]);
        }
    }

    /**
     * Demande l'aide d'une autre équipe interne : le ticket passe « En attente de l'équipe interne » (le client voit « En cours ») et la
     * demande est consignée dans une note interne. Rien n'est envoyé au client.
     *
     * @throws TicketRefused équipe inconnue ou ticket fermé
     */
    public function staffRequestHelp(SupportTicket $ticket, ?string $adminId, string $team, string $message): SupportMessage
    {
        if (! in_array($team, self::TEAMS, true) || $ticket->status === TicketStatus::CLOSED || trim($message) === '') {
            throw TicketRefused::state('help');
        }

        $note = $ticket->getConnection()->transaction(function () use ($ticket, $adminId, $team, $message): SupportMessage {
            $from = $ticket->status;
            $note = $ticket->messages()->create([
                'org_id' => $ticket->org_id, 'author_type' => 'staff', 'author_id' => $adminId, 'visibility' => 'internal',
                'body' => __('tickets.help.note', ['team' => __("tickets.teams.{$team}")], config('app.locale')).' '.trim($message),
            ]);
            $ticket->forceFill(['status' => TicketStatus::WAITING_INTERNAL, 'resolved_at' => null])->save();
            $this->event($ticket, 'staff', $adminId, 'help_requested', $from, TicketStatus::WAITING_INTERNAL, $team, 'internal', ['team' => $team]);

            return $note;
        });

        return $note;
    }

    /** Fermeture (par le client, par l'équipe, ou par l'automatisme avec une autre raison). */
    public function closeTicket(SupportTicket $ticket, ?string $actorId, string $reason, string $actor = 'auto'): void
    {
        $from = $ticket->status;
        $actorType = $actor === 'staff' ? 'staff' : ($reason === 'client' ? 'client' : 'system');

        $ticket->getConnection()->transaction(function () use ($ticket, $actorId, $reason, $from, $actorType): void {
            $ticket->forceFill(['status' => TicketStatus::CLOSED, 'closed_at' => now(), 'closed_reason' => $reason])->save();
            $this->event($ticket, $actorType, $actorType === 'system' ? null : $actorId, 'closed', $from, TicketStatus::CLOSED, $reason, 'public');
        });
    }

    public static function cleanTag(string $tag): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', '-', mb_strtolower(trim($tag)))), 0, 30);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(SupportTicket $ticket, string $authorType, ?string $authorId, string $body, array $files): SupportMessage
    {
        $message = $ticket->messages()->create([
            'org_id' => $ticket->org_id, 'author_type' => $authorType, 'author_id' => $authorId, 'visibility' => 'public', 'body' => $body,
        ]);
        $this->storeFiles($ticket, $message, $files);

        return $message;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeFiles(SupportTicket $ticket, SupportMessage $message, array $files): void
    {
        $stored = [];

        try {
            foreach ($files as $file) {
                $info = $this->attachments->store($file, $ticket->org_id, $ticket->id);
                $stored[] = $info['path'];
                $message->attachments()->create(['org_id' => $ticket->org_id, ...$info]);
            }
        } catch (Throwable $e) {
            // Rien ne reste sur le disque d'un message qui n'aboutit pas.
            Storage::disk(AttachmentStore::DISK)->delete($stored);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function event(SupportTicket $ticket, string $actorType, ?string $actorId, string $action, ?string $from, ?string $to, ?string $note = null, string $visibility = 'public', ?array $meta = null): void
    {
        $ticket->events()->create([
            'org_id' => $ticket->org_id, 'actor_type' => $actorType, 'actor_id' => $actorId, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'visibility' => $visibility, 'meta' => $meta,
        ]);
    }

    /** Un changement d'état n'est visible du client que si l'état qu'il voit change (« Nouveau » → « Ouvert » ne change rien pour lui). */
    private function visibilityFor(?string $from, ?string $to): string
    {
        return $from !== null && $to !== null && TicketStatus::forClient($from) === TicketStatus::forClient($to) ? 'internal' : 'public';
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
