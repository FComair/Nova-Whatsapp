<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\Organization;

/**
 * Organisation, rôle et permissions de l'utilisateur pour la requête en cours (posés par SetTenantContext).
 *
 * Les permissions effectives viennent du rôle de base et des écarts réglés par le propriétaire (docs/34) ; `can()` est la seule porte
 * d'entrée pour décider d'un droit, côté serveur.
 */
final class CurrentTenant
{
    private ?Organization $organization = null;

    private ?string $role = null;

    /** @var array<string, bool>|null */
    private ?array $overrides = null;

    /** @var array<string, true>|null */
    private ?array $granted = null;

    /**
     * @param  array<string, bool>|null  $overrides  écarts de permissions du membre (null = défauts du rôle)
     */
    public function set(Organization $organization, string $role, ?array $overrides = null): void
    {
        $this->organization = $organization;
        $this->role = $role;
        $this->overrides = $overrides;
        $this->granted = null;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->role = null;
        $this->overrides = null;
        $this->granted = null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    /**
     * Le membre a-t-il cette capacité ? Sans rôle (hors espace client), jamais.
     */
    public function can(string $capability): bool
    {
        return isset($this->grantedMap()[$capability]);
    }

    /**
     * Au moins une des capacités.
     */
    public function canAny(string ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toutes les capacités du membre.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_keys($this->grantedMap());
    }

    /**
     * @return array<string, true>
     */
    private function grantedMap(): array
    {
        if ($this->granted === null) {
            $this->granted = $this->role === null || $this->role === 'api'
                ? []
                : array_fill_keys(Capabilities::effective($this->role, $this->overrides, (bool) $this->organization?->reports_financial_managers), true);
        }

        return $this->granted;
    }
}
