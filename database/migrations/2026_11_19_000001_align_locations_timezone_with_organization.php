<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuseau unique par compte : `organizations.timezone` est la référence, chaque site (`locations.timezone`) la suit.
 *
 * Aucune réservation n'est modifiée : `bookings.starts_at` / `ends_at` sont des instants UTC, donc le même rendez-vous reste
 * au même moment. Seule la lecture locale des sites qui s'écartaient de leur entreprise change (ils reprennent le fuseau
 * que le prestataire a choisi à l'inscription).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update locations
               set timezone = organizations.timezone
              from organizations
             where organizations.id = locations.org_id
               and locations.timezone <> organizations.timezone
        SQL);
    }

    public function down(): void
    {
        // Les fuseaux d'origine ne sont pas conservés : rien à restaurer (les rendez-vous, en UTC, n'ont pas bougé).
    }
};
