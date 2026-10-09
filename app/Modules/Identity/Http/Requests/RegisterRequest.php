<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Billing\Services\SignupPlans;
use App\Modules\Identity\Rules\StrongPassword;
use App\Modules\Tenancy\Services\SectorCatalog;
use App\Support\ValidPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

/**
 * Inscription (flowchart 1A) : e-mail, mot de passe, téléphone professionnel, secteur et trois acceptations.
 */
final class RegisterRequest extends FormRequest
{
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
            'plan' => ['required', 'string', Rule::in(app(SignupPlans::class)->codes())],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'accept_dpa' => ['accepted'],
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
        ];
    }
}
