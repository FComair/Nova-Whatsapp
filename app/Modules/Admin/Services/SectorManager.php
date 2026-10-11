<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Tenancy\Support\DefaultSectors;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestion des secteurs d'activité par le Super Admin : création, modification, activation, désactivation, suppression.
 *
 * - Le nom saisi est nettoyé (espaces) et doit être unique sans tenir compte des majuscules ni des accents.
 * - Le code (technique, jamais modifié) est fabriqué une fois à la création ; les établissements gardent ce code.
 * - Désactiver ne touche pas aux établissements : le secteur n'est simplement plus proposé aux nouveaux.
 * - Supprimer déplace les établissements du secteur vers le secteur par défaut (« other »), qui ne peut être ni supprimé ni désactivé.
 * Chaque changement est tracé dans le journal d'audit.
 */
final class SectorManager
{
    public const MIN_NAME = 2;

    public const MAX_NAME = 60;

    /**
     * @param  array{name: string, is_active?: bool, notes?: string|null, name_fr?: string|null, name_nl?: string|null, name_en?: string|null}  $data
     */
    public function create(array $data, string $adminId): PlatformSector
    {
        AdminAccess::authorize($adminId, 'settings.edit');

        return DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($data, $adminId): PlatformSector {
            $fields = $this->fields($data);
            $sector = PlatformSector::query()->create([
                ...$fields,
                'code' => $this->newCode($fields['name']),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => false,
            ]);

            PlatformAudit::record($adminId, 'sector.created', null, 'sectors', $sector->id, ['name' => $sector->name, 'code' => $sector->code, 'is_active' => $sector->is_active]);

            return $sector;
        });
    }

    /**
     * @param  array{name: string, is_active?: bool, notes?: string|null, name_fr?: string|null, name_nl?: string|null, name_en?: string|null}  $data
     */
    public function update(PlatformSector $sector, array $data, string $adminId): void
    {
        AdminAccess::authorize($adminId, 'settings.edit');
        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($sector, $data, $adminId): void {
            $sector->fill($this->fields($data));

            // Le secteur par défaut reste toujours actif ; pour les autres, l'état se règle ici comme avec activer / désactiver.
            if (! $sector->is_default && array_key_exists('is_active', $data)) {
                $sector->is_active = (bool) $data['is_active'];
            }

            $diff = collect($sector->getDirty())->except(['updated_at', 'name_key'])->map(fn ($new, string $key): array => [$sector->getOriginal($key), $new])->all();

            if ($diff === []) {
                return;
            }

            $sector->save();
            PlatformAudit::record($adminId, 'sector.updated', null, 'sectors', $sector->id, $diff);

            if (isset($diff['is_active'])) {
                PlatformAudit::record($adminId, $sector->is_active ? 'sector.activated' : 'sector.deactivated', null, 'sectors', $sector->id, ['merchants' => $this->merchants($sector)]);
            }
        });
    }

    public function setActive(PlatformSector $sector, bool $active, string $adminId): void
    {
        AdminAccess::authorize($adminId, 'settings.edit');
        if ($sector->is_default && ! $active) {
            throw new DomainException('Le secteur par défaut ne peut pas être désactivé.');
        }

        if ($sector->is_active === $active) {
            return;
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($sector, $active, $adminId): void {
            $sector->forceFill(['is_active' => $active])->save();
            PlatformAudit::record($adminId, $active ? 'sector.activated' : 'sector.deactivated', null, 'sectors', $sector->id, [
                'name' => $sector->name,
                'is_active' => [! $active, $active],
                'merchants' => $this->merchants($sector),
            ]);
        });
    }

    /**
     * Supprime le secteur ; ses établissements passent dans le secteur par défaut.
     *
     * @return int nombre d'établissements déplacés
     */
    public function delete(PlatformSector $sector, string $adminId): int
    {
        AdminAccess::authorize($adminId, 'settings.edit');
        if ($sector->is_default) {
            throw new DomainException('Le secteur par défaut ne peut pas être supprimé.');
        }

        return DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($sector, $adminId): int {
            $moved = DB::connection(PlatformAudit::CONNECTION)->table('organizations')
                ->where('sector', $sector->code)
                ->update(['sector' => DefaultSectors::FALLBACK, 'updated_at' => now()]);

            $sector->delete();

            PlatformAudit::record($adminId, 'sector.deleted', null, 'sectors', $sector->id, [
                'name' => $sector->name,
                'code' => $sector->code,
                'merchants_moved' => $moved,
                'moved_to' => DefaultSectors::FALLBACK,
            ]);

            return $moved;
        });
    }

    /** Nombre d'établissements dans ce secteur. */
    public function merchants(PlatformSector $sector): int
    {
        return DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('sector', $sector->code)->count();
    }

    /** Un autre secteur porte-t-il déjà ce nom (sans tenir compte des majuscules ni des accents) ? */
    public function nameTaken(string $name, ?string $ignoreId = null): bool
    {
        return PlatformSector::query()
            ->where('name_key', DefaultSectors::key($name))
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * Champs saisis, nettoyés : espaces simplifiés, traductions vides remplacées par « aucune ».
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, name_key: string, name_fr: string|null, name_nl: string|null, name_en: string|null, notes: string|null}
     */
    private function fields(array $data): array
    {
        $name = (string) Str::of((string) $data['name'])->squish();
        $clean = fn (?string $value): ?string => filled($value) ? (string) Str::of((string) $value)->squish() : null;

        return [
            'name' => $name,
            'name_key' => DefaultSectors::key($name),
            'name_fr' => $clean($data['name_fr'] ?? null),
            'name_nl' => $clean($data['name_nl'] ?? null),
            'name_en' => $clean($data['name_en'] ?? null),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }

    /** Code technique à partir du nom (« Food & Beverage » → « food_beverage »), rendu unique au besoin. */
    private function newCode(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 44, '') ?: 'sector';
        $code = $base;
        $suffix = 2;

        while (PlatformSector::query()->where('code', $code)->exists()) {
            $code = "{$base}_{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
