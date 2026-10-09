<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Configuration du programme de fidélité (propriétaire). Les montants arrivent en euros (« 25 », « 12,50 ») et sont convertis en centimes ;
 * les textes sont par langue (fr, nl, en). Activer le programme exige davantage (voir LoyaltyPrograms::problems) : on peut enregistrer un brouillon incomplet.
 */
final class ProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {

        return [
            'name' => ['nullable', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'reward_description' => ['nullable', 'array'],
            'reward_description.*' => ['nullable', 'string', 'max:200'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['nullable', 'string', 'max:2000'],
            'required_visits' => ['required', 'integer', 'between:1,100'],
            'services_mode' => ['required', 'in:all,selected'],
            'earn_services' => ['nullable', 'array', 'max:200'],
            'earn_services.*' => ['uuid', Rule::exists('services', 'id')],
            'min_amount' => ['nullable', 'string', 'max:12'],
            'require_payment' => ['required', 'boolean'],
            'auto_enroll' => ['required', 'boolean'],
            'reward_type' => ['required', 'in:free_service,percent_discount,fixed_amount,custom'],
            'reward_percent' => ['nullable', 'integer', 'between:1,100'],
            'reward_amount' => ['nullable', 'string', 'max:12'],
            'reward_max' => ['nullable', 'string', 'max:12'],
            'reward_services_mode' => ['required', 'in:all,selected'],
            'reward_services' => ['nullable', 'array', 'max:200'],
            'reward_services.*' => ['uuid', Rule::exists('services', 'id')],
            'redeem_timing' => ['required', 'in:next_visit,any_time'],
            'combinable' => ['required', 'boolean'],
            'requires_appointment' => ['required', 'boolean'],
            'expiry_days' => ['nullable', 'integer', 'between:1,730'],
            'expiry_reminder_days' => ['nullable', 'integer', 'between:1,90'],
            'confirmed' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required_visits.*' => __('ui.loyalty.errors.required_visits'),
            'name.*.max' => __('ui.loyalty.errors.name_max'),
            'reward_description.*.max' => __('ui.loyalty.errors.reward_max_length'),
            'terms.*.max' => __('ui.loyalty.errors.terms_max'),
            'reward_percent.*' => __('ui.loyalty.errors.percent'),
            'expiry_days.*' => __('ui.loyalty.errors.expiry_days'),
            'expiry_reminder_days.*' => __('ui.loyalty.errors.expiry_reminder_days'),
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['min_amount', 'reward_amount', 'reward_max'] as $field) {
                $value = trim((string) $this->input($field, ''));

                if ($value !== '' && Money::parseToCents($value) === null) {
                    $validator->errors()->add($field, __('ui.loyalty.errors.amount'));
                }
            }
        }];
    }

    /**
     * Entrée du service : montants en centimes, valeur de la récompense selon son type.
     *
     * @return array<string, mixed>
     */
    public function programInput(): array
    {
        $cents = fn (string $field): ?int => trim((string) $this->input($field, '')) === '' ? null : Money::parseToCents((string) $this->input($field));
        $type = (string) $this->input('reward_type');

        return [
            'name' => (array) $this->input('name', []),
            'reward_description' => (array) $this->input('reward_description', []),
            'terms' => (array) $this->input('terms', []),
            'required_visits' => $this->integer('required_visits'),
            'services_mode' => (string) $this->input('services_mode'),
            'earn_services' => (array) $this->input('earn_services', []),
            'min_amount_cents' => $cents('min_amount'),
            'require_payment' => $this->boolean('require_payment'),
            'auto_enroll' => $this->boolean('auto_enroll'),
            'reward_type' => $type,
            'reward_value' => $type === 'percent_discount' ? $this->input('reward_percent') : ($type === 'fixed_amount' ? $cents('reward_amount') : null),
            'reward_max_cents' => $cents('reward_max'),
            'reward_services_mode' => (string) $this->input('reward_services_mode'),
            'reward_services' => (array) $this->input('reward_services', []),
            'redeem_timing' => (string) $this->input('redeem_timing'),
            'combinable' => $this->boolean('combinable'),
            'requires_appointment' => $this->boolean('requires_appointment'),
            'expiry_days' => $this->input('expiry_days'),
            'expiry_reminder_days' => $this->input('expiry_reminder_days') ?: 14,
        ];
    }
}
