<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profils et accès des administrateurs (docs/38).
 *
 *  - `admin_role` : rôle d'un administrateur du panel (`super_admin`, `operations`, `support`, `read_only`, `custom`). Les agents de support et
 *    éditeurs de contenu gardent `support_role` et n'ont pas de `admin_role`. Les comptes existants sans rôle restreint deviennent `super_admin`.
 *  - `created_by` : qui a ajouté le compte ; `last_login_ip` : adresse de la dernière connexion ;
 *  - `sessions_revoked_at` : « déconnecter partout » : toute session ouverte avant cette date est refusée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->string('admin_role', 20)->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestampTz('sessions_revoked_at')->nullable();
        });

        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_admin_role_check CHECK (admin_role IS NULL OR admin_role IN ('super_admin','operations','support','read_only','custom'))");
        // Un compte à rôle restreint (agent, éditeur) n'a pas de rôle d'administrateur : les deux notions ne se cumulent pas.
        DB::statement('ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_one_role_check CHECK (support_role IS NULL OR admin_role IS NULL)');
        DB::statement("UPDATE platform_admins SET admin_role = 'super_admin' WHERE support_role IS NULL AND admin_role IS NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_one_role_check');
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_admin_role_check');

        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->dropColumn(['admin_role', 'created_by', 'last_login_ip', 'sessions_revoked_at']);
        });
    }
};
