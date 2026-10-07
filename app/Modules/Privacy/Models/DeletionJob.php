<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une étape d'effacement (une table) pour une organisation. Trois tentatives au plus avant l'alerte au fondateur.
 *
 * @property string $id
 * @property string $org_id
 * @property string $step
 * @property string $status pending | running | done | failed
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $rows_deleted
 * @property Carbon|null $completed_at
 */
class DeletionJob extends Model
{
    use HasUuids;

    protected $connection = 'pgsql_platform';

    protected $table = 'deletion_jobs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
