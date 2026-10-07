<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d'un service. Le prix est saisi en euros (« 40 », « 40,50 ») et converti en centimes.
 */
final class ServiceRequest extends FormRequest
{
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
            'vat_rate' => ['required', 'integer', Rule::in(self::VAT_RATES)],
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
     * @return array{name: string, category: string|null, description: string|null, duration_minutes: int, buffer_before_minutes: int, buffer_after_minutes: int, price_cents: int, vat_rate: int, capacity: int, bookable_online: bool, notes_required: bool}
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
            'vat_rate' => $this->integer('vat_rate'),
            'capacity' => $this->integer('capacity'),
            'bookable_online' => $this->boolean('bookable_online'),
            'notes_required' => $this->boolean('notes_required'),
        ];
    }
}
