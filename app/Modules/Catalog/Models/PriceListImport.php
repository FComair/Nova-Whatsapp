<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $org_id
 * @property string|null $user_id
 * @property string $status analysed, imported, undone, cancelled ou expired
 * @property list<string> $file_names
 * @property list<string>|null $files
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed>|null $result
 * @property int $added
 * @property int $updated
 * @property int $ignored
 * @property CarbonInterface|null $imported_at
 * @property CarbonInterface|null $undone_at
 * @property CarbonInterface|null $created_at
 */
class PriceListImport extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_names' => 'array',
            'files' => 'array',
            'lines' => 'array',
            'result' => 'array',
            'imported_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }
}
