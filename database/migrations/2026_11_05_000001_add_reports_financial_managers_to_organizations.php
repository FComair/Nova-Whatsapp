<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réglage du propriétaire : les responsables de site voient-ils les rapports financiers ? (docs/02 §1, « selon réglage
 * propriétaire »). Désactivé par défaut : un responsable ne voit les montants que si le propriétaire l'a décidé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE organizations ADD COLUMN reports_financial_managers boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP COLUMN IF EXISTS reports_financial_managers');
    }
};
