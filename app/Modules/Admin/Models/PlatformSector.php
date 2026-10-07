<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Models\Sector;

/**
 * Secteur d'activité géré par le Super Admin : même table que Sector, mais écrite avec le rôle `nova_platform`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $name_key
 * @property string|null $notes
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $merchants_count nombre d'établissements dans ce secteur (ajouté par la liste)
 */
class PlatformSector extends Sector
{
    protected $connection = 'pgsql_platform';
}
