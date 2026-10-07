<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;

/**
 * Jeux de données réutilisables pour les tests d'authentification (créés avec le rôle propriétaire).
 */
trait AuthScenarios
{
    /**
     * Compte activé (e-mail et téléphone vérifiés) rattaché à une organisation.
     *
     * @param  array<string, mixed>  $orgState
     * @return array{user: User, org: Organization}
     */
    protected function activatedAccount(array $orgState = [], string $email = 'proprio@example.com', string $role = 'owner', bool $onboarded = true): array
    {
        return $this->asOwner(function () use ($orgState, $email, $role, $onboarded): array {
            $org = Organization::factory()->create($orgState);
            Location::factory()->create(['org_id' => $org->id, 'is_default' => true]);
            $user = User::factory()->create(['email' => $email]);
            OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

            // Par défaut l'assistant est terminé : les tests d'authentification arrivent sur le tableau de bord.
            OnboardingProgress::query()->create([
                'org_id' => $org->id,
                'steps' => $onboarded ? array_fill_keys(OnboardingSteps::ALL, 'done') : [],
                'completed_at' => $onboarded ? now() : null,
            ]);

            return ['user' => $user, 'org' => $org];
        });
    }

    /**
     * Ouvre une session d'espace client pour l'utilisateur, dans le contexte de son organisation.
     */
    protected function signedIn(User $user, Organization $org): static
    {
        return $this->withSession([SetTenantContext::SESSION_KEY => $org->id])->actingAs($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validRegistration(array $overrides = []): array
    {
        return array_merge([
            'email' => 'nouveau@example.com',
            'password' => 'Sol1de-Phrase-Pour-Test!',
            'phone' => '+32 470 12 34 56',
            'sector' => 'hairdressing',
            'plan' => 'trial',
            'accept_terms' => true,
            'accept_privacy' => true,
            'accept_dpa' => true,
        ], $overrides);
    }
}
