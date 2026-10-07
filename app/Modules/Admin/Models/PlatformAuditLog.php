<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'audit vu par le Super Admin (lecture seule, toutes organisations confondues).
 *
 * @property string $id
 * @property string|null $org_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $reason
 * @property string|null $ip
 */
class PlatformAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'audit_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'diff' => 'array'];
    }
}
