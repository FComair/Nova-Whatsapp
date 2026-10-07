<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Requests;

use App\Modules\Tenancy\Services\SectorCatalog;
use App\Modules\Tenancy\Support\CurrentTenant;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Étape 1 : entreprise et fuseau horaire (flowchart 1B).
 */
final class CompanyRequest extends FormRequest
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
            // Secteurs actifs ; l'établissement peut garder son secteur actuel même s'il a été désactivé depuis.
            'sector' => ['required', 'string', Rule::in(app(SectorCatalog::class)->codes(app(CurrentTenant::class)->organization()?->sector))],
            'country' => ['required', 'string', Rule::in(config('nova.countries'))],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::EUROPE))],
            'locale' => ['required', 'string', Rule::in(config('nova.locales'))],
        ];
    }
}
