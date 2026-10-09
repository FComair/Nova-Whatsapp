<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets de support (docs/22, phase 2) : une demande du client à l'équipe Nova, sa conversation et son historique.
 *
 * - `support_tickets` : la demande. Référence « NWS-1001… » donnée par une séquence globale. L'application (nova_app) ne peut
 *   modifier que l'état, la lecture et les dates ; priorité interne et assignation restent à l'équipe (nova_platform).
 * - `support_messages` (ajout seul) : la conversation. Les notes internes de l'équipe (visibility = 'internal') sont invisibles
 *   pour nova_app **au niveau de la base** : même un défaut de l'application ne peut pas les montrer au client.
 * - `support_attachments` : pièces jointes (le fichier est sur le disque privé, jamais une adresse publique).
 * - `support_ticket_events` (ajout seul) : changements d'état, réouvertures, clôtures.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS support_ticket_reference_seq START 1001');
        DB::statement('GRANT USAGE ON SEQUENCE support_ticket_reference_seq TO nova_app');

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->bigInteger('reference_number')->default(DB::raw("nextval('support_ticket_reference_seq')"));
            $table->uuid('user_id');
            $table->uuid('submission_token')->nullable();
            $table->string('reply_email', 255);
            $table->string('locale', 2);
            $table->string('subject', 120);
            $table->string('category', 12);
            $table->string('related_to', 12)->nullable();
            $table->string('urgency', 6)->default('medium');
            $table->string('staff_priority', 6)->nullable();
            $table->string('status', 16)->default('open');
            $table->uuid('assigned_admin_id')->nullable();
            $table->timestampTz('last_message_at')->useCurrent();
            $table->timestampTz('last_staff_message_at')->nullable();
            $table->timestampTz('client_read_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_reason', 16)->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique('reference_number');
            $table->unique(['user_id', 'submission_token']);
            $table->index(['org_id', 'user_id']);
            $table->index(['org_id', 'status']);
            $table->index(['org_id', 'last_message_at']);
        });
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_category_check CHECK (category IN ('technical','feature','billing','access','other'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_related_check CHECK (related_to IS NULL OR related_to IN ('appointments','whatsapp','customers','services','planning','other'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_urgency_check CHECK (urgency IN ('low','medium','high') AND (staff_priority IS NULL OR staff_priority IN ('low','medium','high')))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('open','in_progress','waiting_on_you','resolved','closed'))");
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_closed_reason_check CHECK (closed_reason IS NULL OR closed_reason IN ('client','auto_resolved','auto_closed','staff'))");

        Rls::enableTenantTable('support_tickets');
        // L'application ne supprime jamais un ticket et ne touche ni à la priorité interne ni à l'assignation.
        DB::statement('REVOKE UPDATE, DELETE ON support_tickets FROM nova_app');
        DB::statement('GRANT UPDATE (status, last_message_at, last_staff_message_at, client_read_at, resolved_at, closed_at, closed_reason, updated_at) ON support_tickets TO nova_app');
        DB::statement('CREATE POLICY platform_write ON support_tickets FOR UPDATE TO nova_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT UPDATE ON support_tickets TO nova_platform');

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('ticket_id');
            $table->string('author_type', 6);
            $table->uuid('author_id')->nullable();
            $table->string('visibility', 8)->default('public');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->index(['ticket_id', 'created_at']);
        });
        DB::statement("ALTER TABLE support_messages ADD CONSTRAINT support_messages_author_check CHECK (author_type IN ('client','staff','system'))");
        DB::statement("ALTER TABLE support_messages ADD CONSTRAINT support_messages_visibility_check CHECK (visibility IN ('public','internal') AND (visibility = 'public' OR author_type = 'staff'))");
        Rls::enable('support_messages');
        // Le client ne lit que les messages publics et n'écrit que ses propres messages et les lignes système.
        DB::statement("CREATE POLICY tenant_read ON support_messages FOR SELECT TO nova_app USING (org_id = app_current_org() AND visibility = 'public')");
        DB::statement("CREATE POLICY tenant_insert ON support_messages FOR INSERT TO nova_app WITH CHECK (org_id = app_current_org() AND visibility = 'public' AND author_type IN ('client','system'))");
        Rls::grantPlatformRead('support_messages');
        DB::statement('CREATE POLICY platform_insert ON support_messages FOR INSERT TO nova_platform WITH CHECK (true)');
        DB::statement('GRANT INSERT ON support_messages TO nova_platform');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON support_messages FROM nova_app');
        DB::statement('CREATE TRIGGER support_messages_append_only BEFORE UPDATE OR DELETE ON support_messages FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER support_messages_no_truncate BEFORE TRUNCATE ON support_messages FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('message_id');
            $table->string('original_name', 150);
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->string('path', 255);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('support_messages')->cascadeOnDelete();
            $table->index('message_id');
        });
        // Un fichier d'un message interne ne doit pas se voir non plus : on suit la visibilité du message.
        Rls::enable('support_attachments');
        DB::statement('CREATE POLICY tenant_read ON support_attachments FOR SELECT TO nova_app USING (org_id = app_current_org() AND EXISTS (SELECT 1 FROM support_messages m WHERE m.id = message_id))');
        DB::statement('CREATE POLICY tenant_insert ON support_attachments FOR INSERT TO nova_app WITH CHECK (org_id = app_current_org())');
        Rls::grantPlatformRead('support_attachments');
        DB::statement('CREATE POLICY platform_insert ON support_attachments FOR INSERT TO nova_platform WITH CHECK (true)');
        DB::statement('GRANT INSERT ON support_attachments TO nova_platform');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON support_attachments FROM nova_app');
        DB::statement('CREATE TRIGGER support_attachments_append_only BEFORE UPDATE OR DELETE ON support_attachments FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER support_attachments_no_truncate BEFORE TRUNCATE ON support_attachments FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('ticket_id');
            $table->string('actor_type', 6);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 20);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('note', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->index(['ticket_id', 'created_at']);
        });
        DB::statement("ALTER TABLE support_ticket_events ADD CONSTRAINT support_events_actor_check CHECK (actor_type IN ('client','staff','system'))");
        Rls::enableTenantTable('support_ticket_events');
        DB::statement('CREATE POLICY platform_insert ON support_ticket_events FOR INSERT TO nova_platform WITH CHECK (true)');
        DB::statement('GRANT INSERT ON support_ticket_events TO nova_platform');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON support_ticket_events FROM nova_app');
        DB::statement('CREATE TRIGGER support_ticket_events_append_only BEFORE UPDATE OR DELETE ON support_ticket_events FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER support_ticket_events_no_truncate BEFORE TRUNCATE ON support_ticket_events FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        $this->purgeFunction();
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        DB::statement('DROP SEQUENCE IF EXISTS support_ticket_reference_seq');
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus les tables du support. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'booking_events', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners', 'support_attachments', 'support_ticket_events', 'support_messages', 'support_tickets', 'help_article_feedback', 'customer_saved_views', 'customer_consents', 'customers',
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
