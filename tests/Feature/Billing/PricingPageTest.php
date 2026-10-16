<?php

declare(strict_types=1);

use App\Modules\Billing\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Page tarifs publique et choix à l'inscription (docs/40) : exactement ce que le Super Admin a publié, rien d'autre.
 */
beforeEach(function (): void {
    $this->plan = fn (array $attributes): Plan => $this->asOwner(fn () => Plan::query()->create([
        'id' => $id = (string) Str::uuid7(), 'family_id' => $id, 'code' => 'x-'.Str::lower(Str::random(5)), 'name' => 'Extra', 'price_monthly_cents' => 7999, 'price_cents' => 7999,
        'limits' => ['practitioners' => 10, 'locations' => 2, 'conversations' => 1000, 'appointments' => 800], 'status' => 'active', 'position' => 5,
        ...$attributes,
    ]));
    $this->codes = fn (string $url = '/tarifs') => collect($this->get($url)->assertOk()->original->getData()['page']['props']['plans'])->pluck('code')->all();
    $this->signupCodes = fn (string $query = '') => collect($this->get("/inscription{$query}")->assertOk()->original->getData()['page']['props']['offers']['plans'])->pluck('code')->all();
});

afterEach(function (): void {
    $this->asOwner(fn () => DB::table('plans')->whereNotIn('code', ['basic', 'business'])->delete());
});

describe('page publique', function (): void {
    it('montre les formules publiées, dans l\'ordre choisi, avec prix, limites, fonctionnalités et bouton', function (): void {
        ($this->plan)(['code' => 'pro', 'name' => 'Pro', 'position' => 0, 'is_recommended' => true, 'public_description' => ['fr' => 'Pour les équipes.'], 'features' => [
            ['label' => ['fr' => 'Rapports avancés'], 'state' => 'included', 'value' => null],
            ['label' => ['fr' => 'Support prioritaire'], 'state' => 'limited', 'value' => 'En semaine'],
            ['label' => ['fr' => 'API'], 'state' => 'unavailable', 'value' => null],
        ]]);

        $this->get('/tarifs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Pricing')
            ->where('plans.0.code', 'pro')->where('plans.0.name', 'Pro')->where('plans.0.recommended', true)->where('plans.0.description', 'Pour les équipes.')
            ->where('plans.0.price_cents', 7999)->where('plans.0.cta', ['type' => 'choose_plan', 'url' => '/inscription?plan=pro'])
            ->where('plans.0.features.1', ['label' => 'Support prioritaire', 'state' => 'limited', 'value' => 'En semaine'])
            ->where('plans.0.features.2.state', 'unavailable')
            ->where('plans.1.code', 'basic')->where('plans.2.code', 'business')->where('trial.days', 14));
    });

    it('ne montre jamais un brouillon, une formule inactive, archivée, remplacée ou hors de ses dates', function (string $case): void {
        $attributes = match ($case) {
            'brouillon' => ['status' => 'draft'],
            'inactive' => ['status' => 'inactive'],
            'archivée' => ['status' => 'archived'],
            'pas encore ouverte' => ['available_from' => now()->addDay()],
            'terminée' => ['available_until' => now()->subDay()],
        };
        ($this->plan)(['code' => 'cache', ...$attributes]);

        expect(($this->codes)())->not->toContain('cache');
    })->with(['brouillon', 'inactive', 'archivée', 'pas encore ouverte', 'terminée']);

    it('ne montre pas une formule privée, sur invitation ou réservée à des segments', function (): void {
        ($this->plan)(['code' => 'privee', 'visibility' => 'private']);
        ($this->plan)(['code' => 'invitee', 'visibility' => 'invite_only', 'invite_code' => 'VIP2026']);
        ($this->plan)(['code' => 'segmentee', 'visibility' => 'segments', 'segments' => ['sectors' => ['dental'], 'countries' => []]]);

        expect(($this->codes)())->toBe(['basic', 'business']);
    });

    it('sert les mêmes données en JSON pour le site vitrine', function (): void {
        $this->getJson('/tarifs.json')->assertOk()->assertJsonPath('plans.0.code', 'basic')->assertJsonPath('trial.days', 14)->assertJsonPath('vat.rate', 21);
    });

    it('annonce l\'absence de formule sans erreur', function (): void {
        $this->asOwner(fn () => DB::table('plans')->update(['status' => 'inactive']));

        $this->get('/tarifs')->assertOk()->assertInertia(fn (Assert $page) => $page->where('plans', []));
        $this->asOwner(fn () => DB::table('plans')->update(['status' => 'active']));
    });
});

describe('visibilité à l\'inscription', function (): void {
    it('ouvre une formule sur invitation avec son code, et seulement avec lui', function (): void {
        ($this->plan)(['code' => 'invitee', 'visibility' => 'invite_only', 'invite_code' => 'VIP2026']);

        expect(($this->signupCodes)())->not->toContain('invitee')
            ->and(($this->signupCodes)('?invite=FAUX'))->not->toContain('invitee')
            ->and(($this->signupCodes)('?invite=VIP2026'))->toContain('invitee');
    });

    it('ouvre une formule privée avec son lien direct', function (): void {
        ($this->plan)(['code' => 'privee', 'visibility' => 'private']);

        expect(($this->signupCodes)())->not->toContain('privee')->and(($this->signupCodes)('?plan=privee'))->toContain('privee');
    });

    it('ouvre une formule de segment au secteur concerné seulement', function (): void {
        ($this->plan)(['code' => 'dentistes', 'visibility' => 'segments', 'segments' => ['sectors' => ['dental'], 'countries' => []]]);

        expect(($this->signupCodes)('?sector=hairdressing'))->not->toContain('dentistes')->and(($this->signupCodes)('?sector=dental'))->toContain('dentistes');
    });

    it('n\'offre à l\'inscription que les formules qu\'on peut acheter en ligne', function (): void {
        ($this->plan)(['code' => 'devis', 'plan_type' => 'custom', 'price_cents' => 0, 'cta_type' => 'contact_sales', 'cta_url' => 'mailto:v@nova.test']);

        expect(($this->signupCodes)())->not->toContain('devis')->and(($this->codes)())->toContain('devis');
    });

    it('refuse à l\'envoi une formule invisible pour la personne', function (): void {
        ($this->plan)(['code' => 'invitee', 'visibility' => 'invite_only', 'invite_code' => 'VIP2026']);

        $this->post('/inscription', $this->validRegistration(['plan' => 'invitee']))->assertSessionHasErrors('plan');
        expect($this->asOwner(fn () => DB::table('organizations')->count()))->toBe(0);
    });
});
