<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Identity\Rules\StrongPassword;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Legal\Support\LegalDocument;
use Illuminate\Contracts\Validation\Validator;
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
            // Versions vues par la personne : elle accepte celles-là, pas une version publiée entre l'affichage et l'envoi.
            'legal_versions' => ['required', 'array'],
            'legal_versions.terms' => ['required', 'string', 'max:30'],
            'legal_versions.privacy' => ['required', 'string', 'max:30'],
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
            'legal_versions.required' => __('ui.legal.signup.changed'),
            'legal_versions.terms.required' => __('ui.legal.signup.changed'),
            'legal_versions.privacy.required' => __('ui.legal.signup.changed'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = app(LegalDocuments::class)->currentVersions();
            $seen = (array) $this->input('legal_versions', []);

            foreach (LegalDocument::requiredFor(false) as $code) {
                if (($seen[$code] ?? null) !== ($current[$code] ?? null)) {
                    $validator->errors()->add('legal', __('ui.legal.signup.changed'));

                    return;
                }
            }
        }];
    }
}
