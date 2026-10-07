<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Étape 2 : création ou modification d'un site (flowchart 1B).
 */
final class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'street' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', Rule::in(config('nova.countries'))],
        ];
    }

    /**
     * @return array{street: string|null, postal_code: string|null, city: string|null, country: string}
     */
    public function address(): array
    {
        return [
            'street' => $this->filled('street') ? trim($this->string('street')->toString()) : null,
            'postal_code' => $this->filled('postal_code') ? trim($this->string('postal_code')->toString()) : null,
            'city' => $this->filled('city') ? trim($this->string('city')->toString()) : null,
            'country' => $this->string('country')->toString(),
        ];
    }
}
