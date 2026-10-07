<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formule facturée, figée à l'émission comme l'identité du vendeur et du client : la formule d'un abonnement change (montée ou
 * descente de gamme), une facture ne doit pas changer avec elle. Nulle pour les factures émises avant cette colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('plan_name', 60)->nullable()->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('plan_name');
        });
    }
};
