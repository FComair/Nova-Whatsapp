<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Le rôle client n'a aucune raison de supprimer une organisation (la suppression passe par le pipeline RGPD, rôle plateforme)
        // ni de toucher à l'historique des migrations.
        DB::statement('REVOKE DELETE, TRUNCATE ON organizations FROM nova_app');
        DB::statement('REVOKE ALL ON migrations FROM nova_app');
    }

    public function down(): void
    {
        DB::statement('GRANT DELETE ON organizations TO nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON migrations TO nova_app');
    }
};
