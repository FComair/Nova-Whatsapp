<?php

declare(strict_types=1);

use App\Modules\Billing\Support\PlanLimits;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Choix de la formule à l'inscription (docs/10) : les formules, prix, limites, durée d'essai et TVA viennent de la configuration ;
 * l'inscription enregistre le choix et ouvre l'essai avec les limites de cette formule, sans abonnement ni moyen de paiement.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->offers = fn (string $query = ''): array => Assert::fromTestResponse($this->get("/inscription{$query}")->assertOk())->toArray()['props'];
    $this->orgs = fn (): int => $this->asOwner(fn () => DB::table('organizations')->count());
    $this->org = fn (): object => $this->asOwner(fn () => DB::table('organizations')->latest('created_at')->firstOrFail());
});

// Les formules et la durée d'essai sont des données globales (hors isolation par établissement) : chaque test les remet à leur état d'origine.
afterEach(function (): void {
    $this->asOwner(function (): void {
        DB::table('plans')->where('code', 'basic')->update(['name' => 'Basic', 'price_monthly_cents' => 2999, 'is_active' => true, 'is_recommended' => false, 'limits' => json_encode(['practitioners' => 1, 'locations' => 1, 'conversations' => 150, 'appointments' => 90])]);
        DB::table('plans')->where('code', 'business')->update(['name' => 'Business', 'price_monthly_cents' => 4999, 'is_active' => true, 'is_recommended' => false, 'limits' => json_encode(['practitioners' => 5, 'locations' => 1, 'conversations' => 600, 'appointments' => 380])]);
        DB::table('platform_settings')->where('key', 'trial_days')->update(['value' => json_encode(14)]);
    });
});

describe('formules proposées', function (): void {
    it('vient de la configuration : formules actives, prix, limites, durée de l\'essai et TVA', function (): void {
        $offers = ($this->offers)()['offers'];

        expect($offers['trial'])->toMatchArray(['days' => 14, 'based_on' => 'Business', 'payment_method_required' => false])
            ->and($offers['trial']['limits'])->toBe(['practitioners' => 5, 'locations' => 1, 'conversations' => 600, 'appointments' => 380])
            ->and(collect($offers['plans'])->pluck('code')->all())->toBe(['basic', 'business'])
            ->and($offers['plans'][0])->toMatchArray(['name' => 'Basic', 'price_cents' => 2999, 'currency' => 'EUR', 'recommended' => false])
            ->and($offers['plans'][0]['limits'])->toBe(['practitioners' => 1, 'locations' => 1, 'conversations' => 150, 'appointments' => 90])
            ->and($offers['plans'][1])->toMatchArray(['name' => 'Business', 'price_cents' => 4999])
            ->and($offers['vat'])->toBe(['rate' => 21, 'country' => 'BE']);
    });

    it('suit la configuration sans toucher à l\'écran : prix, limites, essai, formule retirée', function (): void {
        $this->asOwner(function (): void {
            DB::table('plans')->where('code', 'basic')->update(['price_monthly_cents' => 3500, 'name' => 'Essentiel', 'limits' => json_encode(['practitioners' => 2, 'locations' => 2, 'conversations' => null, 'appointments' => 100])]);
            DB::table('plans')->where('code', 'business')->update(['is_active' => false]);
            DB::table('platform_settings')->where('key', 'trial_days')->update(['value' => json_encode(30)]);
        });

        $offers = ($this->offers)()['offers'];

        expect(collect($offers['plans'])->pluck('code')->all())->toBe(['basic'])->and($offers['plans'][0])->toMatchArray(['name' => 'Essentiel', 'price_cents' => 3500])
            ->and($offers['plans'][0]['limits'])->toBe(['practitioners' => 2, 'locations' => 2, 'conversations' => null, 'appointments' => 100])
            ->and($offers['trial']['days'])->toBe(30);
    });

    it('ne marque aucune formule comme recommandée sauf désignation explicite (une seule possible)', function (): void {
        expect(collect(($this->offers)()['offers']['plans'])->pluck('recommended')->all())->toBe([false, false]);

        $this->asOwner(fn () => DB::table('plans')->where('code', 'business')->update(['is_recommended' => true]));
        expect(collect(($this->offers)()['offers']['plans'])->pluck('recommended', 'code')->all())->toBe(['basic' => false, 'business' => true]);

        expect(fn () => $this->asOwner(fn () => DB::table('plans')->where('code', 'basic')->update(['is_recommended' => true])))->toThrow(QueryException::class);
    });

    it('préchoisit la formule d\'un lien seulement si elle existe, sinon l\'essai', function (): void {
        expect(($this->offers)('?plan=business')['plan'])->toBe('business')->and(($this->offers)('?plan=basic')['plan'])->toBe('basic')
            ->and(($this->offers)('?plan=inconnue')['plan'])->toBe('trial')->and(($this->offers)()['plan'])->toBe('trial');

        $this->asOwner(fn () => DB::table('plans')->where('code', 'business')->update(['is_active' => false]));
        expect(($this->offers)('?plan=business')['plan'])->toBe('trial');
    });

    it('reste utilisable sans aucune formule payante : seul l\'essai est proposé', function (): void {
        $this->asOwner(fn () => DB::table('plans')->update(['is_active' => false]));

        $offers = ($this->offers)()['offers'];

        expect($offers['plans'])->toBe([])->and($offers['trial']['days'])->toBe(14);
    });
});

describe('inscription avec une formule', function (): void {
    it('enregistre l\'essai gratuit : limites de repli, aucun abonnement ni moyen de paiement', function (): void {
        $this->post('/inscription', $this->validRegistration(['plan' => 'trial']))->assertRedirect('/verification-email');

        $org = ($this->org)();

        expect($org->signup_plan)->toBe('trial')->and($org->status)->toBe('trial')->and($org->plan_id)->toBeNull()
            ->and(CarbonImmutable::parse($org->trial_ends_at)->isSameDay(now()->addDays(14)))->toBeTrue();
        $this->asOwner(function () use ($org): void {
            expect(app(PlanLimits::class)->planFor(Organization::query()->findOrFail($org->id))->code)->toBe('business');
        });
    });

    it('enregistre une formule payante : essai avec ses limites, jamais d\'abonnement ni de moyen de paiement ni de facture', function (string $code, int $practitioners): void {
        $this->post('/inscription', $this->validRegistration(['plan' => $code]))->assertRedirect('/verification-email');

        $org = ($this->org)();

        expect($org->signup_plan)->toBe($code)->and($org->status)->toBe('trial')->and($org->plan_id)->toBeNull();
        $this->asOwner(function () use ($org, $code, $practitioners): void {
            $model = Organization::query()->findOrFail($org->id);

            expect(app(PlanLimits::class)->planFor($model)->code)->toBe($code)->and(app(PlanLimits::class)->limit($model, 'practitioners'))->toBe($practitioners)
                ->and(DB::table('subscriptions')->where('org_id', $org->id)->count())->toBe(0)
                ->and(DB::table('payment_methods')->where('org_id', $org->id)->count())->toBe(0)
                ->and(DB::table('invoices')->where('org_id', $org->id)->count())->toBe(0)
                ->and(DB::table('payments')->where('org_id', $org->id)->count())->toBe(0)
                ->and(json_decode((string) DB::table('audit_logs')->where('action', 'account.registered')->where('org_id', $org->id)->value('diff'), true)['plan'])->toBe($code);
        });
    })->with([['basic', 1], ['business', 5]]);

    it('refuse une formule inconnue, retirée ou absente, et ne crée rien', function (array $override, string $message): void {
        $this->asOwner(fn () => DB::table('plans')->where('code', 'business')->update(['is_active' => false]));

        $this->post('/inscription', $this->validRegistration($override))->assertSessionHasErrors(['plan' => $message]);

        expect(($this->orgs)())->toBe(0)->and($this->asOwner(fn () => DB::table('users')->count()))->toBe(0);
    })->with([
        'inconnue' => [['plan' => 'platine'], 'Cette formule n’est plus disponible : choisissez-en une autre.'],
        'retirée' => [['plan' => 'business'], 'Cette formule n’est plus disponible : choisissez-en une autre.'],
        'absente' => [['plan' => ''], 'Choisissez une formule pour continuer.'],
    ]);

    it('un second envoi du même formulaire (rafraîchissement, double clic) ne crée ni second compte ni second abonnement', function (): void {
        $this->post('/inscription', $this->validRegistration(['plan' => 'business']))->assertRedirect('/verification-email');
        $this->post('/inscription', $this->validRegistration(['plan' => 'business']))->assertSessionHasErrors('email');

        expect(($this->orgs)())->toBe(1)->and($this->asOwner(fn () => DB::table('subscriptions')->count()))->toBe(0);
    });

    it('n\'enregistre rien tant que le formulaire n\'est pas validé (abandon avant l\'envoi, acceptations manquantes)', function (): void {
        $this->post('/inscription', $this->validRegistration(['plan' => 'business', 'accept_terms' => false]))->assertSessionHasErrors('accept_terms');

        expect(($this->orgs)())->toBe(0)->and($this->asOwner(fn () => DB::table('subscriptions')->count()))->toBe(0);
    });

    it('ne change rien aux comptes existants : un utilisateur connecté n\'est pas ramené au choix de formule', function (): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount();
        $this->asOwner(fn () => DB::table('organizations')->where('id', $org->id)->update(['signup_plan' => 'basic']));

        $this->signedIn($user, $org)->get('/inscription')->assertRedirect();

        expect($this->asOwner(fn () => DB::table('organizations')->where('id', $org->id)->value('signup_plan')))->toBe('basic');
    });
});
