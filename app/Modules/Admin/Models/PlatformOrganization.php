<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Models\Organization;

/**
 * Vue Super Admin des organisations : lue avec le rôle PostgreSQL `nova_platform` (lecture transverse),
 * jamais avec le rôle client soumis à l'isolation par organisation.
 */
class PlatformOrganization extends Organization
{
    protected $connection = 'pgsql_platform';

    protected $table = 'organizations';
}
