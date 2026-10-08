<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Catalog\Models\PriceListImport;
use App\Modules\Catalog\Services\PriceListAnalyzer;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Efface chaque heure les photos des imports de listes de prix restés ouverts plus longtemps que `pricelist.retention_hours`
 * (24 h) : l'import passe à « expiré ». Les photos d'un import terminé ou annulé sont déjà effacées au moment où il se termine ;
 * cette tâche rattrape aussi un fichier resté par accident. Chaque organisation est traitée sous son contexte.
 */
final class PurgePriceListFiles extends Command
{
    protected $signature = 'pricelists:purge-files';

    protected $description = 'Efface les photos des imports de listes de prix expirés';

    public function handle(TenantContext $tenants, PriceListAnalyzer $analyzer): int
    {
        $limit = now()->subHours((int) config('pricelist.retention_hours'));
        $purged = 0;
        $failed = 0;

        foreach (PlatformOrganization::query()->pluck('id') as $orgId) {
            try {
                $purged += $tenants->run((string) $orgId, function () use ($analyzer, $limit): int {
                    $count = 0;

                    foreach (PriceListImport::query()->whereNotNull('files')->where(fn ($query) => $query->where('status', '<>', 'analysed')->orWhere('created_at', '<', $limit))->get() as $import) {
                        if ($import->status === 'analysed') {
                            $import->forceFill(['status' => 'expired'])->save();
                        }

                        $analyzer->discardFiles($import);
                        $count++;
                    }

                    return $count;
                });
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->info("Imports nettoyés : {$purged}".($failed > 0 ? ", organisations en erreur : {$failed}" : ''));

        return self::SUCCESS;
    }
}
