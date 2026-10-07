<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paramètre global de la plateforme (durée ou seuil). Écrit avec le rôle `nova_platform`.
 *
 * @property string $key
 * @property string $value JSON
 */
class PlatformSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];
}
