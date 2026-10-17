<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avis des clients sur Rendivoo (docs/26).
 *
 * - `nova_feedback` : avis PRIVÉ à l'équipe Rendivoo (note de 1 à 5, commentaire facultatif), jamais publié. Journal en ajout seul.
 *   Il peut se rattacher à un ticket (`ticket_id`) ; une seule fois par personne et par ticket.
 * - `review_link_clicks` : le client a ouvert le lien d'avis public Google ou Trustpilot (la plateforme, rien d'autre : ce qu'il y
 *   écrit ne nous est jamais connu). Strictement séparé des avis privés ; ajout seul.
 * - `feedback_prompt_dismissals` : « Pas maintenant » sur une invitation, pour ne pas la reproposer.
 * Isolation par établissement (RLS) ; l'équipe Rendivoo lit tout (nova_platform), n'écrit rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nova_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->uuid('ticket_id')->nullable();
            $table->string('source', 12);
            $table->smallInteger('rating');
            $table->text('comment')->nullable();
            $table->char('locale', 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('support_tickets');
            $table->index(['org_id', 'created_at']);
            $table->index('created_at');
        });
        DB::statement('ALTER TABLE nova_feedback ADD CONSTRAINT nova_feedback_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE nova_feedback ADD CONSTRAINT nova_feedback_source_check CHECK (source IN ('general', 'intervention', 'ticket'))");
        DB::statement('ALTER TABLE nova_feedback ADD CONSTRAINT nova_feedback_comment_check CHECK (comment IS NULL OR char_length(comment) <= 2000)');
        DB::statement("ALTER TABLE nova_feedback ADD CONSTRAINT nova_feedback_ticket_check CHECK ((source = 'general') = (ticket_id IS NULL))");
        DB::statement('CREATE UNIQUE INDEX nova_feedback_one_per_ticket ON nova_feedback (user_id, ticket_id, source) WHERE ticket_id IS NOT NULL');

        Schema::create('review_link_clicks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->uuid('feedback_id')->nullable();
            $table->string('platform', 12);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('feedback_id')->references('id')->on('nova_feedback');
            $table->index(['org_id', 'created_at']);
        });
        DB::statement("ALTER TABLE review_link_clicks ADD CONSTRAINT review_link_clicks_platform_check CHECK (platform IN ('google', 'trustpilot'))");

        Schema::create('feedback_prompt_dismissals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('prompt_key', 60);
            $table->timestampTz('dismissed_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['user_id', 'prompt_key']);
        });

        foreach (['nova_feedback', 'review_link_clicks', 'feedback_prompt_dismissals'] as $table) {
            Rls::enableTenantTable($table);
        }

        // Journaux en ajout seul : ni modification ni suppression par l'application (l'effacement d'un établissement passe par la
        // fonction gdpr_purge_org, qui s'exécute avec les droits du propriétaire).
        foreach (['nova_feedback', 'review_link_clicks', 'feedback_prompt_dismissals'] as $table) {
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM nova_app");
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION forbid_modification()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()");
        }

        $this->purgeFunction();
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_prompt_dismissals');
        Schema::dropIfExists('review_link_clicks');
        Schema::dropIfExists('nova_feedback');
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus les trois tables d'avis. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'booking_events', 'booking_tip_allocations', 'booking_tips', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners',
            'review_link_clicks', 'feedback_prompt_dismissals', 'nova_feedback',
            'support_attachments', 'support_ticket_events', 'support_messages', 'support_tickets', 'help_article_feedback', 'customer_saved_views', 'customer_consents', 'customers',
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
