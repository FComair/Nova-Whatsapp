<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportPermissions;
use App\Modules\Support\Exceptions\TicketChanged;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\TicketDesk;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Point d'entrée unique du Super Admin pour agir sur les tickets (docs/29) : vérifie le droit de voir le ticket, détecte une modification
 * simultanée, délègue à `TicketDesk` (qui écrit avec le rôle `nova_platform`) et consigne CHAQUE action dans le journal d'audit de la
 * plateforme (consultation, réponse, note, état, priorité, assignation, étiquettes, liens, export).
 *
 * Les écrans (liste, détail, rapports) n'écrivent jamais autrement : les règles sont les mêmes pour une action isolée et en lot.
 */
final class SupportConsole
{
    public function __construct(private readonly TicketDesk $desk) {}

    /**
     * Charge un ticket que l'agent a le droit de voir.
     *
     * @throws AuthorizationException ticket introuvable ou hors de la portée de l'agent
     */
    public function find(PlatformAdmin $admin, string $ticketId): PlatformSupportTicket
    {
        $ticket = SupportAccess::scope(PlatformSupportTicket::query(), $admin)->whereKey($ticketId)->first();

        return $ticket ?? throw new AuthorizationException;
    }

    /** Ouvre un ticket à l'écran : la consultation est tracée. */
    public function open(PlatformAdmin $admin, string $ticketId): PlatformSupportTicket
    {
        $ticket = $this->find($admin, $ticketId);
        $this->audit($admin, 'support.ticket.viewed', $ticket);

        return $ticket;
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketRefused|TicketChanged
     */
    public function reply(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $body, ?string $status = null, array $files = [], ?int $version = null): SupportMessage
    {
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->status;
        $message = $this->desk->staffReply($ticket, $admin->id, $body, $status, $files);

        $this->audit($admin, 'support.ticket.replied', $ticket, ['status' => [$from, $ticket->status], 'attachments' => count($files)]);

        return $message;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function note(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $body, array $files = []): SupportMessage
    {
        $ticket = $this->find($admin, $ticket->id);
        $message = $this->desk->staffNote($ticket, $admin->id, $body, $files);
        $this->audit($admin, 'support.ticket.note_added', $ticket, ['attachments' => count($files)]);

        return $message;
    }

    /**
     * @throws TicketRefused|TicketChanged
     */
    public function setStatus(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $status, ?int $version = null): void
    {
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->status;
        $this->desk->staffSetStatus($ticket, $admin->id, $status);
        $this->audit($admin, 'support.ticket.status_changed', $ticket, ['status' => [$from, $ticket->status]]);
    }

    /**
     * @throws TicketRefused|TicketChanged
     */
    public function setPriority(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $priority, ?int $version = null): void
    {
        $this->authorize($admin, SupportPermissions::CHANGE_PRIORITY);
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->priority();
        $this->desk->staffSetPriority($ticket, $admin->id, $priority);
        $this->audit($admin, 'support.ticket.priority_changed', $ticket, ['priority' => [$from, $priority]]);
    }

    /**
     * @throws TicketRefused|TicketChanged|AuthorizationException
     */
    public function assign(PlatformAdmin $admin, PlatformSupportTicket $ticket, ?string $assigneeId, ?int $version = null): void
    {
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->assigned_admin_id;

        // Un compte inactif (invité, suspendu, retiré), un éditeur ou un agent hors périmètre ne reçoit jamais de ticket.
        if ($assigneeId !== null && ! array_key_exists($assigneeId, SupportAccess::assignees($ticket->org_id))) {
            throw new AuthorizationException;
        }

        // Sans la permission « assigner à d'autres », un agent prend un ticket pour lui ou remet le sien dans la file.
        if (! SupportPermissions::can($admin, SupportPermissions::ASSIGN_OTHERS) && ! ($assigneeId === $admin->id || ($assigneeId === null && $from === $admin->id))) {
            throw new AuthorizationException;
        }

        $this->desk->staffAssign($ticket, $admin->id, $assigneeId);
        $this->audit($admin, 'support.ticket.assigned', $ticket, ['assigned_to' => [$from, $assigneeId]]);
    }

    public function addTag(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $tag): void
    {
        $this->authorize($admin, SupportPermissions::MANAGE_TAGS);
        $ticket = $this->find($admin, $ticket->id);
        $this->desk->staffAddTag($ticket, $admin->id, $tag);
        $this->audit($admin, 'support.ticket.tag_added', $ticket, ['tag' => TicketDesk::cleanTag($tag)]);
    }

    public function removeTag(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $tag): void
    {
        $this->authorize($admin, SupportPermissions::MANAGE_TAGS);
        $ticket = $this->find($admin, $ticket->id);
        $this->desk->staffRemoveTag($ticket, $admin->id, $tag);
        $this->audit($admin, 'support.ticket.tag_removed', $ticket, ['tag' => TicketDesk::cleanTag($tag)]);
    }

    /**
     * @throws TicketRefused
     */
    public function link(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $kind, string $label, ?string $refId = null): void
    {
        $ticket = $this->find($admin, $ticket->id);
        $this->desk->staffLink($ticket, $admin->id, $kind, $label, $refId);
        $this->audit($admin, 'support.ticket.link_added', $ticket, ['kind' => $kind, 'label' => $label]);
    }

    public function unlink(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $linkId): void
    {
        $ticket = $this->find($admin, $ticket->id);
        $this->desk->staffUnlink($ticket, $admin->id, $linkId);
        $this->audit($admin, 'support.ticket.link_removed', $ticket, ['link' => $linkId]);
    }

    /**
     * @throws TicketRefused|TicketChanged
     */
    public function requestHelp(PlatformAdmin $admin, PlatformSupportTicket $ticket, string $team, string $message, ?int $version = null): void
    {
        $ticket = $this->fresh($admin, $ticket, $version);
        $this->desk->staffRequestHelp($ticket, $admin->id, $team, $message);
        $this->audit($admin, 'support.ticket.help_requested', $ticket, ['team' => $team]);
    }

    /**
     * @throws TicketRefused|TicketChanged
     */
    public function close(PlatformAdmin $admin, PlatformSupportTicket $ticket, ?int $version = null): void
    {
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->status;
        $this->desk->staffClose($ticket, $admin->id);
        $this->audit($admin, 'support.ticket.closed', $ticket, ['status' => [$from, TicketStatus::CLOSED]]);
    }

    /**
     * @throws TicketRefused|TicketChanged
     */
    public function reopen(PlatformAdmin $admin, PlatformSupportTicket $ticket, ?int $version = null): void
    {
        $this->authorize($admin, SupportPermissions::REOPEN_TICKETS);
        $ticket = $this->fresh($admin, $ticket, $version);
        $from = $ticket->status;
        $this->desk->staffReopen($ticket, $admin->id);
        $this->audit($admin, 'support.ticket.reopened', $ticket, ['status' => [$from, TicketStatus::REOPENED]]);
    }

    /**
     * Supprime DÉFINITIVEMENT un ticket (messages, notes internes, pièces jointes, événements, étiquettes, liens) : docs/32 §1. Seul le
     * journal d'audit survit, avec qui, quand, la référence, l'objet, le client, ce qui a été supprimé et le motif (facultatif).
     * Refusé sans la permission de suppression ou hors de la portée de l'agent.
     *
     * @throws AuthorizationException
     */
    public function delete(PlatformAdmin $admin, string $ticketId, ?string $reason = null): void
    {
        $this->authorize($admin, SupportPermissions::DELETE_TICKETS);
        $ticket = $this->find($admin, $ticketId);
        $db = DB::connection(PlatformAudit::CONNECTION);

        /** @var array<string, mixed>|null $gone */
        $gone = $db->transaction(function () use ($db, $admin, $ticket, $reason): ?array {
            $json = $db->selectOne('select support_delete_ticket(?) as result', [$ticket->id])->result ?? null;
            $gone = $json === null ? null : (array) json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

            if ($gone === null) {
                return null;
            }

            PlatformAudit::record($admin->id, 'support.ticket.deleted', $ticket->org_id, 'support_ticket', $ticket->id, [
                'reference' => $ticket->reference(), 'subject' => $ticket->subject, 'status' => $gone['status'], 'assigned_to' => $gone['assigned_admin_id'],
                'messages' => $gone['messages'], 'internal_notes' => $gone['internal_notes'], 'attachments' => $gone['attachments'], 'events' => $gone['events'],
            ], $reason);

            // Les notifications de la cloche qui pointaient vers ce ticket n'ont plus de cible.
            $db->table('platform_notifications')->whereRaw('data::text like ?', ['%'.$ticket->id.'%'])->delete();

            return $gone;
        });

        if ($gone === null) {
            throw new AuthorizationException;
        }

        // Les fichiers partent après la validation en base : un échec laisse un fichier orphelin, jamais une pièce jointe sans ticket.
        foreach ((array) $gone['paths'] as $path) {
            try {
                Storage::disk(AttachmentStore::DISK)->delete((string) $path);
            } catch (Throwable $e) {
                Log::warning('Suppression de ticket : fichier non supprimé.', ['ticket' => $ticket->id, 'path' => (string) $path, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Création manuelle au nom d'un client. Le demandeur doit être un membre actif du compte choisi.
     *
     * @param  array{org_id: string, user_id: string, subject: string, category: string, priority: string, description: string, source?: string, assigned_admin_id?: string|null}  $data
     * @param  list<UploadedFile>  $files
     *
     * @throws AuthorizationException demandeur étranger au compte
     */
    public function create(PlatformAdmin $admin, array $data, array $files = []): PlatformSupportTicket
    {
        $member = collect($this->members($data['org_id']))->firstWhere('user_id', $data['user_id']);

        if ($member === null) {
            throw new AuthorizationException;
        }

        $ticket = $this->desk->createByStaff($admin->id, [...$data, 'email' => (string) $member->email, 'locale' => (string) $member->locale], $files);
        $this->audit($admin, 'support.ticket.created', $ticket, ['source' => $ticket->source, 'priority' => $ticket->priority()]);

        return $ticket;
    }

    /**
     * Membres actifs d'un compte (le Super Admin ne lit pas la table des utilisateurs : fonction étroite de la base).
     *
     * @return list<object{user_id: string, email: string, locale: string, role: string}>
     */
    public function members(string $orgId): array
    {
        return DB::connection(PlatformAudit::CONNECTION)->select('select * from org_member_contacts(?)', [$orgId]);
    }

    /**
     * Applique la même action à plusieurs tickets. Les tickets hors de portée, fermés ou refusés sont comptés « ignorés » sans bloquer les autres.
     *
     * @param  iterable<string>  $ids
     * @param  callable(PlatformSupportTicket): void  $action
     * @return array{done: int, skipped: int}
     */
    public function bulk(PlatformAdmin $admin, iterable $ids, string $name, callable $action): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            try {
                $action($this->find($admin, (string) $id));
                $done++;
            } catch (AuthorizationException|TicketRefused|TicketChanged) {
                $skipped++;
            }
        }

        PlatformAudit::record($admin->id, 'support.tickets.bulk', null, 'support_ticket', null, ['operation' => $name, 'done' => $done, 'skipped' => $skipped]);

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Trace un export de la liste (qui, quels filtres, combien de lignes).
     *
     * @param  array<string, mixed>  $filters
     */
    public function audited(PlatformAdmin $admin, string $action, int $count, array $filters = []): void
    {
        PlatformAudit::record($admin->id, $action, null, 'support_ticket', null, ['count' => $count, 'filters' => $filters]);
    }

    /** @throws AuthorizationException */
    private function authorize(PlatformAdmin $admin, string $permission): void
    {
        if (! SupportPermissions::can($admin, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Recharge le ticket (état à jour) et refuse si quelqu'un l'a modifié depuis l'ouverture de l'écran.
     *
     * @throws AuthorizationException|TicketChanged
     */
    private function fresh(PlatformAdmin $admin, PlatformSupportTicket $ticket, ?int $version): PlatformSupportTicket
    {
        $current = $this->find($admin, $ticket->id);

        if ($version !== null && $current->version !== $version) {
            throw new TicketChanged;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>|null  $diff
     */
    private function audit(PlatformAdmin $admin, string $action, PlatformSupportTicket $ticket, ?array $diff = null): void
    {
        PlatformAudit::record($admin->id, $action, $ticket->org_id, 'support_ticket', $ticket->id, ['reference' => $ticket->reference(), ...($diff ?? [])]);
    }
}
