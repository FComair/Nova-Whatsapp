<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Whatsapp\Support\MessageTemplateLibrary;
use App\Modules\Whatsapp\Support\MessageTemplateStatus;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importe la bibliothèque de départ (MessageTemplateLibrary) : un jeu complet de modèles, en BROUILLON, pour chaque secteur demandé.
 *
 * Idempotent : un modèle qui existe déjà (même secteur, même type) n'est jamais écrasé, même modifié ou approuvé. Importer un secteur
 * n'écrit que dans ce secteur. Un secteur sans jeu propre reçoit le jeu générique « Autre ». Utilisé par la migration d'import
 * (sans administrateur) et par l'action « Importer le jeu standard » du Super Admin.
 */
final class MessageTemplateImporter
{
    /**
     * @param  string|null  $sectorCode  null : tous les secteurs
     * @return array{created: int, skipped: int}
     */
    public function import(?string $sectorCode, ?string $adminId = null, ?string $connection = null): array
    {
        if ($adminId !== null) {
            AdminAccess::authorize($adminId, 'settings.edit');
        }

        $db = DB::connection($connection ?? PlatformAudit::CONNECTION);
        $codes = $sectorCode !== null ? [$sectorCode] : $db->table('sectors')->orderBy('code')->pluck('code')->all();
        $created = 0;
        $skipped = 0;

        foreach ($codes as $code) {
            if (! $db->table('sectors')->where('code', $code)->exists()) {
                continue;
            }

            $db->transaction(function () use ($db, $code, $adminId, &$created, &$skipped): void {
                foreach (MessageTemplateLibrary::TYPES as $type) {
                    if ($db->table('message_templates')->where('sector_code', $code)->where('type', $type)->exists()) {
                        $skipped++;

                        continue;
                    }

                    $this->insert($db, $code, $type, $adminId);
                    $created++;
                }
            });
        }

        if ($adminId !== null) {
            PlatformAudit::record($adminId, 'whatsapp_template.imported', null, 'sectors', null, ['sector' => $sectorCode ?? 'all', 'created' => $created, 'skipped' => $skipped]);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function insert(Connection $db, string $sector, string $type, ?string $adminId): void
    {
        $id = (string) Str::uuid7();
        $name = (string) trans("message_templates.types.{$type}", [], 'fr');
        $bodies = [];

        $db->table('message_templates')->insert([
            'id' => $id,
            'sector_code' => $sector,
            'type' => $type,
            'slug' => $type,
            'name' => $name,
            'meta_name' => Str::limit('nova_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($sector.'_'.$type)), 120, ''),
            'category' => MessageTemplateType::category($type),
            'status' => MessageTemplateStatus::DRAFT,
            'is_enabled' => true,
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ((array) config('nova.locales') as $language) {
            $bodies[$language] = MessageTemplateLibrary::body($sector, $type, (string) $language);
            $db->table('message_template_translations')->insert([
                'id' => (string) Str::uuid7(), 'template_id' => $id, 'language' => $language, 'body' => $bodies[$language], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $db->table('message_template_events')->insert([
            'id' => (string) Str::uuid7(),
            'template_id' => $id,
            'sector_code' => $sector,
            'template_name' => $name,
            'action' => 'imported',
            'from_status' => null,
            'to_status' => MessageTemplateStatus::DRAFT,
            'reason' => null,
            'actor_id' => $adminId,
            'snapshot' => json_encode(['name' => $name, 'type' => $type, 'category' => MessageTemplateType::category($type), 'is_enabled' => true, 'translations' => $bodies], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
