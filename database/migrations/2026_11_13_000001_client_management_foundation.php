<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des clients par le Super Admin (docs/31).
 *
 * - Deux fonctions étroites (SECURITY DEFINER) : le Super Admin ne lit pas la table des utilisateurs, mais doit voir le contact principal
 *   d'un client (liste), le nombre de membres, la dernière connexion et, sur la fiche, ses membres (rôle, statut, double authentification
 *   activée ou non). Jamais de mot de passe, de secret ni de code de secours.
 * - Données propres à l'équipe (aucun droit pour le rôle client) : notes internes, étiquettes, vues enregistrées.
 * - Effacement RGPD : notes et étiquettes d'un établissement sont supprimées avec lui.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->functions();
        $this->tables();
        $this->purgeFunction();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_saved_views');
        Schema::dropIfExists('client_tags');
        Schema::dropIfExists('client_notes');
        DB::statement('DROP FUNCTION IF EXISTS org_member_directory(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS org_user_summary()');
    }

    private function functions(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION org_user_summary()
            RETURNS TABLE (org_id uuid, owner_email citext, owner_phone text, owner_locale text, owner_last_login timestamptz, members_count integer, last_login_at timestamptz)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT o.id, ow.email, ow.phone_e164::text, ow.locale::text, ow.last_login_at,
                       (SELECT count(*)::integer FROM org_members m WHERE m.org_id = o.id AND m.status = 'active'),
                       (SELECT max(u.last_login_at) FROM org_members m JOIN users u ON u.id = m.user_id WHERE m.org_id = o.id)
                FROM organizations o
                LEFT JOIN LATERAL (
                    SELECT u.email, u.phone_e164, u.locale, u.last_login_at
                    FROM org_members m JOIN users u ON u.id = m.user_id
                    WHERE m.org_id = o.id AND m.role = 'owner' AND m.status = 'active'
                    ORDER BY m.created_at LIMIT 1
                ) ow ON true
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION org_user_summary() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION org_user_summary() TO nova_platform');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION org_member_directory(p_org_id uuid)
            RETURNS TABLE (user_id uuid, email citext, phone text, locale text, role text, member_status text, user_status text, two_factor boolean, email_verified boolean, last_login_at timestamptz, joined_at timestamptz)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT u.id, u.email, u.phone_e164::text, u.locale::text, m.role::text, m.status::text, u.status::text,
                       (u.two_factor_confirmed_at IS NOT NULL), (u.email_verified_at IS NOT NULL), u.last_login_at, m.created_at
                FROM org_members m JOIN users u ON u.id = m.user_id
                WHERE m.org_id = p_org_id
                ORDER BY (m.role = 'owner') DESC, m.created_at
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION org_member_directory(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION org_member_directory(uuid) TO nova_platform');
    }

    private function tables(): void
    {
        Schema::create('client_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('author_id')->nullable();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'created_at']);
        });
        $this->staffOnly('client_notes');

        Schema::create('client_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('tag', 30);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['org_id', 'tag']);
            $table->index('tag');
        });
        $this->staffOnly('client_tags', delete: true);

        // Vues enregistrées d'un administrateur : ni organisation, ni donnée client.
        Schema::create('client_saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('admin_id');
            $table->string('name', 40);
            $table->jsonb('filters');
            $table->timestampsTz();

            $table->unique(['admin_id', 'name']);
        });
        DB::statement('REVOKE ALL ON client_saved_views FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON client_saved_views TO nova_platform');
    }

    /** Table lisible et modifiable par le seul rôle nova_platform : nova_app n'a ni droit ni politique. */
    private function staffOnly(string $table, bool $delete = false): void
    {
        Rls::enable($table);
        DB::statement("REVOKE ALL ON {$table} FROM nova_app");
        Rls::grantPlatformRead($table);
        DB::statement("CREATE POLICY platform_insert ON {$table} FOR INSERT TO nova_platform WITH CHECK (true)");
        DB::statement("GRANT INSERT ON {$table} TO nova_platform");

        if ($delete) {
            DB::statement("CREATE POLICY platform_delete ON {$table} FOR DELETE TO nova_platform USING (true)");
            DB::statement("GRANT DELETE ON {$table} TO nova_platform");
        }
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus les notes et étiquettes des clients. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'loyalty_ledger', 'loyalty_rewards', 'loyalty_members', 'loyalty_program_services', 'loyalty_programs',
            'booking_events', 'booking_tip_allocations', 'booking_tips', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners',
            'review_link_clicks', 'feedback_prompt_dismissals', 'nova_feedback',
            'client_notes', 'client_tags',
            'support_ticket_links', 'support_ticket_tags', 'support_attachments', 'support_ticket_events', 'support_messages', 'support_tickets', 'help_article_feedback', 'customer_saved_views', 'customer_consents', 'customers',
            'services', 'service_categories', 'business_hours_exceptions', 'business_hours', 'org_member_locations', 'locations',
            'api_clients', 'whatsapp_qr_codes', 'whatsapp_templates', 'whatsapp_accounts', 'booking_policies', 'onboarding_progress', 'dunning_events',
        ])."'";

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION gdpr_purge_org(p_org_id uuid, p_step text) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public
            AS \$\$
            DECLARE
                v_count bigint := 0;
                v_emails text[];
            BEGIN
                IF p_org_id IS NULL THEN
                    RAISE EXCEPTION 'Organisation manquante';
                END IF;

                PERFORM set_config('nova.gdpr_purge', p_org_id::text, true);

                IF p_step = 'users' THEN
                    WITH gone AS (
                        DELETE FROM users u
                        WHERE u.id IN (SELECT m.user_id FROM org_members m WHERE m.org_id = p_org_id)
                          AND NOT EXISTS (SELECT 1 FROM org_members o WHERE o.user_id = u.id AND o.org_id <> p_org_id)
                        RETURNING u.email
                    )
                    SELECT count(*), array_agg(email::text) INTO v_count, v_emails FROM gone;

                    DELETE FROM password_reset_tokens WHERE email::text = ANY (COALESCE(v_emails, ARRAY[]::text[]));

                    RETURN v_count;
                ELSIF p_step = 'org_members' THEN
                    DELETE FROM org_members WHERE org_id = p_org_id;
                ELSIF p_step = 'audit_logs' THEN
                    DELETE FROM audit_logs WHERE org_id = p_org_id AND actor_type <> 'platform_admin' AND action NOT LIKE 'gdpr.%' AND action <> 'organization.deleted';
                ELSIF p_step = 'payment_methods' THEN
                    UPDATE subscriptions SET payment_method_id = NULL, status = 'canceled', cancel_at = now() WHERE org_id = p_org_id;
                    DELETE FROM payment_methods WHERE org_id = p_org_id;
                ELSIF p_step = 'invoices' THEN
                    UPDATE invoices SET billing_snapshot = '{}'::jsonb, pseudonymised_at = now() WHERE org_id = p_org_id AND pseudonymised_at IS NULL;
                ELSIF p_step = ANY (ARRAY[{$steps}]) THEN
                    EXECUTE format('DELETE FROM %I WHERE org_id = \$1', p_step) USING p_org_id;
                ELSE
                    RAISE EXCEPTION 'Étape d''effacement inconnue : %', p_step;
                END IF;

                GET DIAGNOSTICS v_count = ROW_COUNT;

                RETURN v_count;
            END;
            \$\$
        SQL);
    }
};
