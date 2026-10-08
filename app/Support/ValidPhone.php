<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Règle de validation d'un numéro (voir Phone) : message distinct quand l'indicatif de pays manque et que le numéro pourrait
 * appartenir à plusieurs pays.
 */
final class ValidPhone implements ValidationRule
{
    public function __construct(private readonly ?string $preferredCountry = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $result = Phone::resolve(is_string($value) ? $value : '', $this->preferredCountry);

        if ($result['problem'] === Phone::AMBIGUOUS) {
            $fail('ui.errors.phone_country_needed')->translate();
        } elseif ($result['e164'] === null) {
            $fail('ui.errors.phone_invalid')->translate();
        }
    }
}
