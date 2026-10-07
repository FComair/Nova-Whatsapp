<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Services\OrganizationLogo;
use Illuminate\Foundation\Http\FormRequest;

/** Envoi du logo : PNG ou JPEG, 2 Mo maximum, au moins 200 × 200 px. Le SVG est refusé (il peut embarquer des scripts). */
final class LogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg',
                'max:2048',
                'dimensions:min_width='.OrganizationLogo::MIN_SIDE.',min_height='.OrganizationLogo::MIN_SIDE.',max_width='.OrganizationLogo::MAX_SIDE.',max_height='.OrganizationLogo::MAX_SIDE,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.required' => __('ui.logo.errors.required'),
            'logo.file' => __('ui.logo.errors.invalid'),
            'logo.uploaded' => __('ui.logo.errors.too_large'),
            'logo.mimetypes' => __('ui.logo.errors.type'),
            'logo.max' => __('ui.logo.errors.too_large'),
            'logo.dimensions' => __('ui.logo.errors.dimensions'),
        ];
    }
}
