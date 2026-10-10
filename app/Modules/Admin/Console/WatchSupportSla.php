<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SupportNotifier;
use App\Modules\Support\Services\SupportSla;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Console\Command;

/**
 * Surveille le délai de première réponse (docs/29 §6) : un ticket sans réponse passe « à risque » puis « en retard ». L'état est mémorisé
 * sur le ticket (`sla_alert`) : chaque changement d'état prévient les agents UNE fois, jamais en boucle. Planifié toutes les heures.
 */
final class WatchSupportSla extends Command
{
    protected $signature = 'support:watch-sla';

    protected $description = 'Prévient les agents des tickets sans première réponse à risque ou en retard';

    public function handle(SupportSla $sla, SupportNotifier $notifier): int
    {
        $changed = 0;

        PlatformSupportTicket::query()
            ->whereIn('status', TicketStatus::ACTIVE)->whereNull('first_response_at')->whereNotNull('first_response_due_at')
            ->orderBy('created_at')
            ->each(function (PlatformSupportTicket $ticket) use ($sla, $notifier, &$changed): void {
                $state = $sla->stateOf($ticket);

                // On ne notifie que l'aggravation (rien → à risque → en retard), jamais deux fois le même état.
                if ($state === null || $state === $ticket->sla_alert) {
                    return;
                }

                $ticket->forceFill(['sla_alert' => $state])->save();
                $notifier->notify('sla', $ticket, extra: ['state' => __("tickets.attention.{$state}", [], (string) config('app.locale'))]);
                PlatformAudit::system('support.sla.'.$state, $ticket->org_id, 'support_ticket', $ticket->id, ['reference' => $ticket->reference()]);
                $changed++;
            });

        $this->info("{$changed} ticket(s) signalé(s).");

        return self::SUCCESS;
    }
}
