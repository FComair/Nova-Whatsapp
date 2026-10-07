<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Sector;
use Illuminate\Support\Collection;

/**
 * Secteurs proposés aux établissements (inscription, assistant, profil) : seulement les secteurs actifs.
 *
 * Un établissement déjà dans un secteur désactivé le garde : `$keep` l'ajoute à la liste pour qu'il puisse le voir et le conserver.
 */
final class SectorCatalog
{
    /**
     * Choix d'un menu déroulant, triés par nom dans la langue demandée, « Autre » (secteur par défaut) en dernier.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(string $locale, ?string $keep = null): array
    {
        return $this->available($keep)
            ->sortBy(fn (Sector $sector): string => ($sector->is_default ? '1' : '0').mb_strtolower($sector->label($locale)))
            ->map(fn (Sector $sector): array => ['value' => $sector->code, 'label' => $sector->label($locale)])
            ->values()
            ->all();
    }

    /**
     * Codes acceptés à la validation d'un formulaire.
     *
     * @return list<string>
     */
    public function codes(?string $keep = null): array
    {
        return $this->available($keep)->pluck('code')->values()->all();
    }

    /**
     * @return Collection<int, Sector>
     */
    private function available(?string $keep): Collection
    {
        return Sector::query()->where(fn ($query) => $query->where('is_active', true)->when($keep !== null, fn ($inner) => $inner->orWhere('code', $keep)))->get();
    }
}
