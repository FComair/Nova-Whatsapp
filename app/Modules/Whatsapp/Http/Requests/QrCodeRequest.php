<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Http\Requests;

use App\Modules\Whatsapp\Services\QrRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Création ou modification d'un QR code : nom, message pré-rempli, couleur (liste fermée), logo. */
final class QrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:60'],
            'message' => ['nullable', 'string', 'max:200'],
            'color' => ['required', 'string', Rule::in(QrRenderer::COLORS)],
            'show_logo' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('ui.whatsapp_qr.errors.name'),
            'name.max' => __('ui.whatsapp_qr.errors.name'),
            'message.max' => __('ui.whatsapp_qr.errors.message'),
            'color.in' => __('ui.whatsapp_qr.errors.color'),
            'color.required' => __('ui.whatsapp_qr.errors.color'),
        ];
    }
}
