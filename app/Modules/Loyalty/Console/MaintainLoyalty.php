<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Loyalty\Services\LoyaltyRedemptions;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Tâche quotidienne de fidélité : passe en « expirée » les récompenses dont le délai est dépassé et envoie, si l'établissement l'a activé,
 * un rappel avant expiration (une seule fois par récompense).
 */
final class MaintainLoyalty extends Command
{
    protected $signature = 'loyalty:maintain';

    protected $description = 'Expire les récompenses de fidélité échues et envoie les rappels d\'expiration';

    public function handle(TenantContext $tenants, LoyaltyRedemptions $redemptions): int
    {
        $expired = 0;
        $reminded = 0;

        foreach (PlatformOrganization::query()->pluck('id') as $orgId) {
            $tenants->run((string) $orgId, function () use ($redemptions, &$expired, &$reminded): void {
                $reminded += $redemptions->remindExpiring();
                $expired += $redemptions->expireDue();
            });
        }

        $this->info("{$expired} récompense(s) expirée(s), {$reminded} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
