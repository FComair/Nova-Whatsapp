<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Services\PhoneChange;
use App\Modules\Onboarding\Http\Requests\CompanyRequest;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\CompanyUpdater;
use App\Modules\Tenancy\Support\CurrentTenant;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglages > Profil : coordonnées de la personne connectée (e-mail, téléphone, langue) et informations de l'entreprise.
 *
 * Tous les rôles voient et modifient leurs propres coordonnées ; seul le propriétaire modifie l'entreprise et sa facturation.
 * Les changements d'e-mail et de téléphone passent par les routes de sécurité existantes (mot de passe actuel, confirmation).
 */
final class AccountProfileController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly CompanyUpdater $company,
    ) {}

    public function show(Request $request, PhoneChange $phoneChange): Response
    {
        $user = $request->user() ?? abort(403);
        $organization = $this->organization();
        $owner = $this->current->role() === 'owner';

        return Inertia::render('Account/Profile', [
            'personal' => [
                'email' => $user->email,
                'pendingEmail' => DB::table('email_changes')->where('user_id', $user->id)->where('expires_at', '>', now())->value('new_email'),
                'phone' => $user->phone_e164,
                'pendingPhone' => $phoneChange->pending($user),
                'locale' => $user->locale,
            ],
            'canEditCompany' => $owner,
            'company' => [
                'name' => $organization->name,
                'sector' => (string) $organization->sector,
                'country' => $organization->country,
                'timezone' => $organization->timezone,
                'locale' => $organization->locale,
            ],
            // Identité de facturation : propriétaire seulement, enregistrée par la route de facturation existante.
            'billing' => $owner ? [
                'billing_name' => $organization->billing_name ?? '',
                'vat_number' => $organization->billing_vat_number ?? '',
                'line1' => (string) ($organization->billing_address['line1'] ?? ''),
                'postal_code' => (string) ($organization->billing_address['postal_code'] ?? ''),
                'city' => (string) ($organization->billing_address['city'] ?? ''),
            ] : null,
            'sectors' => config('nova.sectors'),
            'countries' => config('nova.countries'),
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::EUROPE),
        ]);
    }

    /** Informations de l'entreprise (propriétaire uniquement, imposé par la route). */
    public function updateCompany(CompanyRequest $request): RedirectResponse
    {
        $this->company->update($this->organization(), $request->validated(), (string) $request->user()?->id);

        return back()->with('status', __('ui.account_profile.company_saved'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
