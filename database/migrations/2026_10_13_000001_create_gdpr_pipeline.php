<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Étapes autorisées : miroir de App\Modules\Privacy\Support\ErasureSteps::ALL (un test les compare et les exécute). */
    private const STEPS = [
        'booking_events', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
        'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners', 'customers',
        'services', 'service_categories', 'business_hours_exceptions', 'business_hours', 'org_member_locations', 'locations',
        'api_clients', 'whatsapp_accounts', 'booking_policies', 'onboarding_progress',
    ];

    public function up(): void
    {
        // Suivi d'une suppression de compte (docs/01 : deletion_requests / deletion_jobs). Aucune donnée personnelle durable :
        // le contact du propriétaire sert uniquement au message de confirmation, puis il est effacé.
        Schema::create('deletion_requests', function (Blueprint $table): void {
            $table->uuid('org_id')->primary();
            $table->string('kind', 10);
            $table->text('owner_email_enc')->nullable();
            $table->string('owner_locale', 2)->nullable();
            $table->text('org_name_enc')->nullable();
            $table->timestampTz('scheduled_for');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('alerted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations');
        });
        DB::statement("ALTER TABLE deletion_requests ADD CONSTRAINT deletion_requests_kind_check CHECK (kind IN ('standard','immediate'))");

        Schema::create('deletion_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('step', 40);
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 60)->nullable();
            $table->unsignedBigInteger('rows_deleted')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('org_id')->on('deletion_requests');
            $table->unique(['org_id', 'step']);
        });
        DB::statement("ALTER TABLE deletion_jobs ADD CONSTRAINT deletion_jobs_status_check CHECK (status IN ('pending','running','done','failed'))");

        // Réservé au rôle plateforme : le rôle applicatif des clients n'y touche jamais.
        foreach (['deletion_requests', 'deletion_jobs'] as $table) {
            DB::statement("REVOKE ALL ON {$table} FROM nova_app");
            DB::statement("GRANT SELECT, INSERT, UPDATE ON {$table} TO nova_platform");
        }

        // Les tables en ajout seul acceptent UNE exception : la suppression d'une organisation par la fonction d'effacement
        // ci-dessous, reconnue à la fois par son rôle propriétaire et par un réglage local à la transaction.
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

        $steps = "'".implode("','", self::STEPS)."'";

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

                -- Réglage local à la transaction : levé automatiquement à la fin, valable pour cette seule organisation.
                PERFORM set_config('nova.gdpr_purge', p_org_id::text, true);

                IF p_step = 'users' THEN
                    -- Comptes qui n'appartiennent qu'à cette organisation (les autres gardent leur compte).
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
                    -- Restent : les actions du Super Admin sur ce compte et le suivi de la suppression (preuve, sans donnée de client).
                    DELETE FROM audit_logs WHERE org_id = p_org_id AND actor_type <> 'platform_admin' AND action NOT LIKE 'gdpr.%' AND action <> 'organization.deleted';
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
        DB::statement('REVOKE ALL ON FUNCTION gdpr_purge_org(uuid, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION gdpr_purge_org(uuid, text) TO nova_platform');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS gdpr_purge_org(uuid, text)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_modification() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La table % est en ajout seul : % interdit', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);
        Schema::dropIfExists('deletion_jobs');
        Schema::dropIfExists('deletion_requests');
    }
};
