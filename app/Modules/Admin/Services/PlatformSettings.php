<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Paramètres globaux de la plateforme (table `platform_settings`) : durées et seuils, jamais en dur dans le code.
 * Lecture par tous les rôles applicatifs ; écriture réservée au Super Admin (rôle `nova_platform`).
 */
final class PlatformSettings
{
    /** Réglages modifiables, avec leurs bornes (jours). */
    public const INTEGERS = [
        'deletion_delay_days' => ['min' => 1, 'max' => 365],
        'pause_max_days' => ['min' => 1, 'max' => 365],
        'suspension_max_days' => ['min' => 1, 'max' => 365],
        'trial_days' => ['min' => 1, 'max' => 90],
        'trial_reminder_days' => ['min' => 1, 'max' => 30],
        'dunning_retry_days' => ['min' => 1, 'max' => 30],
        'dunning_max_attempts' => ['min' => 1, 'max' => 10],
    ];

    public function integer(string $key): int
    {
        $value = DB::table('platform_settings')->where('key', $key)->value('value');

        return $value === null ? throw new \RuntimeException("Paramètre manquant : {$key}") : (int) json_decode((string) $value, true);
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $values = DB::connection(PlatformAudit::CONNECTION)->table('platform_settings')->pluck('value', 'key');

        return collect(self::INTEGERS)->mapWithKeys(fn (array $bounds, string $key): array => [$key => (int) json_decode((string) ($values[$key] ?? 'null'), true)])->all();
    }

    /**
     * @param  array<string, int>  $new
     * @return array<string, array{0: int, 1: int}> changements : ancienne et nouvelle valeur
     */
    public function update(array $new, string $adminId): array
    {
        $old = $this->all();
        $changes = [];

        foreach (self::INTEGERS as $key => $bounds) {
            $value = $new[$key] ?? $old[$key];

            if ($value < $bounds['min'] || $value > $bounds['max']) {
                throw new \InvalidArgumentException("Valeur hors bornes : {$key}");
            }

            if ($value !== $old[$key]) {
                DB::connection(PlatformAudit::CONNECTION)->table('platform_settings')->where('key', $key)->update(['value' => (string) $value, 'updated_by' => $adminId, 'updated_at' => now()]);
                $changes[$key] = [$old[$key], $value];
            }
        }

        return $changes;
    }
}
