<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Notifications\AgentTicketNotification;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prévient les agents du support (docs/29 §7). Qui est prévenu :
 *  - « assigned » : la personne à qui le ticket est confié ;
 *  - ticket assigné : son agent seulement ;
 *  - ticket sans agent (nouveau ticket, réponse du client, échéance) : tous les comptes qui peuvent le voir ;
 *  - jamais l'auteur de l'action elle-même.
 * Chaque agent choisit pour chaque événement « cloche », « e-mail », les deux ou aucun (`platform_admins.support_notifications`) ;
 * à défaut, `DEFAULTS`. L'envoi est au mieux : un échec ne bloque jamais l'action qui l'a provoqué.
 */
final class SupportNotifier
{
    public const CHANNELS = ['database', 'mail'];

    /** Événement => canaux par défaut. */
    public const DEFAULTS = [
        'new_ticket' => ['database', 'mail'],
        'client_reply' => ['database'],
        'assigned' => ['database', 'mail'],
        'priority_changed' => ['database'],
        'sla' => ['database', 'mail'],
        'ticket_done' => ['database'],
    ];

    /**
     * @param  array<string, string>  $extra  remplacements supplémentaires (priority, state…)
     */
    public function notify(string $event, SupportTicket $ticket, ?string $actorAdminId = null, ?string $assigneeId = null, array $extra = []): void
    {
        try {
            $organization = (string) DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('id', $ticket->org_id)->value('name');
            $params = ['reference' => $ticket->reference(), 'subject' => $ticket->subject, 'organization' => $organization, ...$extra];
            $url = SupportTicketResource::getUrl('view', ['record' => $ticket->id], panel: 'admin');

            foreach ($this->recipients($event, $ticket, $actorAdminId, $assigneeId) as $admin) {
                $channels = $this->channelsFor($admin, $event);

                if ($channels === []) {
                    continue;
                }

                $admin->notify((new AgentTicketNotification($event, $channels, $params, $url))->locale((string) config('app.locale')));
            }
        } catch (Throwable $e) {
            Log::warning('Support : notification des agents non envoyée.', ['ticket' => $ticket->reference(), 'event' => $event, 'error' => $e::class]);
        }
    }

    /**
     * Canaux choisis par un agent pour un événement.
     *
     * @return list<string>
     */
    public function channelsFor(PlatformAdmin $admin, string $event): array
    {
        $chosen = $admin->support_notifications[$event] ?? self::DEFAULTS[$event] ?? [];

        return array_values(array_intersect(self::CHANNELS, $chosen));
    }

    /**
     * @return list<PlatformAdmin>
     */
    private function recipients(string $event, SupportTicket $ticket, ?string $actorAdminId, ?string $assigneeId): array
    {
        // Un éditeur de contenu n'a pas accès aux tickets : il n'est jamais prévenu.
        $query = PlatformAdmin::query()->where(fn ($q) => $q->whereNull('support_role')->orWhere('support_role', '!=', PlatformAdmin::SUPPORT_EDITOR))
            ->when($actorAdminId !== null, fn ($q) => $q->where('id', '!=', $actorAdminId));

        $target = $event === 'assigned' ? $assigneeId : $ticket->assigned_admin_id;

        if ($target !== null) {
            return $query->where('id', $target)->get()->all();
        }

        if ($event === 'assigned') {
            return [];
        }

        // Ticket sans agent : tous ceux qui peuvent le voir (les agents voient les tickets non assignés).
        return $query->get()->all();
    }
}
