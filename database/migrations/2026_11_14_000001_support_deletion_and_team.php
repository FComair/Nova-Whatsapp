<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression de tickets et équipe de support (docs/32).
 *
 *  - `support_delete_ticket(uuid)` : suppression DÉFINITIVE d'un ticket (messages, notes internes, pièces jointes, événements, étiquettes, liens).
 *    Les messages, pièces jointes et événements sont en ajout seul : la fonction (propriétaire, SECURITY DEFINER) est la seule exception
 *    reconnue par `forbid_modification()`, comme pour l'effacement d'une organisation. Seul `nova_platform` peut l'appeler ; le journal
 *    d'audit, lui, est conservé.
 *  - `platform_admins` : nom, téléphone, statut (actif / invité / suspendu / retiré), notes internes, permissions, invitation.
 *  - `platform_admin_client_scopes` : périmètre client optionnel d'un agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 10)->default('active');
            $table->text('internal_notes')->nullable();
            $table->jsonb('permissions')->nullable();
            $table->string('invitation_token', 64)->nullable();
            $table->timestampTz('invitation_expires_at')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
        });
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_status_check CHECK (status IN ('active','invited','suspended','removed'))");

        Schema::create('platform_admin_client_scopes', function (Blueprint $table): void {
            $table->uuid('admin_id');
            $table->uuid('org_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['admin_id', 'org_id']);
            $table->foreign('admin_id')->references('id')->on('platform_admins')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE platform_admin_client_scopes ENABLE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON platform_admin_client_scopes FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, DELETE ON platform_admin_client_scopes TO nova_platform');
        DB::statement('CREATE POLICY platform_all ON platform_admin_client_scopes FOR ALL TO nova_platform USING (true) WITH CHECK (true)');

        // Les tables en ajout seul acceptent DEUX exceptions : l'effacement d'une organisation (RGPD) et la suppression d'un ticket.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_modification() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                   AND current_setting('nova.gdpr_purge', true) = OLD.org_id::text
                   AND current_user::text::regrole = (SELECT proowner FROM pg_proc WHERE oid = to_regprocedure('gdpr_purge_org(uuid,text)'))
                THEN
                    RETURN OLD;
                END IF;

                IF TG_OP = 'DELETE'
                   AND TG_TABLE_NAME IN ('support_messages', 'support_attachments', 'support_ticket_events')
                   AND current_setting('nova.ticket_purge', true) = COALESCE(to_jsonb(OLD)->>'ticket_id', (SELECT m.ticket_id::text FROM support_messages m WHERE m.id = (to_jsonb(OLD)->>'message_id')::uuid))
                   AND current_user::text::regrole = (SELECT proowner FROM pg_proc WHERE oid = to_regprocedure('support_delete_ticket(uuid)'))
                THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'La table % est en ajout seul : % interdit', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION support_delete_ticket(p_ticket uuid) RETURNS jsonb
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public
            AS $$
            DECLARE
                v_ticket support_tickets%ROWTYPE;
                v_paths jsonb;
                v_messages bigint;
                v_attachments bigint;
                v_events bigint;
                v_internal bigint;
            BEGIN
                SELECT * INTO v_ticket FROM support_tickets WHERE id = p_ticket FOR UPDATE;

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                PERFORM set_config('nova.ticket_purge', p_ticket::text, true);

                SELECT COALESCE(jsonb_agg(a.path), '[]'::jsonb) INTO v_paths
                FROM support_attachments a JOIN support_messages m ON m.id = a.message_id WHERE m.ticket_id = p_ticket;

                SELECT count(*), count(*) FILTER (WHERE visibility = 'internal') INTO v_messages, v_internal FROM support_messages WHERE ticket_id = p_ticket;

                WITH gone AS (DELETE FROM support_attachments a USING support_messages m WHERE m.id = a.message_id AND m.ticket_id = p_ticket RETURNING a.id)
                SELECT count(*) INTO v_attachments FROM gone;

                WITH gone AS (DELETE FROM support_ticket_events WHERE ticket_id = p_ticket RETURNING id)
                SELECT count(*) INTO v_events FROM gone;

                DELETE FROM support_messages WHERE ticket_id = p_ticket;
                DELETE FROM support_ticket_tags WHERE ticket_id = p_ticket;
                DELETE FROM support_ticket_links WHERE ticket_id = p_ticket;
                DELETE FROM support_tickets WHERE id = p_ticket;

                RETURN jsonb_build_object(
                    'id', v_ticket.id, 'org_id', v_ticket.org_id, 'reference_number', v_ticket.reference_number, 'subject', v_ticket.subject,
                    'status', v_ticket.status, 'assigned_admin_id', v_ticket.assigned_admin_id, 'paths', v_paths,
                    'messages', v_messages, 'internal_notes', v_internal, 'attachments', v_attachments, 'events', v_events
                );
            END;
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION support_delete_ticket(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION support_delete_ticket(uuid) TO nova_platform');

        // Les liens vers un ticket supprimé n'ont plus de sens.
        DB::statement('GRANT DELETE ON platform_notifications TO nova_platform');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS support_delete_ticket(uuid)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_modification() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                   AND current_setting('nova.gdpr_purge', true) = OLD.org_id::text
                   AND current_user::text::regrole = (SELECT proowner FROM pg_proc WHERE oid = to_regprocedure('gdpr_purge_org(uuid,text)'))
                THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'La table % est en ajout seul : % interdit', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);

        Schema::dropIfExists('platform_admin_client_scopes');
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_status_check');
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->dropColumn(['name', 'phone', 'status', 'internal_notes', 'permissions', 'invitation_token', 'invitation_expires_at', 'invited_at', 'suspended_at', 'removed_at']);
        });
    }
};
