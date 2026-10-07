<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Modification des informations de l'entreprise (nom, secteur, pays, fuseau, langue), depuis l'assistant d'onboarding comme
 * depuis Réglages > Profil. Chaque changement est tracé avec l'ancienne et la nouvelle valeur.
 */
final class CompanyUpdater
{
    /**
     * @param  array{name: string, sector: string, country: string, timezone: string, locale: string}  $data
     */
    public function update(Organization $organization, array $data, ?string $actorId): void
    {
        $previousTimezone = $organization->timezone;

        DB::transaction(function () use ($organization, $data, $previousTimezone, $actorId): void {
            $organization->fill($data);
            $diff = collect($organization->getDirty())->map(fn ($new, string $key): array => [$organization->getOriginal($key), $new])->all();
            $organization->save();

            // Les sites qui suivaient le fuseau de l'entreprise le suivent encore.
            if ($organization->timezone !== $previousTimezone) {
                Location::query()->where('timezone', $previousTimezone)->update(['timezone' => $organization->timezone]);
            }

            if ($diff !== []) {
                Audit::record('org.company.updated', 'user', actorId: $actorId, orgId: $organization->id, entityType: 'organization', entityId: $organization->id, diff: $diff);
            }
        });
    }
}
