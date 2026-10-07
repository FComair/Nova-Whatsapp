<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Calendars\Services\CalendarSync;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Synchronise les calendriers externes dus (planifié toutes les 5 minutes ; chaque calendrier a son propre intervalle).
 */
final class SyncCalendars extends Command
{
    protected $signature = 'calendars:sync';

    protected $description = 'Synchronise les calendriers externes en lecture seule';

    public function handle(TenantContext $tenants, CalendarSync $sync): int
    {
        $ok = 0;
        $failed = 0;
        $usable = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED];

        // Le rôle plateforme ne sert qu'à lister les organisations ; chaque synchronisation se fait sous l'isolation de la sienne.
        foreach (PlatformOrganization::query()->whereIn('status', $usable)->pluck('id') as $orgId) {
            $tenants->run((string) $orgId, function () use ($sync, &$ok, &$failed, $orgId): void {
                $organization = Organization::query()->findOrFail((string) $orgId);

                foreach ($sync->due() as $calendar) {
                    $sync->sync($calendar, $organization) ? $ok++ : $failed++;
                }
            });
        }

        $this->info("{$ok} calendrier(s) synchronisé(s), {$failed} en échec.");

        return self::SUCCESS;
    }
}
