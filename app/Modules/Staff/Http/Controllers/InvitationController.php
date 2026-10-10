<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Identity\Services\SessionStarter;
use App\Modules\Staff\Http\Requests\AcceptInvitationRequest;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\InvitationInvalid;
use App\Modules\Staff\Services\InvitationService;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages publiques d'invitation d'un praticien (flowcharts 1C et 5B/E13). L'invité n'est pas connecté.
 */
final class InvitationController
{
    public function __construct(private readonly InvitationService $invitations, private readonly TenantContext $tenant) {}

    public function show(string $token): Response
    {
        $row = $this->invitations->lookup($token);

        // Introuvable, expirée, déjà utilisée ou remplacée : un seul message (E13), sans rien révéler de plus.
        if ($row === null) {
            return $this->expired();
        }

        return $this->tenant->run($row->org_id, function () use ($row, $token): Response {
            $invitation = Invitation::query()->find($row->id);

            if ($invitation === null || ! $invitation->isUsable()) {
                return $this->expired();
            }

            return Inertia::render('Invitation/Accept', [
                'token' => $token,
                'organization' => Organization::query()->find($invitation->org_id)?->name,
                'name' => $invitation->name ?? Practitioner::query()->find($invitation->practitioner_id)?->name,
                'email' => $invitation->email,
            ]);
        });
    }

    public function accept(AcceptInvitationRequest $request, string $token, SessionStarter $session): RedirectResponse
    {
        try {
            $user = $this->invitations->accept($token, ['password' => $request->string('password')->toString()]);
        } catch (InvitationInvalid) {
            return redirect()->route('invitation.show', ['token' => $token]);
        }

        // Compte créé et e-mail prouvé par le lien : connexion directe, puis espace du praticien (il renseigne ses services et ses horaires) ou
        // tableau de bord pour un assistant.
        $session->start($request, $user);
        $role = collect(DB::select('select * from auth_memberships(?)', [$user->id]))->first()?->role;

        return $role === OrgMember::ROLE_PRACTITIONER ? redirect()->route('profile.show') : redirect()->route('dashboard');
    }

    private function expired(): Response
    {
        return Inertia::render('Invitation/Expired');
    }
}
