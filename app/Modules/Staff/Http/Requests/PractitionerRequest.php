<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Services\PractitionerPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d'un praticien : identité, couleur du planning et services proposés.
 */
final class PractitionerRequest extends FormRequest
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
            'color' => ['required', 'string', Rule::in(config('nova.practitioner_colors'))],
            'bio' => ['nullable', 'string', 'max:1000'],
            'service_ids' => ['present', 'array'],
            // `exists` est évalué sous la RLS : un service d'une autre organisation est introuvable.
            'service_ids.*' => ['uuid', 'distinct', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'is_me' => ['sometimes', 'boolean'],
            'copy_hours' => ['sometimes', 'boolean'],
            // Photo facultative à la création ; à la modification la photo passe par son propre envoi (photo / retrait).
            'photo' => ['nullable', ...PractitionerPhoto::rules()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PractitionerPhoto::messages();
    }

    /**
     * @return array{name: string, color: string, bio: string|null, service_ids: list<string>, copy_hours: bool}
     */
    public function practitioner(): array
    {
        /** @var list<string> $serviceIds */
        $serviceIds = array_values((array) $this->input('service_ids', []));

        return [
            'name' => $this->string('name')->toString(),
            'color' => $this->string('color')->toString(),
            'bio' => $this->filled('bio') ? trim($this->string('bio')->toString()) : null,
            'service_ids' => $serviceIds,
            'copy_hours' => $this->boolean('copy_hours', true),
        ];
    }
}
