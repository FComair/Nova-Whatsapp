<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Vue enregistrée de la liste des clients : filtres, colonnes et tri sous un nom, personnelle ou partagée.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property list<string>|null $columns
 * @property string|null $sort
 * @property string|null $direction
 * @property bool $shared
 */
final class CustomerSavedView extends Model
{
    use HasUuids;

    protected $table = 'customer_saved_views';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'shared' => 'boolean'];
    }
}
