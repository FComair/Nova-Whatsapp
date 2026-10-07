<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Organization;

/**
 * Organisation et rôle de l'utilisateur pour la requête en cours (posés par SetTenantContext).
 */
final class CurrentTenant
{
    private ?Organization $organization = null;

    private ?string $role = null;

    public function set(Organization $organization, string $role): void
    {
        $this->organization = $organization;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->role = null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function role(): ?string
    {
        return $this->role;
    }
}
