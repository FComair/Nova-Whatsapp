<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketLink;
use App\Modules\Support\Models\SupportTicketTag;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vue Super Admin d'un ticket : lue et écrite avec le rôle PostgreSQL `nova_platform`, jamais avec le rôle client. Les messages, pièces
 * jointes et événements liés suivent la même connexion (notes internes comprises).
 *
 * @property string|null $organization_name ajouté par la liste
 */
class PlatformSupportTicket extends SupportTicket
{
    protected $connection = 'pgsql_platform';

    /**
     * @return HasMany<SupportTicketTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(SupportTicketTag::class, 'ticket_id');
    }

    /**
     * @return HasMany<SupportTicketLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(SupportTicketLink::class, 'ticket_id');
    }
}
