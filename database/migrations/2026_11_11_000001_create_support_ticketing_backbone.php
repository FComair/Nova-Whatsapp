<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Socle de l'espace de support du Super Admin (docs/29), au-dessus des tables de docs/22.
 *
 * - `support_tickets` : nouveaux états (new, waiting_internal, reopened), priorité « urgent », origine, suivi du premier délai de réponse
 *   (SLA), version pour détecter deux modifications simultanées.
 * - `support_ticket_events` : visibilité (les événements internes, comme une assignation, sont invisibles pour nova_app **au niveau de la base**)
 *   et métadonnées.
 * - Données réservées à l'équipe (aucun droit pour nova_app) : étiquettes, liens entre tickets et enregistrements, vues enregistrées,
 *   notifications des agents.
 * - `platform_admins` : rôle de support (agent) et préférences de notification.
 * - Réglages des délais de réponse dans `platform_settings` ; fonction étroite pour lister les membres d'un établissement.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->tickets();
        $this->events();
        $this->staffTables();
        $this->admins();
        $this->settings();
        $this->memberContacts();
        $this->purgeFunction();
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS org_member_contacts(uuid)');
        DB::table('platform_settings')->whereIn('key', ['support_sla_urgent_hours', 'support_sla_high_hours', 'support_sla_medium_hours', 'support_sla_low_hours', 'support_at_risk_percent'])->delete();

        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_support_role_check');
        DB::statement('ALTER TABLE platform_admins DROP COLUMN IF EXISTS support_role, DROP COLUMN IF EXISTS support_notifications');

        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('support_saved_views');
        Schema::dropIfExists('support_ticket_links');
        Schema::dropIfExists('support_ticket_tags');

        DB::statement('DROP POLICY IF EXISTS tenant_read ON support_ticket_events');
        DB::statement('DROP POLICY IF EXISTS tenant_insert ON support_ticket_events');
        DB::statement('CREATE POLICY tenant_isolation ON support_ticket_events FOR ALL TO nova_app USING (org_id = app_current_org()) WITH CHECK (org_id = app_current_org())');
        DB::statement('ALTER TABLE support_ticket_events DISABLE TRIGGER support_ticket_events_append_only');
        DB::statement('ALTER TABLE support_ticket_events DROP CONSTRAINT IF EXISTS support_events_visibility_check');
        DB::statement('ALTER TABLE support_ticket_events DROP COLUMN IF EXISTS visibility, DROP COLUMN IF EXISTS meta');
        DB::statement('ALTER TABLE support_ticket_events ENABLE TRIGGER support_ticket_events_append_only');

        DB::statement('DROP POLICY IF EXISTS platform_insert ON support_tickets');
        DB::statement('REVOKE INSERT ON support_tickets FROM nova_platform');
        DB::statement('REVOKE UPDATE (last_client_message_at, version) ON support_tickets FROM nova_app');
        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_source_check, DROP CONSTRAINT IF EXISTS support_tickets_sla_alert_check, DROP CONSTRAINT IF EXISTS support_tickets_status_check, DROP CONSTRAINT IF EXISTS support_tickets_urgency_check');
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('open','in_progress','waiting_on_you','resolved','closed'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_urgency_check CHECK (urgency IN ('low','medium','high') AND (staff_priority IS NULL OR staff_priority IN ('low','medium','high')))");
        DB::statement('DROP INDEX IF EXISTS support_tickets_assigned_admin_id_index');
        DB::statement('DROP INDEX IF EXISTS support_tickets_status_last_message_at_index');
        DB::statement('ALTER TABLE support_tickets DROP COLUMN IF EXISTS source, DROP COLUMN IF EXISTS first_response_at, DROP COLUMN IF EXISTS first_response_due_at, DROP COLUMN IF EXISTS last_client_message_at, DROP COLUMN IF EXISTS sla_alert, DROP COLUMN IF EXISTS version');
    }

    private function tickets(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('source', 12)->default('contact_form');
            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('first_response_due_at')->nullable();
            $table->timestampTz('last_client_message_at')->nullable();
            $table->string('sla_alert', 8)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->index('assigned_admin_id');
            $table->index(['status', 'last_message_at']);
        });

        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT support_tickets_status_check');
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('new','open','in_progress','waiting_on_you','waiting_internal','resolved','closed','reopened'))");
        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT support_tickets_urgency_check');
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_urgency_check CHECK (urgency IN ('low','medium','high') AND (staff_priority IS NULL OR staff_priority IN ('low','medium','high','urgent')))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_source_check CHECK (source IN ('contact_form','email','in_app','manual'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_sla_alert_check CHECK (sla_alert IS NULL OR sla_alert IN ('at_risk','overdue'))");

        // Le client écrit le dernier message du client et la version ; le reste (priorité, assignation, SLA, origine) reste à l'équipe.
        DB::statement('GRANT UPDATE (last_client_message_at, version) ON support_tickets TO nova_app');

        // L'équipe peut ouvrir un ticket au nom d'un client (création manuelle).
        DB::statement('CREATE POLICY platform_insert ON support_tickets FOR INSERT TO nova_platform WITH CHECK (true)');
        DB::statement('GRANT INSERT ON support_tickets TO nova_platform');
        DB::statement('GRANT USAGE ON SEQUENCE support_ticket_reference_seq TO nova_platform');
    }

    private function events(): void
    {
        DB::statement('ALTER TABLE support_ticket_events DISABLE TRIGGER support_ticket_events_append_only');
        Schema::table('support_ticket_events', function (Blueprint $table): void {
            $table->string('visibility', 8)->default('public');
            $table->jsonb('meta')->nullable();
        });
        DB::statement('ALTER TABLE support_ticket_events ENABLE TRIGGER support_ticket_events_append_only');
        DB::statement("ALTER TABLE support_ticket_events ADD CONSTRAINT support_events_visibility_check CHECK (visibility IN ('public','internal'))");
        DB::statement('ALTER TABLE support_ticket_events ALTER COLUMN action TYPE varchar(24)');

        // Le client ne lit que les événements publics (les notes internes d'avant cette version restent exclues par leur action).
        DB::statement('DROP POLICY tenant_isolation ON support_ticket_events');
        DB::statement("CREATE POLICY tenant_read ON support_ticket_events FOR SELECT TO nova_app USING (org_id = app_current_org() AND visibility = 'public' AND action <> 'internal_note')");
        DB::statement("CREATE POLICY tenant_insert ON support_ticket_events FOR INSERT TO nova_app WITH CHECK (org_id = app_current_org() AND visibility = 'public')");
    }

    private function staffTables(): void
    {
        Schema::create('support_ticket_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('ticket_id');
            $table->string('tag', 30);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->unique(['ticket_id', 'tag']);
            $table->index('tag');
        });
        $this->staffOnly('support_ticket_tags', delete: true);

        Schema::create('support_ticket_links', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('ticket_id');
            $table->string('kind', 12);
            $table->uuid('ref_id')->nullable();
            $table->string('label', 120);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->index('ticket_id');
        });
        DB::statement("ALTER TABLE support_ticket_links ADD CONSTRAINT support_links_kind_check CHECK (kind IN ('ticket','booking','customer','service','conversation','other'))");
        DB::statement('CREATE UNIQUE INDEX support_links_unique ON support_ticket_links (ticket_id, kind, label)');
        $this->staffOnly('support_ticket_links', delete: true);

        // Vues enregistrées d'un agent : ni organisation, ni donnée client.
        Schema::create('support_saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('admin_id');
            $table->string('name', 40);
            $table->jsonb('filters');
            $table->timestampsTz();

            $table->unique(['admin_id', 'name']);
        });
        DB::statement('REVOKE ALL ON support_saved_views FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON support_saved_views TO nova_platform');

        // Notifications dans le panel (cloche) : table du Super Admin uniquement.
        Schema::create('platform_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('REVOKE ALL ON platform_notifications FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON platform_notifications TO nova_platform');
    }

    /** Table lisible et modifiable par le seul rôle nova_platform : nova_app n'a ni droit ni politique. */
    private function staffOnly(string $table, bool $delete): void
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

    private function admins(): void
    {
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->string('support_role', 10)->nullable();
            $table->jsonb('support_notifications')->nullable();
        });
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_support_role_check CHECK (support_role IS NULL OR support_role IN ('agent'))");
    }

    private function settings(): void
    {
        // Délais cibles de première réponse (heures écoulées) selon la priorité, et seuil « à risque » (part du délai écoulée).
        DB::table('platform_settings')->insert([
            ['key' => 'support_sla_urgent_hours', 'value' => '2', 'updated_at' => now()],
            ['key' => 'support_sla_high_hours', 'value' => '8', 'updated_at' => now()],
            ['key' => 'support_sla_medium_hours', 'value' => '24', 'updated_at' => now()],
            ['key' => 'support_sla_low_hours', 'value' => '48', 'updated_at' => now()],
            ['key' => 'support_at_risk_percent', 'value' => '75', 'updated_at' => now()],
        ]);
    }

    /** Le Super Admin ne lit pas les utilisateurs : cette fonction ne renvoie que l'identité des membres actifs d'un établissement. */
    private function memberContacts(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION org_member_contacts(p_org_id uuid)
            RETURNS TABLE (user_id uuid, email citext, locale text, role text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT u.id, u.email, u.locale::text, m.role::text
                FROM org_members m
                JOIN users u ON u.id = m.user_id
                WHERE m.org_id = p_org_id AND m.status = 'active'
                ORDER BY (m.role = 'owner') DESC, u.email
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION org_member_contacts(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION org_member_contacts(uuid) TO nova_platform');
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus les étiquettes et les liens du support. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'loyalty_ledger', 'loyalty_rewards', 'loyalty_members', 'loyalty_program_services', 'loyalty_programs',
            'booking_events', 'booking_tip_allocations', 'booking_tips', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners',
            'review_link_clicks', 'feedback_prompt_dismissals', 'nova_feedback',
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
