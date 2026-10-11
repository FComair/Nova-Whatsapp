<?php

declare(strict_types=1);

namespace App\Modules\Legal\Console;

use App\Modules\Legal\Services\InviteeAcceptanceRepair;
use Illuminate\Console\Command;

/**
 * Retrouve les acceptations juridiques des personnes invitées avant le correctif, d'après le journal d'audit (docs/28).
 * Sans option : simple rapport, rien n'est écrit. Avec --apply : écrit les acceptations prouvées ; les autres personnes verront l'avis de
 * nouvelle acceptation dans l'application.
 */
final class RepairInviteeAcceptances extends Command
{
    protected $signature = 'legal:repair-invitee-acceptances {--apply : Écrire les acceptations retrouvées (par défaut : rapport seulement)}';

    protected $description = 'Retrouve les acceptations des documents juridiques des personnes invitées, à partir du journal d\'audit';

    public function handle(InviteeAcceptanceRepair $repair): int
    {
        $plan = $repair->plan();
        $this->line(count($plan['recoverable']).' acceptation(s) prouvée(s) par le journal d\'audit.');
        $this->line(count($plan['unresolved']).' cas sans preuve suffisante : ces personnes devront accepter les versions en vigueur (avis dans l\'application).');

        if (! $this->option('apply')) {
            $this->warn('Rapport seulement : relancez avec --apply pour écrire les acceptations prouvées.');

            return self::SUCCESS;
        }

        $this->info($repair->apply().' acceptation(s) écrite(s).');

        return self::SUCCESS;
    }
}
