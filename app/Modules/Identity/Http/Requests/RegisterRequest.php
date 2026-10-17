<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Billing\Services\SignupPlans;
use App\Modules\Identity\Rules\StrongPassword;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Legal\Support\LegalDocument;
use App\Modules\Tenancy\Services\SectorCatalog;
use App\Support\ValidPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * Inscription (flowchart 1A) : e-mail, mot de passe, téléphone professionnel, secteur et trois acceptations.
 */
final class RegisterRequest extends FormRequest
{
    /** Valeur texte d'un champ, ou null s'il est absent ou vide. */
    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, list<ValidationRule|string|Rule|In|Unique>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', new StrongPassword, 'max:200'],
            'phone' => ['required', 'string', new ValidPhone],
            'sector' => ['required', 'string', Rule::in(app(SectorCatalog::class)->codes())],
            // Formules visibles pour cette personne : publiques, plus celle ouverte par son code d'invitation, son secteur ou son lien direct.
            'invite' => ['nullable', 'string', 'max:40'],
            'plan' => ['required', 'string', Rule::in(app(SignupPlans::class)->codes($this->nullableString('invite'), $this->nullableString('sector'), $this->nullableString('plan')))],
            // Conditions et confidentialité : acceptations distinctes et obligatoires, jamais précochées. Le DPA l'est aussi tant que la plateforme l'exige (docs/28).
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'accept_dpa' => config('rendivoo.legal.dpa_required_at_signup') ? ['accepted'] : ['nullable', 'boolean'],
            'legal_versions' => ['required', 'array'],
            'legal_versions.terms' => ['required', 'string', 'max:30'],
            'legal_versions.privacy' => ['required', 'string', 'max:30'],
            'legal_versions.dpa' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // E1 (flowchart 5A / catalogue 5E).
            'email.unique' => __('ui.errors.E1'),
            // Formule absente, retirée ou devenue indisponible entre l'affichage et l'envoi : on repropose le choix.
            'plan.required' => __('ui.register.plan_required'),
            'plan.in' => __('ui.register.plan_unavailable'),
            'accept_terms.accepted' => __('ui.register.must_accept'),
            'accept_privacy.accepted' => __('ui.register.must_accept'),
            'accept_dpa.accepted' => __('ui.register.must_accept'),
            'legal_versions.required' => __('ui.legal.signup.changed'),
            'legal_versions.terms.required' => __('ui.legal.signup.changed'),
            'legal_versions.privacy.required' => __('ui.legal.signup.changed'),
        ];
    }

    /**
     * Documents acceptés par cette demande (le DPA seulement s'il est coché).
     *
     * @return list<string>
     */
    public function acceptedDocuments(): array
    {
        $accepted = [LegalDocument::TERMS, LegalDocument::PRIVACY];

        if ($this->boolean('accept_dpa')) {
            $accepted[] = LegalDocument::DPA;
        }

        return $accepted;
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

            // L'utilisateur accepte la version qu'il a vue : si elle a changé depuis l'affichage, on lui montre la nouvelle avant de continuer.
            $current = app(LegalDocuments::class)->currentVersions();
            $seen = (array) $this->input('legal_versions', []);

            foreach ($this->acceptedDocuments() as $code) {
                if (($seen[$code] ?? null) !== ($current[$code] ?? null)) {
                    $validator->errors()->add('legal', __('ui.legal.signup.changed'));

                    return;
                }
            }
        }];
    }
}
