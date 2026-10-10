<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;

/**
 * Ce que l'utilisateur connecté peut voir dans les rapports (docs/02 §1) :
 * - propriétaire : tout ;
 * - responsable de site : activité, clients et praticiens de ses sites ; les montants seulement si le propriétaire l'a autorisé ;
 * - praticien : uniquement ses propres rendez-vous, sans montants ni comparaison avec ses collègues ;
 * - réceptionniste : aucun rapport.
 * Les mêmes règles valent pour les graphiques, les tableaux, les exports et la version imprimée.
 */
final class ReportAccess
{
    public const OVERVIEW = 'overview';

    public const APPOINTMENTS = 'appointments';

    public const CUSTOMERS = 'customers';

    public const FINANCIAL = 'financial';

    public const PRACTITIONERS = 'practitioners';

    /** Catégories d'un rapport, dans l'ordre d'affichage (la vue d'ensemble n'est pas une catégorie). */
    public const CATEGORIES = [self::APPOINTMENTS, self::CUSTOMERS, self::FINANCIAL, self::PRACTITIONERS];

    public function __construct(private readonly CurrentTenant $current, private readonly PlanningAccess $planning) {}

    public function role(): string
    {
        return $this->current->role() ?? abort(403);
    }

    public function canView(): bool
    {
        return $this->current->can('reports.view');
    }

    /** Chiffres limités à ses propres rendez-vous : sans la permission « voir toute l'activité » (le cas du praticien). */
    public function isPractitioner(): bool
    {
        return ! $this->current->can('reports.all_data');
    }

    public function isOwner(): bool
    {
        return $this->role() === OrgMember::ROLE_OWNER;
    }

    /** Montants (valeur des rendez-vous, encaissements). */
    public function canSeeFinancial(): bool
    {
        return $this->current->can('reports.financial');
    }

    /** Export CSV / Excel : propriétaire et responsable de site (docs/02 §1). L'impression reste ouverte à qui voit le rapport. */
    public function canExport(): bool
    {
        return $this->current->can('reports.export');
    }

    /** Détail par praticien (tableau de comparaison, répartition des montants) : jamais pour un praticien. */
    public function canCompareStaff(): bool
    {
        return $this->current->can('reports.staff');
    }

    public function canConfigure(): bool
    {
        return $this->current->can('reports.configure');
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return array_values(array_filter(
            self::CATEGORIES,
            fn (string $category): bool => $category !== self::FINANCIAL || $this->canSeeFinancial(),
        ));
    }

    public function canViewCategory(string $category): bool
    {
        return $category === self::OVERVIEW || in_array($category, $this->categories(), true);
    }

    /** Fiche praticien imposée (rôle praticien) : tous les chiffres se limitent à ses rendez-vous. */
    public function practitionerId(): ?string
    {
        return $this->isPractitioner() ? ($this->planning->practitionerId() ?? '00000000-0000-0000-0000-000000000000') : null;
    }

    /**
     * @return list<string>|null null = tous les sites
     */
    public function locationIds(): ?array
    {
        if ($this->isOwner() || $this->isPractitioner()) {
            return null;
        }

        $ids = $this->planning->locations()->pluck('id')->map(fn ($id): string => (string) $id)->all();

        // Un responsable sans site attribué voit tous les sites (même hypothèse que le planning).
        $all = Location::query()->count();

        return count($ids) === $all ? null : array_values($ids);
    }

    /**
     * Sites que l'utilisateur peut choisir dans le filtre.
     *
     * @return list<array{id: string, name: string}>
     */
    public function visibleLocations(): array
    {
        return $this->planning->locations()->map(fn (Location $location): array => ['id' => (string) $location->id, 'name' => (string) $location->name])->values()->all();
    }
}
