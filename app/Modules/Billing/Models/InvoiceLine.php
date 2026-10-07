<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $org_id
 * @property string $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_cents
 * @property int $total_cents
 */
class InvoiceLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
