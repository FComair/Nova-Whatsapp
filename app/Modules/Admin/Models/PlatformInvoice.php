<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Facture vue par le Super Admin (lecture seule, toutes organisations). Le rôle plateforme n'a aucun droit d'écriture ici.
 *
 * @property string $id
 * @property string $org_id
 * @property string $number
 * @property string $status
 * @property int $total_cents
 */
class PlatformInvoice extends Model
{
    use HasUuids;

    protected $connection = 'pgsql_platform';

    protected $table = 'invoices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
