<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QR codes WhatsApp d'un établissement. Seuls le nom, le message pré-rempli et le style sont enregistrés : l'image est recalculée
 * à chaque demande à partir du numéro connecté, donc un code ne périme jamais. Le premier code est le code principal (non supprimable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_qr_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('name', 60);
            $table->string('message', 200)->nullable();
            $table->string('color', 7)->default('#0B1220');
            $table->boolean('show_logo')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });

        Rls::enableTenantTable('whatsapp_qr_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_qr_codes');
    }
};
