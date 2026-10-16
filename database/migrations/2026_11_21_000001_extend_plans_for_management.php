<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion complète des formules par le Super Admin (docs/40).
 *
 * `plans` reste la table de référence des abonnements (`subscriptions.plan_id`, `organizations.plan_id`) : on l'étend, on ne la remplace pas.
 * - Prix : `price_cents` est le montant facturé PAR PÉRIODE (`billing_interval_months` mois) ; `price_monthly_cents` devient l'équivalent
 *   mensuel (affichage et comparaison monter / descendre de formule).
 * - Versions : une formule qui a des abonnés n'est jamais modifiée commercialement sur place ; une nouvelle version est créée
 *   (`family_id`, `version`) et l'ancienne, `superseded_by`, garde son prix et ses limites pour ses abonnés.
 * - `status` remplace `is_active` (conservé et tenu à jour : actif = vrai) ; la disponibilité réelle tient aussi aux dates et à la visibilité.
 * - Le rôle plateforme peut désormais écrire dans `plans` ; l'application cliente reste en lecture seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->uuid('family_id')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->uuid('superseded_by')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('plan_type', 12)->default('monthly');
            $table->string('billing_frequency', 10)->default('monthly');
            $table->unsignedSmallInteger('billing_interval_months')->default(1);
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('setup_fee_cents')->default(0);
            $table->string('tax_mode', 10)->default('excluded');
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->text('internal_description')->nullable();
            $table->jsonb('public_description')->nullable();
            $table->jsonb('features')->default('[]');
            $table->string('visibility', 12)->default('public');
            $table->string('invite_code', 40)->nullable();
            $table->jsonb('segments')->nullable();
            $table->string('cta_type', 16)->default('choose_plan');
            $table->string('cta_url', 255)->nullable();
            $table->timestampTz('available_from')->nullable();
            $table->timestampTz('available_until')->nullable();
        });

        DB::statement("UPDATE plans SET family_id = id, price_cents = price_monthly_cents, status = CASE WHEN is_active THEN 'active' ELSE 'inactive' END");

        Schema::table('plans', function (Blueprint $table): void {
            $table->uuid('family_id')->nullable(false)->change();
            $table->foreign('superseded_by')->references('id')->on('plans')->nullOnDelete();
            $table->index(['family_id', 'version']);
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('draft','active','inactive','archived'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_type_check CHECK (plan_type IN ('free_trial','monthly','annual','custom','one_time'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_frequency_check CHECK (billing_frequency IN ('monthly','quarterly','yearly','custom'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_interval_check CHECK (billing_interval_months BETWEEN 1 AND 60)');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_tax_mode_check CHECK (tax_mode IN ('excluded','included'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_visibility_check CHECK (visibility IN ('public','private','invite_only','segments'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_cta_check CHECK (cta_type IN ('choose_plan','start_trial','contact_sales'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_window_check CHECK (available_until IS NULL OR available_from IS NULL OR available_until > available_from)');
        DB::statement('CREATE UNIQUE INDEX plans_invite_code_unique ON plans (invite_code) WHERE invite_code IS NOT NULL');

        DB::statement('GRANT INSERT, UPDATE, DELETE ON plans TO nova_platform');

        // Compatibilité : les anciennes colonnes (`is_active`, `price_monthly_cents`) restent utilisables telles quelles par le code et les
        // outils existants ; ce déclencheur garde `status` et `price_cents` d'accord avec elles (et inversement).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plans_sync_legacy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.family_id IS NULL THEN
                        NEW.family_id := NEW.id;
                    END IF;
                    IF NEW.price_cents = 0 AND NEW.price_monthly_cents > 0 THEN
                        NEW.price_cents := NEW.price_monthly_cents * NEW.billing_interval_months;
                    END IF;
                    IF NEW.status IN ('active', 'inactive') THEN
                        NEW.status := CASE WHEN NEW.is_active THEN 'active' ELSE 'inactive' END;
                    ELSE
                        NEW.is_active := false;
                    END IF;
                ELSE
                    IF NEW.status IS DISTINCT FROM OLD.status THEN
                        NEW.is_active := (NEW.status = 'active');
                    ELSIF NEW.is_active IS DISTINCT FROM OLD.is_active AND OLD.status IN ('active', 'inactive') THEN
                        NEW.status := CASE WHEN NEW.is_active THEN 'active' ELSE 'inactive' END;
                    END IF;
                    IF NEW.price_monthly_cents IS DISTINCT FROM OLD.price_monthly_cents AND NEW.price_cents = OLD.price_cents THEN
                        NEW.price_cents := NEW.price_monthly_cents * NEW.billing_interval_months;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER plans_sync_legacy BEFORE INSERT OR UPDATE ON plans FOR EACH ROW EXECUTE FUNCTION plans_sync_legacy()');

        // Migration explicite des abonnés d'une formule vers une autre : le changement est PROGRAMMÉ à leur prochaine échéance
        // (`pending_plan_id`, mécanisme existant), jamais appliqué d'un coup. Les abonnements sont isolés par établissement : le rôle
        // plateforme passe par cette fonction plutôt que d'écrire dans la table.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plan_schedule_migration(p_from uuid, p_to uuid) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public
            AS $$
            DECLARE
                v_count integer;
            BEGIN
                IF p_from IS NULL OR p_to IS NULL OR p_from = p_to THEN
                    RAISE EXCEPTION 'Formules invalides';
                END IF;

                UPDATE subscriptions SET pending_plan_id = p_to, updated_at = now()
                 WHERE plan_id = p_from AND status IN ('active', 'past_due');
                GET DIAGNOSTICS v_count = ROW_COUNT;

                RETURN v_count;
            END;
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION plan_schedule_migration(uuid, uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION plan_schedule_migration(uuid, uuid) TO nova_platform');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS plan_schedule_migration(uuid, uuid)');
        DB::statement('DROP TRIGGER IF EXISTS plans_sync_legacy ON plans');
        DB::statement('DROP FUNCTION IF EXISTS plans_sync_legacy()');
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON plans FROM nova_platform');
        DB::statement('DROP INDEX IF EXISTS plans_invite_code_unique');

        foreach (['status', 'type', 'frequency', 'interval', 'tax_mode', 'visibility', 'cta', 'window'] as $check) {
            DB::statement("ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_{$check}_check");
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropForeign(['superseded_by']);
            $table->dropIndex(['family_id', 'version']);
            $table->dropColumn([
                'family_id', 'version', 'superseded_by', 'status', 'plan_type', 'billing_frequency', 'billing_interval_months', 'price_cents', 'setup_fee_cents',
                'tax_mode', 'trial_days', 'internal_description', 'public_description', 'features', 'visibility', 'invite_code', 'segments', 'cta_type', 'cta_url',
                'available_from', 'available_until',
            ]);
        });
    }
};
