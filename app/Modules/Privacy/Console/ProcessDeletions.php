<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Console;

use App\Modules\Privacy\Services\DeletionPipeline;
use Illuminate\Console\Command;

/**
 * Supprime réellement les comptes dont l'échéance est atteinte (planifié toutes les 5 minutes).
 */
final class ProcessDeletions extends Command
{
    protected $signature = 'gdpr:process-deletions';

    protected $description = 'Efface les données des comptes dont la suppression est échue';

    public function handle(DeletionPipeline $pipeline): int
    {
        $this->info($pipeline->run().' compte(s) supprimé(s).');

        return self::SUCCESS;
    }
}
