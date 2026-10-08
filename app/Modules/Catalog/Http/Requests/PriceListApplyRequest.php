<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lignes vérifiées par l'utilisateur. Les prix arrivent en centimes entiers. Une ligne ignorée n'est pas contrôlée plus avant ;
 * une nouvelle ligne doit avoir un nom, un prix, une durée ; une mise à jour doit désigner un service et un prix.
 */
final class PriceListApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'confirm' => ['accepted'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.key' => ['required', 'string', 'max:20'],
            'lines.*.action' => ['required', Rule::in(['new', 'update', 'ignore'])],
            'lines.*.name' => ['required_if:lines.*.action,new', 'nullable', 'string', 'min:1', 'max:120'],
            'lines.*.price_cents' => ['required_unless:lines.*.action,ignore', 'nullable', 'integer', 'min:0', 'max:9999999'],
            'lines.*.category' => ['nullable', 'string', 'max:60'],
            'lines.*.duration_minutes' => ['required_if:lines.*.action,new', 'nullable', 'integer', 'min:5', 'max:480'],
            'lines.*.vat_rate' => ['nullable', Rule::in([0, 6, 12, 21])],
            'lines.*.service_id' => ['required_if:lines.*.action,update', 'nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.accepted' => __('ui.price_import.errors.confirm'),
            'lines.required' => __('ui.price_import.errors.nothing'),
            'lines.*.name.required_if' => __('ui.price_import.errors.line'),
            'lines.*.name.max' => __('ui.price_import.errors.line'),
            'lines.*.price_cents.required_unless' => __('ui.price_import.errors.line'),
            'lines.*.price_cents.integer' => __('ui.price_import.errors.line'),
            'lines.*.price_cents.min' => __('ui.price_import.errors.line'),
            'lines.*.price_cents.max' => __('ui.price_import.errors.line'),
            'lines.*.duration_minutes.required_if' => __('ui.price_import.errors.line'),
            'lines.*.duration_minutes.min' => __('ui.price_import.errors.line'),
            'lines.*.duration_minutes.max' => __('ui.price_import.errors.line'),
            'lines.*.service_id.required_if' => __('ui.price_import.errors.line'),
        ];
    }
}
