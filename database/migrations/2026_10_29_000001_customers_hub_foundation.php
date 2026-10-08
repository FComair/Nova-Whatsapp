<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fondations de la fiche client enrichie (docs/21) : champs structurés, statut, référence « C-1042 », texte de recherche,
 * et consentements enregistrés un par un (journal en ajout seul).
 *
 * - `name` reste le nom affiché partout (planning, WhatsApp, API) ; `first_name` / `last_name` / `display_name` le précisent.
 *   Les noms existants sont coupés au dernier espace (« Anne Marie Dupont » → prénom « Anne Marie », nom « Dupont »).
 * - `status` (active, archived, blocked) remplace le seul drapeau `is_blocked`, qui reste lu par le moteur de réservation :
 *   une contrainte garantit que les deux disent toujours la même chose.
 * - `reference_number` : numéro par établissement attribué par la base (1001, 1002…), jamais deux fois le même.
 * - `search_text` : nom, e-mail, référence et étiquettes sans accents ni majuscules, maintenu par la base, avec un index
 *   trigramme (recherche tolérante aux fautes).
 * - `customer_consents` : qui a accepté ou retiré quoi (rendez-vous, marketing), quand et par quel moyen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->unsignedInteger('reference_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->jsonb('address')->nullable();
            $table->string('acquisition_source', 20)->nullable();
            $table->string('status', 10)->default('active');
            $table->uuid('assigned_practitioner_id')->nullable();
            $table->uuid('default_location_id')->nullable();
            $table->string('preferred_channel', 10)->nullable();
            $table->jsonb('custom')->default('{}');
            $table->text('search_text')->nullable();

            $table->foreign('assigned_practitioner_id')->references('id')->on('practitioners')->nullOnDelete();
            $table->foreign('default_location_id')->references('id')->on('locations')->nullOnDelete();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active','archived','blocked'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_blocked_status_check CHECK (is_blocked = (status = 'blocked'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_source_check CHECK (acquisition_source IS NULL OR acquisition_source IN ('whatsapp_qr','whatsapp','website','walk_in','referral','social','manual','other'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_channel_check CHECK (preferred_channel IS NULL OR preferred_channel IN ('whatsapp','phone','email'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_whatsapp_phone_check CHECK (whatsapp_phone IS NULL OR whatsapp_phone ~ '^\\+[1-9][0-9]{7,14}$')");

        // Attribution du numéro de référence et du texte de recherche, par la base : tous les chemins d'écriture sont couverts.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION customers_before_write() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.reference_number IS NULL THEN
                    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.org_id::text, 0));
                    SELECT COALESCE(MAX(reference_number), 1000) + 1 INTO NEW.reference_number FROM customers WHERE org_id = NEW.org_id;
                END IF;

                NEW.search_text := btrim(lower(unaccent(concat_ws(' ',
                    NEW.name, NEW.first_name, NEW.last_name, NEW.display_name, NEW.email,
                    'c-' || NEW.reference_number,
                    regexp_replace(COALESCE(NEW.tags::text, ''), '[\[\]"]', ' ', 'g')
                ))));

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER customers_before_write BEFORE INSERT OR UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION customers_before_write()');

        // Reprise des données existantes : la RLS s'applique aussi au propriétaire, on la suspend le temps de la reprise.
        DB::statement('ALTER TABLE customers NO FORCE ROW LEVEL SECURITY');

        DB::statement("UPDATE customers SET status = 'blocked' WHERE is_blocked");
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT id, 1000 + row_number() OVER (PARTITION BY org_id ORDER BY created_at, id) AS number FROM customers
            )
            UPDATE customers c SET reference_number = ranked.number FROM ranked WHERE ranked.id = c.id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE customers SET
                first_name = CASE WHEN position(' ' IN btrim(name)) > 0 THEN left(regexp_replace(btrim(name), ' +[^ ]+$', ''), 80) ELSE left(btrim(name), 80) END,
                last_name = CASE WHEN position(' ' IN btrim(name)) > 0 THEN left(substring(btrim(name) FROM '[^ ]+$'), 80) END
            WHERE name IS NOT NULL AND btrim(name) <> '' AND anonymized_at IS NULL
        SQL);
        // Le déclencheur recalcule le texte de recherche de chaque ligne.
        DB::statement('UPDATE customers SET updated_at = updated_at');

        DB::statement('ALTER TABLE customers ALTER COLUMN reference_number SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX customers_reference_unique ON customers (org_id, reference_number)');
        DB::statement('CREATE INDEX customers_search_trgm ON customers USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX customers_org_status_index ON customers (org_id, status)');

        Schema::create('customer_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('customer_id');
            $table->string('purpose', 12);
            $table->string('channel', 10)->default('whatsapp');
            $table->string('status', 10);
            $table->string('source', 16);
            $table->string('evidence_ref', 120)->nullable();
            // Pas de clé étrangère vers users : le journal est en ajout seul, une suppression d'utilisateur ne doit pas le modifier.
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->index(['customer_id', 'purpose', 'recorded_at']);
        });
        DB::statement("ALTER TABLE customer_consents ADD CONSTRAINT customer_consents_purpose_check CHECK (purpose IN ('appointment','marketing'))");
        DB::statement("ALTER TABLE customer_consents ADD CONSTRAINT customer_consents_channel_check CHECK (channel IN ('whatsapp','email','phone'))");
        DB::statement("ALTER TABLE customer_consents ADD CONSTRAINT customer_consents_status_check CHECK (status IN ('granted','withdrawn'))");
        DB::statement("ALTER TABLE customer_consents ADD CONSTRAINT customer_consents_source_check CHECK (source IN ('qr','conversation','form','staff','stop_reply','start_reply','import','system'))");

        // Les désabonnements WhatsApp (STOP) déjà reçus deviennent des retraits de consentement datés.
        foreach (['appointment', 'marketing'] as $purpose) {
            DB::statement(<<<SQL
                INSERT INTO customer_consents (org_id, customer_id, purpose, channel, status, source, recorded_at)
                SELECT org_id, id, '{$purpose}', 'whatsapp', 'withdrawn', 'stop_reply', whatsapp_opted_out_at
                FROM customers WHERE whatsapp_opted_out_at IS NOT NULL
            SQL);
        }

        DB::statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY');

        Rls::enableTenantTable('customer_consents');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON customer_consents FROM nova_app');
        DB::statement('CREATE TRIGGER customer_consents_append_only BEFORE UPDATE OR DELETE ON customer_consents FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER customer_consents_no_truncate BEFORE TRUNCATE ON customer_consents FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        $this->purgeFunction();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS customer_consents_no_truncate ON customer_consents');
        DB::statement('DROP TRIGGER IF EXISTS customer_consents_append_only ON customer_consents');
        Schema::dropIfExists('customer_consents');
        DB::statement('DROP TRIGGER IF EXISTS customers_before_write ON customers');
        DB::statement('DROP FUNCTION IF EXISTS customers_before_write()');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['assigned_practitioner_id']);
            $table->dropForeign(['default_location_id']);
        });

        foreach (['customers_status_check', 'customers_blocked_status_check', 'customers_source_check', 'customers_channel_check', 'customers_whatsapp_phone_check'] as $constraint) {
            DB::statement("ALTER TABLE customers DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        DB::statement('DROP INDEX IF EXISTS customers_reference_unique');
        DB::statement('DROP INDEX IF EXISTS customers_search_trgm');
        DB::statement('DROP INDEX IF EXISTS customers_org_status_index');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'display_name', 'reference_number', 'date_of_birth', 'whatsapp_phone', 'address', 'acquisition_source', 'status', 'assigned_practitioner_id', 'default_location_id', 'preferred_channel', 'custom', 'search_text']);
        });
    }

    /** Fonction d'effacement d'un établissement (RGPD) : mêmes étapes qu'avant, plus customer_consents. */
    private function purgeFunction(): void
    {
        $steps = "'".implode("','", [
            'booking_events', 'price_list_imports', 'whatsapp_messages', 'external_busy_slots', 'external_calendars', 'calendar_feeds', 'bookings',
            'practitioner_time_off', 'practitioner_schedules', 'practitioner_services', 'invitations', 'practitioners', 'customer_consents', 'customers',
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
