<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Assistant d'onboarding : accès, reprise à l'étape enregistrée (flowcharts 1B à 1D, 2A).
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(onboarded: false);
});

describe('accès', function (): void {
    it('renvoie un visiteur vers la connexion', function (): void {
        $this->get('/configuration/assistant')->assertRedirect('/connexion');
    });

    it('est réservé au propriétaire', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($user, $org);
        $this->get('/configuration/assistant')->assertForbidden();
        $this->get('/configuration/assistant/entreprise')->assertForbidden();
        $this->post('/configuration/assistant/entreprise', [])->assertForbidden();
        $this->post('/configuration/assistant/sites', [])->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('répond 404 pour une étape inconnue', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/configuration/assistant/inconnue')->assertNotFound();
    });

    it('affiche « bientôt disponible » pour les étapes sans écran', function (string $slug): void {
        $this->signedIn($this->owner, $this->org)->get("/configuration/assistant/{$slug}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Onboarding/Pending'));
    })->with(['reservation-test']);
});

describe('reprise de l\'assistant', function (): void {
    it('ouvre la première étape pour un nouveau compte', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/configuration/assistant')->assertRedirect('/configuration/assistant/entreprise');
    });

    it('rouvre l\'étape enregistrée', function (array $done, string $expected): void {
        $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->update(['steps' => array_fill_keys($done, 'done')]));

        $this->signedIn($this->owner, $this->org)->get('/configuration/assistant')->assertRedirect("/configuration/assistant/{$expected}");
    })->with([
        'entreprise faite' => [[OnboardingSteps::COMPANY], 'sites'],
        'sites faits' => [[OnboardingSteps::COMPANY, OnboardingSteps::LOCATIONS], 'horaires'],
        'horaires faits' => [[OnboardingSteps::COMPANY, OnboardingSteps::LOCATIONS, OnboardingSteps::HOURS], 'services'],
    ]);

    it('envoie le propriétaire vers l\'assistant à la connexion tant qu\'il n\'est pas terminé', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'password-de-test'])->assertRedirect('/configuration/assistant');
    });

    it('envoie vers le tableau de bord quand l\'assistant est terminé', function (): void {
        $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->update(['completed_at' => now()]));

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'password-de-test'])->assertRedirect('/tableau-de-bord');
    });

    it('n\'impose pas l\'assistant aux autres rôles', function (): void {
        // Réceptionniste rattaché à la MÊME organisation, dont l'assistant est inachevé.
        $this->asOwner(function (): void {
            $user = User::factory()->create(['email' => 'accueil@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist']);
        });

        $this->post('/connexion', ['email' => 'accueil@example.com', 'password' => 'password-de-test'])->assertRedirect('/tableau-de-bord');
    });

    it('crée la progression pour un compte antérieur à l\'assistant', function (): void {
        $this->asOwner(fn () => DB::table('onboarding_progress')->where('org_id', $this->org->id)->delete());

        $this->signedIn($this->owner, $this->org)->get('/configuration/assistant')->assertRedirect('/configuration/assistant/entreprise');

        expect($this->asOwner(fn () => DB::table('onboarding_progress')->where('org_id', $this->org->id)->count()))->toBe(1);
    });
});

describe('tableau de bord', function (): void {
    it('invite le propriétaire à reprendre l\'assistant', function (): void {
        $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->update(['steps' => ['company' => 'done']]));

        $this->signedIn($this->owner, $this->org)->get('/tableau-de-bord')
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('onboarding.slug', 'sites'));
    });

    it('n\'affiche rien quand l\'assistant est terminé', function (): void {
        $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->update(['completed_at' => now()]));

        $this->signedIn($this->owner, $this->org)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('onboarding', null));
    });

    it('n\'affiche rien aux autres rôles', function (): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager', onboarded: false);

        $this->signedIn($user, $org)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('onboarding', null));
    });
});

describe('modèle de progression', function (): void {
    it('termine l\'assistant quand les 9 étapes sont faites', function (): void {
        $progress = $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id));

        $this->asOwner(function () use ($progress): void {
            foreach (OnboardingSteps::ALL as $step) {
                expect($progress->isComplete())->toBeFalse();
                $progress->markDone($step);
            }
        });

        expect($progress->isComplete())->toBeTrue()->and($progress->currentStep())->toBe(OnboardingSteps::TEST_BOOKING);
    });

    it('propose la prochaine étape non faite, puis une étape sautée, puis la dernière', function (): void {
        $progress = $this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id));

        // Aucune étape faite : après « sites », on va aux horaires (et non retour à « entreprise »).
        expect($progress->nextStepAfter(OnboardingSteps::LOCATIONS))->toBe(OnboardingSteps::HOURS);

        // Horaires déjà faits : on saute à la suivante.
        $this->asOwner(fn () => $progress->markDone(OnboardingSteps::HOURS));
        expect($progress->nextStepAfter(OnboardingSteps::LOCATIONS))->toBe(OnboardingSteps::SERVICES);

        // Rien de non fait après la dernière : retour à la première étape sautée.
        expect($progress->nextStepAfter(OnboardingSteps::TEST_BOOKING))->toBe(OnboardingSteps::COMPANY);
    });

    it('décrit l\'état de chaque étape', function (): void {
        $progress = $this->asOwner(function () {
            $progress = OnboardingProgress::query()->find($this->org->id);
            $progress->markDone(OnboardingSteps::COMPANY);

            return $progress;
        });

        $overview = collect($progress->overview())->keyBy('key');

        expect($overview['company']['status'])->toBe('done')
            ->and($overview['locations']['status'])->toBe('current')
            ->and($overview['hours']['status'])->toBe('todo')
            ->and($overview['locations']['available'])->toBeTrue()
            ->and($overview['services']['available'])->toBeTrue()
            ->and($overview['practitioners']['available'])->toBeTrue()
            ->and($overview['policies']['available'])->toBeTrue()
            ->and($overview['whatsapp']['available'])->toBeTrue()
            ->and($overview['templates']['available'])->toBeTrue()
            ->and($overview['test']['available'])->toBeFalse()
            ->and($overview)->toHaveCount(9);
    });

    it('isole la progression entre organisations (RLS)', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com', onboarded: false);

        app(TenantContext::class)->set($this->org->id);

        expect(OnboardingProgress::query()->pluck('org_id')->all())->toBe([$this->org->id]);
        expect(OnboardingProgress::query()->find($other->id))->toBeNull();
    });
});
