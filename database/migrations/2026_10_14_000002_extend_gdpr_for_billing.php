<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Étapes « suppression » : miroir de App\Modules\Privacy\Support\ErasureSteps (un test les compare et les exécute). */
    private const STEPS = [
        'booking_events', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
        'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners', 'customers',
        'services', 'service_categories', 'business_hours_exceptions', 'business_hours', 'org_member_locations', 'locations',
        'api_clients', 'whatsapp_accounts', 'booking_policies', 'onboarding_progress', 'dunning_events',
    ];

    public function up(): void
    {
        $steps = "'".implode("','", self::STEPS)."'";

        // Facturation : les factures, paiements et abonnements sont conservés 7 ans (obligation comptable) ; ils ne contiennent
        // aucun jeton de carte. Les factures sont PSEUDONYMISÉES (identité du client effacée), les moyens de paiement supprimés.
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
                    -- L'abonnement (conservé) ne doit plus pointer vers un moyen de paiement ; le jeton chez le prestataire est à révoquer par lui.
                    UPDATE subscriptions SET payment_method_id = NULL, status = 'canceled', cancel_at = now() WHERE org_id = p_org_id;
                    DELETE FROM payment_methods WHERE org_id = p_org_id;
                ELSIF p_step = 'invoices' THEN
                    -- Conservation légale : le numéro, les dates et les montants restent ; l'identité du client est effacée.
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

    public function down(): void
    {
        // La version précédente de la fonction est recréée par le rollback de 2026_10_13_000001.
    }
};
