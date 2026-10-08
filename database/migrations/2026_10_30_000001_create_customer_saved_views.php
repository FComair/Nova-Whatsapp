<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vues enregistrées de la liste des clients (docs/21 §2) : un nom, les filtres, les colonnes et le tri.
 * Une vue est personnelle ou partagée avec toute l'équipe de l'établissement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('name', 60);
            $table->jsonb('filters')->default('{}');
            $table->jsonb('columns')->nullable();
            $table->string('sort', 20)->nullable();
            $table->string('direction', 4)->nullable();
            $table->boolean('shared')->default(false);
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'shared']);
        });
        DB::statement("ALTER TABLE customer_saved_views ADD CONSTRAINT customer_saved_views_direction_check CHECK (direction IS NULL OR direction IN ('asc','desc'))");
        Rls::enableTenantTable('customer_saved_views');

        $this->purgeFunction();
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_saved_views');
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus customer_saved_views. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'booking_events', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners', 'customer_saved_views', 'customer_consents', 'customers',
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
