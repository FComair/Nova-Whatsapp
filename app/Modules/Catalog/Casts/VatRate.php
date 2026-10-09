<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux de TVA en pourcentage : un entier quand il est rond (21), un décimal sinon (21.5). La base le garde en numeric(5,2).
 *
 * @implements CastsAttributes<int|float, int|float|string>
 */
final class VatRate implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $rate = (float) $value;

        return floor($rate) === $rate ? (int) $rate : $rate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
