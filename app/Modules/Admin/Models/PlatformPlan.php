<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Billing\Models\Plan;

/**
 * Formule écrite avec le rôle `nova_platform` (Super Admin).
 *
 * @property int|null $subscribers_count abonnés actuels (ajouté par la liste)
 */
class PlatformPlan extends Plan
{
    protected $connection = 'pgsql_platform';
}
