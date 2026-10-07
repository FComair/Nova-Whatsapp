<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Flux ICS sortant d'un praticien. Le jeton est chiffré (réaffichage) et son empreinte sert à la recherche.
 *
 * @property string $id
 * @property string $org_id
 * @property string $practitioner_id
 * @property string $token_hash
 * @property string $token_enc
 */
class CalendarFeed extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_enc', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token_enc' => 'encrypted'];
    }
}
