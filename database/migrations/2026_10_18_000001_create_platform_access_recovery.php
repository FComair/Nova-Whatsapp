<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Déblocage d'accès par le support (décision 18) : le Super Admin ne lit ni n'écrit la table `users` ; il passe par deux fonctions
 * dédiées, limitées aux membres actifs de l'organisation choisie. Aucun mot de passe ni secret n'en sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_org_members(p_org_id uuid)
            RETURNS TABLE (user_id uuid, email citext, locale text, role text, has_two_factor boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT u.id, u.email, u.locale::text, m.role::text, (u.two_factor_confirmed_at IS NOT NULL)
                FROM org_members m
                JOIN users u ON u.id = m.user_id
                WHERE m.org_id = p_org_id AND m.status = 'active'
                ORDER BY u.email
            $$
        SQL);

        // Réinitialise la double authentification et lève le verrouillage ; si un nouveau mot de passe (inutilisable) est fourni,
        // les sessions ouvertes sont invalidées et la personne devra passer par « mot de passe oublié ».
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_unblock_user(p_org_id uuid, p_user_id uuid, p_password_hash text)
            RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM org_members WHERE org_id = p_org_id AND user_id = p_user_id AND status = 'active') THEN
                    RAISE EXCEPTION 'user is not an active member of the organization' USING ERRCODE = 'P0002';
                END IF;

                UPDATE users SET
                    two_factor_secret = NULL,
                    two_factor_recovery_codes = NULL,
                    two_factor_confirmed_at = NULL,
                    failed_login_count = 0,
                    locked_until = NULL,
                    remember_token = NULL,
                    password = COALESCE(p_password_hash, password),
                    updated_at = now()
                WHERE id = p_user_id;
            END
            $$
        SQL);

        foreach (['platform_org_members(uuid)', 'platform_unblock_user(uuid, uuid, text)'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO nova_platform");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS platform_unblock_user(uuid, uuid, text)');
        DB::statement('DROP FUNCTION IF EXISTS platform_org_members(uuid)');
    }
};
