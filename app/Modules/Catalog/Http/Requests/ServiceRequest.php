<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création ou modification d'un service. Le prix est saisi en euros (« 40 », « 40,50 ») et converti en centimes.
 */
final class ServiceRequest extends FormRequest
{
    /** Taux proposés dans la liste ; le client peut aussi saisir le sien (0 à 100 %, deux décimales au plus). */
    public const VAT_RATES = [0, 6, 12, 21];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'price' => is_scalar($this->input('price')) ? trim((string) $this->input('price')) : '',
            'vat_rate' => $this->normalizedVat(),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'between:5,480'],
            'buffer_before_minutes' => ['required', 'integer', 'between:0,120'],
            'buffer_after_minutes' => ['required', 'integer', 'between:0,120'],
            'price' => ['required', 'string'],
            'vat_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'capacity' => ['required', 'integer', 'between:1,50'],
            'bookable_online' => ['required', 'boolean'],
            'notes_required' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('price') && Money::parseToCents($this->string('price')->toString()) === null) {
                $validator->errors()->add('price', __('ui.services.errors.price_invalid'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $invalid = __('ui.services.fields.vat_invalid');

        return ['vat_rate.required' => $invalid, 'vat_rate.numeric' => $invalid, 'vat_rate.between' => $invalid, 'vat_rate.decimal' => $invalid];
    }

    /** « 21,5 % » ou « 21.5 » : virgule ou point, espaces et signe % ignorés. Une saisie qui n'est pas un nombre reste telle quelle (refusée plus loin). */
    private function normalizedVat(): mixed
    {
        $value = $this->input('vat_rate');

        if (! is_scalar($value)) {
            return $value;
        }

        $clean = str_replace(',', '.', preg_replace('/[\s%]+/u', '', (string) $value) ?? '');

        return is_numeric($clean) ? $clean : (string) $value;
    }

    /**
     * @return array{name: string, category: string|null, description: string|null, duration_minutes: int, buffer_before_minutes: int, buffer_after_minutes: int, price_cents: int, vat_rate: int|float, capacity: int, bookable_online: bool, notes_required: bool}
     */
    public function service(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'category' => $this->filled('category') ? trim($this->string('category')->toString()) : null,
            'description' => $this->filled('description') ? trim($this->string('description')->toString()) : null,
            'duration_minutes' => $this->integer('duration_minutes'),
            'buffer_before_minutes' => $this->integer('buffer_before_minutes'),
            'buffer_after_minutes' => $this->integer('buffer_after_minutes'),
            'price_cents' => Money::parseToCents($this->string('price')->toString()) ?? 0,
            'vat_rate' => round((float) $this->input('vat_rate'), 2),
            'capacity' => $this->integer('capacity'),
            'bookable_online' => $this->boolean('bookable_online'),
            'notes_required' => $this->boolean('notes_required'),
        ];
    }
}
