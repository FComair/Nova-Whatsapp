<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programme de fidélité configurable par chaque établissement (docs/27).
 *
 * - `loyalty_programs` : un programme par établissement (un seul non archivé à la fois) : règle de gain, récompense, règles
 *   d'utilisation, textes multilingues (JSON par langue) et réglages des messages WhatsApp.
 * - `loyalty_program_services` : services qui comptent pour la progression (`earn`) et services visés par la récompense (`reward`).
 * - `loyalty_members` : participation d'un client (compteur de tampons = somme du journal, jamais deux sources de vérité).
 * - `loyalty_rewards` : récompenses obtenues, avec un instantané de la récompense au moment de l'attribution (modifier le
 *   programme ensuite ne change jamais une récompense déjà obtenue).
 * - `loyalty_ledger` : journal en AJOUT SEUL de tout ce qui compte (tampons gagnés ou annulés, ajustements, récompenses
 *   attribuées, utilisées, annulées, changements de configuration). Un tampon par rendez-vous et par programme, garanti par la base.
 * Isolation par établissement (RLS) ; l'équipe Nova lit, n'écrit rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('status', 10)->default('draft');
            $table->jsonb('name')->default('{}');
            $table->jsonb('reward_description')->default('{}');
            $table->jsonb('terms')->default('{}');
            $table->smallInteger('required_visits')->default(8);
            $table->string('services_mode', 10)->default('all');
            $table->integer('min_amount_cents')->nullable();
            $table->boolean('require_payment')->default(false);
            $table->string('reward_type', 20)->default('free_service');
            $table->integer('reward_value')->nullable();
            $table->integer('reward_max_cents')->nullable();
            $table->string('reward_services_mode', 10)->default('all');
            $table->string('redeem_timing', 12)->default('next_visit');
            $table->boolean('combinable')->default(false);
            $table->boolean('requires_appointment')->default(true);
            $table->smallInteger('expiry_days')->nullable();
            $table->smallInteger('expiry_reminder_days')->default(14);
            $table->boolean('auto_enroll')->default(true);
            $table->jsonb('messages')->default('{}');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_status_check CHECK (status IN ('draft', 'active', 'paused', 'archived'))");
        DB::statement('ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_visits_check CHECK (required_visits BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_modes_check CHECK (services_mode IN ('all', 'selected') AND reward_services_mode IN ('all', 'selected'))");
        DB::statement("ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_reward_check CHECK (reward_type IN ('free_service', 'percent_discount', 'fixed_amount', 'custom'))");
        DB::statement("ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_timing_check CHECK (redeem_timing IN ('next_visit', 'any_time'))");
        DB::statement('ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_amounts_check CHECK ((min_amount_cents IS NULL OR min_amount_cents >= 0) AND (reward_max_cents IS NULL OR reward_max_cents > 0) AND (reward_value IS NULL OR reward_value > 0))');
        DB::statement('ALTER TABLE loyalty_programs ADD CONSTRAINT loyalty_programs_expiry_check CHECK ((expiry_days IS NULL OR expiry_days BETWEEN 1 AND 730) AND expiry_reminder_days BETWEEN 1 AND 90)');
        DB::statement("CREATE UNIQUE INDEX loyalty_programs_one_live ON loyalty_programs (org_id) WHERE status <> 'archived'");

        Schema::create('loyalty_program_services', function (Blueprint $table): void {
            $table->uuid('org_id');
            $table->uuid('program_id');
            $table->uuid('service_id');
            $table->string('kind', 6);

            $table->primary(['program_id', 'service_id', 'kind']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services');
        });
        DB::statement("ALTER TABLE loyalty_program_services ADD CONSTRAINT loyalty_program_services_kind_check CHECK (kind IN ('earn', 'reward'))");

        Schema::create('loyalty_members', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('program_id');
            $table->uuid('customer_id');
            $table->string('status', 10)->default('active');
            $table->integer('stamps')->default(0);
            $table->integer('lifetime_stamps')->default(0);
            $table->integer('rewards_earned')->default(0);
            $table->timestampTz('last_visit_at')->nullable();
            $table->string('joined_via', 10)->default('auto');
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampTz('opted_out_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('program_id')->references('id')->on('loyalty_programs');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->unique(['program_id', 'customer_id']);
            $table->index(['org_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE loyalty_members ADD CONSTRAINT loyalty_members_status_check CHECK (status IN ('active', 'opted_out'))");
        DB::statement("ALTER TABLE loyalty_members ADD CONSTRAINT loyalty_members_joined_check CHECK (joined_via IN ('auto', 'staff'))");
        DB::statement('ALTER TABLE loyalty_members ADD CONSTRAINT loyalty_members_stamps_check CHECK (stamps >= 0 AND lifetime_stamps >= 0 AND rewards_earned >= 0)');

        Schema::create('loyalty_rewards', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('program_id');
            $table->uuid('customer_id');
            $table->string('status', 10)->default('available');
            $table->jsonb('snapshot');
            $table->uuid('earned_booking_id')->nullable();
            $table->timestampTz('earned_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('expiry_reminded_at')->nullable();
            $table->timestampTz('redeemed_at')->nullable();
            $table->uuid('redeemed_by')->nullable();
            $table->uuid('redeemed_booking_id')->nullable();
            $table->integer('applied_value_cents')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('program_id')->references('id')->on('loyalty_programs');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('earned_booking_id')->references('id')->on('bookings');
            $table->foreign('redeemed_booking_id')->references('id')->on('bookings');
            $table->foreign('redeemed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'customer_id', 'status']);
            $table->index(['org_id', 'status', 'expires_at']);
        });
        DB::statement("ALTER TABLE loyalty_rewards ADD CONSTRAINT loyalty_rewards_status_check CHECK (status IN ('available', 'redeemed', 'expired', 'void'))");
        // Un rendez-vous ne reçoit qu'une récompense utilisée : la base empêche l'utilisation en double.
        DB::statement("CREATE UNIQUE INDEX loyalty_rewards_one_per_booking ON loyalty_rewards (redeemed_booking_id) WHERE status = 'redeemed' AND redeemed_booking_id IS NOT NULL");

        Schema::create('loyalty_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('program_id');
            $table->uuid('customer_id')->nullable();
            $table->string('kind', 24);
            $table->integer('stamps_delta')->default(0);
            $table->integer('balance_after')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->uuid('reward_id')->nullable();
            $table->string('actor_type', 10)->default('system');
            $table->uuid('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('program_id')->references('id')->on('loyalty_programs');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->foreign('reward_id')->references('id')->on('loyalty_rewards');
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'customer_id', 'created_at']);
        });
        DB::statement("ALTER TABLE loyalty_ledger ADD CONSTRAINT loyalty_ledger_kind_check CHECK (kind IN ('stamp_earned', 'stamp_reversed', 'manual_adjustment', 'reward_issued', 'reward_voided', 'reward_redeemed', 'redemption_reversed', 'reward_expired', 'enrolled', 'opted_out', 'rejoined', 'config_changed', 'status_changed', 'message_sent'))");
        // Un tampon par rendez-vous et par programme (et une seule annulation) : aucun doublon possible, même en cas de rejeu.
        DB::statement("CREATE UNIQUE INDEX loyalty_ledger_one_earn ON loyalty_ledger (program_id, booking_id) WHERE kind = 'stamp_earned'");
        DB::statement("CREATE UNIQUE INDEX loyalty_ledger_one_reversal ON loyalty_ledger (program_id, booking_id) WHERE kind = 'stamp_reversed'");

        foreach (['loyalty_programs', 'loyalty_program_services', 'loyalty_members', 'loyalty_rewards', 'loyalty_ledger'] as $table) {
            Rls::enableTenantTable($table);
        }

        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON loyalty_ledger FROM nova_app');
        DB::statement('CREATE TRIGGER loyalty_ledger_append_only BEFORE UPDATE OR DELETE ON loyalty_ledger FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER loyalty_ledger_no_truncate BEFORE TRUNCATE ON loyalty_ledger FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        $this->purgeFunction();
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_members');
        Schema::dropIfExists('loyalty_program_services');
        Schema::dropIfExists('loyalty_programs');
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus les cinq tables de fidélité (en premier : elles dépendent des rendez-vous et des clients). */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'loyalty_ledger', 'loyalty_rewards', 'loyalty_members', 'loyalty_program_services', 'loyalty_programs',
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
