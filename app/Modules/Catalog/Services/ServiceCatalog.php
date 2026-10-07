<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Audit;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Support\SectorTemplates;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catalogue de services de l'organisation courante (docs/01 §4.3). Toute écriture est tracée dans le journal d'audit.
 */
final class ServiceCatalog
{
    /**
     * @param  array{name: string, category?: string|null, description?: string|null, duration_minutes: int, buffer_before_minutes: int, buffer_after_minutes: int, price_cents: int, vat_rate: int, capacity: int, bookable_online: bool, notes_required: bool}  $data
     */
    public function create(Organization $organization, array $data): Service
    {
        return DB::transaction(function () use ($organization, $data): Service {
            $service = Service::query()->create([
                ...$this->attributes($organization, $data),
                'org_id' => $organization->id,
                'position' => (int) Service::query()->max('position') + 1,
            ]);

            Audit::record('service.created', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'service', entityId: $service->id, diff: ['name' => $service->name]);

            return $service;
        });
    }

    /**
     * @param  array{name: string, category?: string|null, description?: string|null, duration_minutes: int, buffer_before_minutes: int, buffer_after_minutes: int, price_cents: int, vat_rate: int, capacity: int, bookable_online: bool, notes_required: bool}  $data
     */
    public function update(Organization $organization, Service $service, array $data): Service
    {
        return DB::transaction(function () use ($organization, $service, $data): Service {
            $service->fill($this->attributes($organization, $data));
            $diff = collect($service->getDirty())->map(fn ($new, string $key): array => [$service->getOriginal($key), $new])->all();
            $service->save();

            if ($diff !== []) {
                Audit::record('service.updated', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'service', entityId: $service->id, diff: $diff);
            }

            return $service;
        });
    }

    public function delete(Organization $organization, Service $service): void
    {
        $service->delete();

        Audit::record('service.deleted', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'service', entityId: $service->id, diff: ['name' => $service->name]);
    }

    /**
     * Crée les services du modèle sectoriel (flowchart 1B). Refusé si le catalogue n'est pas vide : un modèle ne doit
     * jamais écraser ni dupliquer des services déjà saisis.
     *
     * @return int nombre de services créés
     */
    public function applyTemplate(Organization $organization): int
    {
        if (Service::query()->exists()) {
            throw new RuntimeException('Le catalogue contient déjà des services.');
        }

        $templates = SectorTemplates::services((string) $organization->sector, $organization->locale);

        DB::transaction(function () use ($organization, $templates): void {
            foreach ($templates as $position => $template) {
                Service::query()->create([
                    'org_id' => $organization->id,
                    'category_id' => $this->category($organization, $template['category'])?->id,
                    'name' => $template['name'],
                    'duration_minutes' => $template['duration'],
                    'price_cents' => $template['price'],
                    'vat_rate' => $template['vat'],
                    'position' => $position + 1,
                ]);
            }

            Audit::record('services.template_applied', 'user', actorId: auth()->id(), orgId: $organization->id, diff: ['sector' => $organization->sector, 'count' => count($templates)]);
        });

        return count($templates);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Organization $organization, array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => $this->category($organization, $data['category'] ?? null)?->id,
            'duration_minutes' => $data['duration_minutes'],
            'buffer_before_minutes' => $data['buffer_before_minutes'],
            'buffer_after_minutes' => $data['buffer_after_minutes'],
            'price_cents' => $data['price_cents'],
            'vat_rate' => $data['vat_rate'],
            'capacity' => $data['capacity'],
            'bookable_online' => $data['bookable_online'],
            'notes_required' => $data['notes_required'],
        ];
    }

    /** Catégorie retrouvée par son nom (sans tenir compte de la casse) ou créée. */
    private function category(Organization $organization, ?string $name): ?ServiceCategory
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            return null;
        }

        return ServiceCategory::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? ServiceCategory::query()->create([
                'org_id' => $organization->id,
                'name' => $name,
                'position' => (int) ServiceCategory::query()->max('position') + 1,
            ]);
    }
}
