<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PendingVerification;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre la session d'un utilisateur dont les identifiants sont valides (flowchart 2A).
 *
 * Un compte non activé (e-mail ou téléphone non vérifié) n'est jamais connecté : il est renvoyé vers l'étape de vérification.
 */
final class SessionStarter
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            PendingVerification::remember($request, $user);

            return redirect()->route('verification.notice');
        }

        if ($user->phone_e164 !== null && ! $user->hasVerifiedPhone()) {
            PendingVerification::remember($request, $user);

            return redirect()->route('phone.show');
        }

        $memberships = collect(DB::select('select * from auth_memberships(?)', [$user->id]));

        if ($memberships->isEmpty()) {
            // Compte sans organisation active (rattachement désactivé) : accès refusé sans détail.
            return redirect()->route('login')->withErrors(['email' => __('ui.errors.invalid_credentials')]);
        }

        PendingVerification::forget($request);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(SetTenantContext::SESSION_KEY, $memberships->first()->org_id);

        // L'écriture d'audit rattachée à l'organisation exige son contexte (RLS) : posé le temps de l'écriture seulement.
        $orgId = $memberships->first()->org_id;
        $role = $memberships->first()->role;

        $onboardingPending = $this->tenant->run($orgId, function () use ($user, $orgId, $role): bool {
            Audit::record('auth.login', 'user', actorId: $user->id, orgId: $orgId);

            return $role === OrgMember::ROLE_OWNER
                && ! OnboardingProgress::query()->firstOrCreate(['org_id' => $orgId], ['steps' => []])->isComplete();
        });

        // Assistant non terminé : il se rouvre à l'étape enregistrée (flowchart 2A).
        return $onboardingPending ? redirect()->route('onboarding.index') : redirect()->intended(route('dashboard'));
    }
}
