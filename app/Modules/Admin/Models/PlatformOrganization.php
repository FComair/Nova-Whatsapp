<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vue Super Admin des organisations : lue avec le rôle PostgreSQL `nova_platform` (lecture transverse),
 * jamais avec le rôle client soumis à l'isolation par organisation.
 *
 * Les relations (factures, journal, tickets) suivent la même connexion : l'équipe ne lit un client qu'à travers cette fiche.
 */
class PlatformOrganization extends Organization
{
    protected $connection = 'pgsql_platform';

    protected $table = 'organizations';

    /**
     * @return HasMany<PlatformInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class, 'org_id');
    }

    /**
     * @return HasMany<PlatformAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(PlatformAuditLog::class, 'org_id');
    }

    /**
     * @return HasMany<PlatformSupportTicket, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(PlatformSupportTicket::class, 'org_id');
    }
}
