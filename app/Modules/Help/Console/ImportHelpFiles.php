<?php

declare(strict_types=1);

namespace App\Modules\Help\Console;

use App\Modules\Help\Support\FaqImporter;
use Illuminate\Console\Command;

/**
 * Importe les articles d'aide en fichiers Markdown dans la base pour les gérer depuis le Super Admin (docs/30). Sûr à relancer.
 */
final class ImportHelpFiles extends Command
{
    protected $signature = 'help:import-files';

    protected $description = "Importe les articles d'aide (fichiers Markdown) dans la base, sans rien écraser";

    public function handle(FaqImporter $importer): int
    {
        $result = $importer->import();

        $this->info("{$result['categories']} catégorie(s) et {$result['articles']} article(s) importés ; {$result['skipped']} déjà présent(s).");

        return self::SUCCESS;
    }
}
