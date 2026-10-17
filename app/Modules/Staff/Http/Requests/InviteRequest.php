<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitation d'un praticien par e-mail : nom et adresse. Le praticien renseigne lui-même ses services et disponibilités.
 */
final class InviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                // Un compte Rendivoo existe déjà pour cette adresse : le rattachement d'un compte existant n'est pas géré à ce stade.
                Rule::unique('users', 'email'),
                // Une seule invitation en attente par adresse (évalué sous la RLS : propre à l'organisation).
                Rule::unique('invitations', 'email')->whereNull('used_at')->whereNull('revoked_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.unique' => __('ui.practitioners.errors.email_taken')];
    }
}
