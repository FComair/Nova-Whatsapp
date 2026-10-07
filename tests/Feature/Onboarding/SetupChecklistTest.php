<?php

declare(strict_types=1);

use App\Modules\Booking\Services\BookingCore;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactor;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Réglages > Mise en route : les tâches existantes regroupées en catégories, avec leur état réel.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(onboarded: false);
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->done = fn (string ...$steps) => $this->asOwner(function () use ($steps): void {
        $progress = OnboardingProgress::query()->findOrFail($this->org->id);
        array_map(fn (string $step) => $progress->markDone($step), $steps);
    });
    $this->category = fn (string $key, ?User $user = null) => ($this->as)($user)->get("/reglages/demarrage/{$key}")->assertOk();
    $this->states = function (string $key): array {
        $steps = [];
        ($this->category)($key)->assertInertia(function (Assert $page) use (&$steps): void {
            $steps = $page->toArray()['props']['category']['steps'];
        });

        return collect($steps)->pluck('state', 'key')->all();
    };
});

describe('catégories', function (): void {
    it('regroupe les 13 tâches en 4 catégories, chacune dans une seule', function (): void {
        $keys = [];

        foreach (['business' => 3, 'team' => 4, 'messages' => 2, 'launch' => 4] as $category => $count) {
            ($this->category)($category)->assertInertia(fn (Assert $page) => $page
                ->component('Account/Setup')
                ->where('category.key', $category)
                ->where('category.total', $count)
                ->has('category.steps', $count));

            $keys = [...$keys, ...array_keys(($this->states)($category))];
        }

        expect($keys)->toHaveCount(13)->and(array_unique($keys))->toHaveCount(13);
    });

    it('renvoie chaque étape de l\'assistant vers son écran existant', function (): void {
        ($this->category)('business')->assertInertia(fn (Assert $page) => $page
            ->where('category.steps.0.href', '/configuration/assistant/entreprise')
            ->where('category.steps.1.href', '/configuration/assistant/sites')
            ->where('category.steps.2.href', '/configuration/assistant/horaires'));

        ($this->category)('messages')->assertInertia(fn (Assert $page) => $page
            ->where('category.steps.0.href', '/configuration/assistant/whatsapp')
            ->where('category.steps.1.href', '/configuration/assistant/messages-types'));
    });

    it('renvoie les autres tâches vers les écrans qui les portent', function (): void {
        ($this->category)('team')->assertInertia(fn (Assert $page) => $page->where('category.steps.3.key', 'calendar')->where('category.steps.3.href', '/calendriers'));

        ($this->category)('launch')->assertInertia(fn (Assert $page) => $page
            ->where('category.steps.1.href', '/planning')
            ->where('category.steps.2.href', '/facturation')
            ->where('category.steps.3.href', '/securite'));
    });

    it('refuse une catégorie inconnue', function (): void {
        ($this->as)()->get('/reglages/demarrage/inconnue')->assertNotFound();
    });
});

describe('état des tâches', function (): void {
    it('lit la progression de l\'assistant : terminée, en cours (la première non faite), à faire', function (): void {
        expect(($this->states)('business'))->toBe(['company' => 'current', 'locations' => 'todo', 'hours' => 'todo']);

        ($this->done)('company', 'locations');

        expect(($this->states)('business'))->toBe(['company' => 'done', 'locations' => 'done', 'hours' => 'current'])
            ->and(($this->states)('team')['services'])->toBe('todo');
    });

    it('marque « bientôt » la réservation test, dont l\'écran n\'existe pas encore, sans lien', function (): void {
        ($this->category)('launch')->assertInertia(fn (Assert $page) => $page->where('category.steps.0.key', 'test_booking')->where('category.steps.0.state', 'soon')->where('category.steps.0.href', null));
    });

    it('détecte le premier rendez-vous dans les données', function (): void {
        expect(($this->states)('launch')['first_booking'])->toBe('todo');

        app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00')));

        expect(($this->states)('launch')['first_booking'])->toBe('done');
    });

    it('détecte la double authentification du compte', function (): void {
        expect(($this->states)('launch')['two_factor'])->toBe('todo');

        $service = app(TwoFactor::class);
        $secret = $service->beginSetup($this->owner);
        $service->confirm($this->owner->refresh(), (new Google2FA)->oathTotp($secret, (new Google2FA)->getTimestamp()));

        expect(($this->states)('launch')['two_factor'])->toBe('done');
    });

    it('compte le « reste à faire » sans les tâches facultatives ni « bientôt »', function (): void {
        // Services et équipe : 3 tâches obligatoires, le calendrier est facultatif.
        ($this->category)('team')->assertInertia(fn (Assert $page) => $page->where('category.left', 3)->where('category.complete', false));

        ($this->done)('services', 'practitioners', 'policies');

        ($this->category)('team')->assertInertia(fn (Assert $page) => $page->where('category.left', 0)->where('category.complete', true)->where('category.done', 3));

        // Lancement : la réservation test (« bientôt ») ne compte pas, il reste premier rendez-vous, formule et 2FA.
        ($this->category)('launch')->assertInertia(fn (Assert $page) => $page->where('category.left', 3));
    });
});

describe('accès', function (): void {
    it('est réservé au propriétaire', function (string $role): void {
        $user = $this->asOwner(function () use ($role): User {
            $user = User::factory()->create(['email' => "{$role}@example.com"]);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

            return $user;
        });

        ($this->as)($user)->get('/reglages/demarrage/business')->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/reglages/demarrage/business')->assertRedirect('/connexion');
    });
});

describe('pastilles du menu', function (): void {
    it('partage le reste à faire de chaque catégorie avec les pages de réglages du propriétaire', function (): void {
        ($this->done)('company', 'locations', 'hours');

        ($this->as)()->get('/reglages/profil')->assertInertia(fn (Assert $page) => $page
            ->where('settings.setup.0', ['key' => 'business', 'left' => 0, 'complete' => true])
            ->where('settings.setup.1', ['key' => 'team', 'left' => 3, 'complete' => false])
            ->where('settings.setup.2', ['key' => 'messages', 'left' => 2, 'complete' => false])
            ->where('settings.setup.3', ['key' => 'launch', 'left' => 3, 'complete' => false]));
    });

    it('ne les partage pas aux autres rôles', function (): void {
        $user = $this->asOwner(function (): User {
            $user = User::factory()->create(['email' => 'resp@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'site_manager']);

            return $user;
        });

        ($this->as)($user)->get('/reglages/profil')->assertInertia(fn (Assert $page) => $page->where('settings.setup', null));
    });
});
