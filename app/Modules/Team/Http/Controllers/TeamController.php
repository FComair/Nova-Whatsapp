<?php

declare(strict_types=1);

namespace App\Modules\Team\Http\Controllers;

use App\Modules\Team\Exceptions\TeamRefused;
use App\Modules\Team\Services\TeamManager;
use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglages > Équipe et accès (docs/34) : liste des personnes, invitation d'un praticien ou d'un assistant, permissions, suspension, retrait.
 * Réservé aux membres qui ont la permission « gérer l'équipe » (le propriétaire, et ceux à qui il l'a donnée). Chaque règle est vérifiée par
 * `TeamManager`, côté serveur ; l'écran ne fait que masquer ce qui serait refusé.
 */
final class TeamController
{
    public function __construct(private readonly TeamManager $team, private readonly CurrentTenant $current) {}

    public function index(): Response
    {
        $owner = $this->current->role() === OrgMember::ROLE_OWNER;

        return Inertia::render('Account/Team', [
            'members' => $this->team->members(),
            'catalog' => ['groups' => Capabilities::GROUPS, 'protected' => Capabilities::PROTECTED],
            'presets' => [
                'practitioner' => ['role' => OrgMember::ROLE_PRACTITIONER, 'capabilities' => Capabilities::defaults(OrgMember::ROLE_PRACTITIONER)],
                'assistant' => ['role' => OrgMember::ROLE_RECEPTIONIST, 'capabilities' => Capabilities::defaults(OrgMember::ROLE_RECEPTIONIST)],
            ],
            // Ce que la personne connectée peut accorder : ce qu'elle a, sans les capacités sensibles si elle n'est pas propriétaire.
            'grantable' => array_values(array_filter($this->current->permissions(), fn (string $capability): bool => $owner || ! in_array($capability, Capabilities::PROTECTED, true))),
            'is_owner' => $owner,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(Capabilities::INVITABLE_ROLES)],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'preset' => ['nullable', Rule::in(['practitioner', 'assistant', 'custom'])],
            'capabilities' => ['present', 'array', 'max:80'],
            'capabilities.*' => ['string', 'max:60'],
        ]);

        return $this->attempt(function () use ($data): void {
            $result = $this->team->invite([...$data, 'capabilities' => array_values($data['capabilities'])]);
            session()->flash('team_over_limit', $result['over_limit']);
        }, 'ui.team.flash.invited');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(Capabilities::INVITABLE_ROLES)],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'preset' => ['nullable', Rule::in(['practitioner', 'assistant', 'custom'])],
            'capabilities' => ['present', 'array', 'max:80'],
            'capabilities.*' => ['string', 'max:60'],
            'acknowledged' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($id, $data): void {
            $this->team->updateAccess($id, [
                'role' => $data['role'] ?? null, 'preset' => $data['preset'] ?? null, 'capabilities' => array_values($data['capabilities']),
                'name' => $data['name'] ?? null, 'phone' => $data['phone'] ?? null, 'notes' => $data['notes'] ?? null,
            ], (bool) ($data['acknowledged'] ?? false));
        }, 'ui.team.flash.saved');
    }

    public function suspend(Request $request, string $id): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;

        return $this->attempt(fn () => $this->team->suspend($id, $reason), 'ui.team.flash.suspended');
    }

    public function reactivate(string $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->team->reactivate($id), 'ui.team.flash.reactivated');
    }

    public function remove(Request $request, string $id): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;

        return $this->attempt(fn () => $this->team->remove($id, $reason), 'ui.team.flash.removed');
    }

    public function resend(string $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->team->resendInvitation($id), 'ui.team.flash.resent');
    }

    public function cancel(string $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->team->cancelInvitation($id), 'ui.team.flash.cancelled');
    }

    public function resetPassword(string $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->team->sendPasswordReset($id), 'ui.team.flash.reset');
    }

    /**
     * Exécute une action d'équipe : un refus devient un message d'erreur clair, jamais une page d'erreur.
     */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (TeamRefused $e) {
            return back()->withErrors(['team' => $e->message(), 'team_code' => $e->getMessage()]);
        }

        return back()->with('status', __($success));
    }
}
