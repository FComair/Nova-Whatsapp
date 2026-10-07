<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Passage horaire de la facturation : renouvellements dus, relances d'impayé, rappel et fin d'essai. Chaque organisation est
 * traitée sous son contexte ; un incident sur l'une n'empêche pas les autres.
 */
final class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Renouvellements, relances d\'impayé et fins d\'essai';

    public function handle(TenantContext $tenants, BillingService $billing): int
    {
        $events = [];
        $usable = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE];

        // Le rôle plateforme ne sert qu'à lister les organisations ; chaque traitement se fait sous l'isolation de la sienne.
        foreach (PlatformOrganization::query()->whereIn('status', $usable)->pluck('id') as $orgId) {
            try {
                $event = $tenants->run((string) $orgId, fn (): ?string => $billing->process(Organization::query()->findOrFail((string) $orgId)));
            } catch (Throwable $e) {
                report($e);
                $event = 'error';
            }

            if ($event !== null) {
                $events[$event] = ($events[$event] ?? 0) + 1;
            }
        }

        $this->info($events === [] ? 'Rien à traiter.' : collect($events)->map(fn (int $count, string $event): string => "{$event}: {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
