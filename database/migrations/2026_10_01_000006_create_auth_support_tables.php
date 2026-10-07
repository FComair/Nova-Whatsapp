<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jetons de réinitialisation de mot de passe (usage unique, durée définie dans config/auth.php).
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // Consentements du compte utilisateur, avec date, version du document et source (docs/01 §1, flowcharts 1A et 3B).
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->string('type', 30);
            $table->string('document_version', 30)->nullable();
            $table->timestampTz('granted_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('source', 50);
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'type']);
        });
        DB::statement("ALTER TABLE user_consents ADD CONSTRAINT user_consents_type_check CHECK (type IN ('terms','privacy','dpa','nova_news','product_improvement'))");

        // Plan souhaité à l'inscription (essai gratuit ou plan choisi). Les plans eux-mêmes arrivent avec la facturation.
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('signup_plan', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('signup_plan');
        });
        Schema::dropIfExists('user_consents');
        Schema::dropIfExists('password_reset_tokens');
    }
};
