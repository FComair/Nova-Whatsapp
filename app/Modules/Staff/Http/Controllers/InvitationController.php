<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Identity\Services\SessionStarter;
use App\Modules\Legal\Exceptions\LegalAcceptanceFailed;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Legal\Support\LegalDocument;
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
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages publiques d'invitation d'un praticien (flowcharts 1C et 5B/E13). L'invité n'est pas connecté.
 */
final class InvitationController
{
    public function __construct(private readonly InvitationService $invitations, private readonly TenantContext $tenant, private readonly LegalDocuments $legal) {}

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
                // Documents à accepter, avec la version affichée : c'est cette version que la personne accepte (docs/28).
                'legal' => ['documents' => $this->legal->displayed(LegalDocument::requiredFor(false), app()->getLocale())],
            ]);
        });
    }

    public function accept(AcceptInvitationRequest $request, string $token, SessionStarter $session): RedirectResponse
    {
        try {
            $user = $this->invitations->accept($token, [
                'password' => $request->string('password')->toString(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'locale' => app()->getLocale(),
            ]);
        } catch (InvitationInvalid) {
            return redirect()->route('invitation.show', ['token' => $token]);
        } catch (LegalAcceptanceFailed $e) {
            // Le compte n'a pas été créé (transaction annulée) : la personne peut réessayer avec le même lien. Aucune donnée personnelle dans le journal.
            Log::error('Invitation : acceptation des documents juridiques non enregistrée.', ['missing' => $e->missing, 'cause' => $e->getPrevious() === null ? null : $e->getPrevious()::class]);

            return back()->withErrors(['legal' => __('ui.legal.invitation.failed')]);
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
