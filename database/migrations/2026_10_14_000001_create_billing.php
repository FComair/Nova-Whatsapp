<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappel de fin d'essai déjà envoyé (une seule fois).
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestampTz('trial_reminder_sent_at')->nullable();
        });

        // Abonnement : un par organisation (docs/01 §9). La descente de formule est appliquée à l'échéance (pending_plan_id).
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id')->unique();
            $table->uuid('plan_id');
            $table->uuid('pending_plan_id')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('provider', 20);
            $table->uuid('payment_method_id')->nullable();
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->timestampTz('cancel_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->foreign('pending_plan_id')->references('id')->on('plans');
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','past_due','canceled'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_check CHECK (current_period_end > current_period_start)');

        // Moyen de paiement : uniquement le jeton du prestataire et de quoi le reconnaître. AUCUNE donnée de carte.
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('provider', 20);
            $table->text('provider_ref_enc');
            $table->string('brand', 20)->nullable();
            $table->char('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
        });

        // Factures : numérotation séquentielle sans trou, identités figées au moment de l'émission, conservation légale de 7 ans
        // (pseudonymisées, jamais supprimées, quand le compte est effacé).
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('number', 30)->unique();
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('status', 15)->default('open');
            $table->char('currency', 3)->default('EUR');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedSmallInteger('vat_rate');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->jsonb('seller_snapshot');
            $table->jsonb('billing_snapshot');
            $table->timestampTz('issued_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('pseudonymised_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['org_id', 'issued_at']);
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('open','paid','void','uncollectible'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_check CHECK (total_cents = subtotal_cents + vat_cents)');

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->string('description', 255);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_cents');
            $table->unsignedInteger('total_cents');

            $table->foreign('org_id')->references('id')->on('organizations');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->string('provider', 20);
            $table->string('provider_ref', 100)->nullable();
            $table->string('status', 10);
            $table->unsignedInteger('amount_cents');
            $table->string('failure_code', 40)->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations');
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->index('invoice_id');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('succeeded','failed'))");

        // Relances d'impayé (erreur E7) : une ligne par message envoyé.
        Schema::create('dunning_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->unsignedSmallInteger('attempt');
            $table->string('channel', 10)->default('email');
            $table->timestampTz('sent_at');

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices');
        });

        foreach (['subscriptions', 'payment_methods', 'invoices', 'invoice_lines', 'payments', 'dunning_events'] as $table) {
            Rls::enableTenantTable($table);
        }

        // Numéro de facture : compteur global par année, accessible uniquement par cette fonction (verrou de ligne, donc sans trou
        // tant que la facture est validée dans la même transaction).
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
        DB::statement('REVOKE ALL ON invoice_sequences FROM nova_app');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION next_invoice_number(p_year int) RETURNS text
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public
            AS $$
            DECLARE v_number int;
            BEGIN
                INSERT INTO invoice_sequences (year, last_number) VALUES (p_year, 1)
                ON CONFLICT (year) DO UPDATE SET last_number = invoice_sequences.last_number + 1
                RETURNING last_number INTO v_number;

                RETURN 'NOVA-' || p_year || '-' || lpad(v_number::text, 6, '0');
            END;
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION next_invoice_number(int) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION next_invoice_number(int) TO nova_app');

        // Paramètres globaux (jamais de durée en dur) : essai, relances d'impayé. VALEURS PAR DÉFAUT = HYPOTHÈSES à valider.
        DB::table('platform_settings')->insert([
            ['key' => 'trial_days', 'value' => '14', 'updated_at' => now()],
            ['key' => 'dunning_retry_days', 'value' => '3', 'updated_at' => now()],
            ['key' => 'dunning_max_attempts', 'value' => '3', 'updated_at' => now()],
            ['key' => 'trial_reminder_days', 'value' => '3', 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', ['trial_days', 'dunning_retry_days', 'dunning_max_attempts', 'trial_reminder_days'])->delete();
        DB::statement('DROP FUNCTION IF EXISTS next_invoice_number(int)');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('dunning_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['payment_method_id']);
        });
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('subscriptions');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('trial_reminder_sent_at');
        });
    }
};
