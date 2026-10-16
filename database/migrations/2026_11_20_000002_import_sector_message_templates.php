<?php

declare(strict_types=1);

use App\Modules\Admin\Services\MessageTemplateImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import initial de la bibliothèque de messages WhatsApp : un jeu complet par secteur, en brouillon (rien n'est approuvé tant que le
 * Super Admin ne l'a pas fait). Les secteurs créés plus tard s'importent depuis le Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(MessageTemplateImporter::class)->import(null, null, DB::getDefaultConnection());
    }

    public function down(): void
    {
        DB::table('message_templates')->delete();
    }
};
