<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\AccountPause;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fin des pauses d'établissement : rappel quelques jours avant l'échéance, puis suspension du compte à l'échéance ; suppression programmée d'un compte resté suspendu.
 * Chaque organisation est traitée sous son contexte ; un incident sur l'une n'empêche pas les autres.
 */
final class EndPauses extends Command
{
    protected $signature = 'accounts:end-pauses';

    protected $description = 'Rappelle et clôt les pauses d\'établissement arrivées à échéance';

    public function handle(TenantContext $tenants, AccountPause $pause): int
    {
        $events = [];

        foreach (PlatformOrganization::query()->where(fn ($query) => $query->where('status', Organization::STATUS_PAUSED)->orWhere(fn ($expired) => $expired->where('status', Organization::STATUS_SUSPENDED)->where('suspension_reason', AccountPause::EXPIRED_REASON)))->pluck('id') as $orgId) {
            try {
                $event = $tenants->run((string) $orgId, fn (): ?string => $pause->process(Organization::query()->findOrFail((string) $orgId)));
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
