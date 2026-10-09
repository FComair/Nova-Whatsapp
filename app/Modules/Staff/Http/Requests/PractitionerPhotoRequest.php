<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Services\PractitionerPhoto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envoi ou remplacement de la photo de profil d'un praticien (JPEG, PNG ou WebP, 5 Mo, de 200 à 6 000 px).
 */
final class PractitionerPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['photo' => ['required', ...PractitionerPhoto::rules()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['photo.required' => __('ui.practitioner_photo.errors.invalid'), ...PractitionerPhoto::messages()];
    }
}
