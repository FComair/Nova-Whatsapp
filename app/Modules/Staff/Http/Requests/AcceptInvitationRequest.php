<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Acceptation d'une invitation : mot de passe et acceptation des conditions (le DPA lie l'entreprise à Nova, pas le praticien).
 */
final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', new StrongPassword, 'max:200', 'confirmed'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => __('ui.register.must_accept'),
            'accept_privacy.accepted' => __('ui.register.must_accept'),
        ];
    }
}
