<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Services\TemplateManager;
use App\Modules\Whatsapp\Services\WhatsappConnector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Synchronise chaque heure le statut des modèles de messages avec Meta (approbation ou refus arrivent plusieurs heures après la
 * soumission). Chaque organisation est traitée sous son contexte ; un incident sur l'une n'empêche pas les autres.
 */
final class SyncTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Synchronise le statut des modèles WhatsApp avec Meta';

    public function handle(TenantContext $tenants, WhatsappConnector $connector, TemplateManager $templates): int
    {
        $changed = 0;
        $failed = 0;

        foreach (PlatformOrganization::query()->whereIn('status', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE])->pluck('id') as $orgId) {
            try {
                $changed += $tenants->run((string) $orgId, function () use ($connector, $templates, $orgId): int {
                    $account = $connector->current();

                    return $account === null ? 0 : $templates->sync(Organization::query()->findOrFail((string) $orgId), $account);
                });
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->info("Statuts modifiés : {$changed}".($failed > 0 ? ", organisations en erreur : {$failed}" : ''));

        return self::SUCCESS;
    }
}
