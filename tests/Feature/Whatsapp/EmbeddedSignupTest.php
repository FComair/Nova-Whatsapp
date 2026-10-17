<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Inscription intégrée de Meta (Embedded Signup) : échange du code contre un jeton côté serveur, enregistrement, abonnement aux webhooks.
 * Les réponses de Meta sont simulées : les appels réels restent à valider sur un compte de test (docs/17).
 */
const ES_SECRET = 'secret-application-meta-ne-jamais-exposer';
const ES_TOKEN = 'EAAGjeton-issu-du-code-1234567890';

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore']);
    $this->transport = app(WhatsappTransport::class);
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->configure = fn () => config(['whatsapp.embedded_signup.app_id' => '555000111', 'whatsapp.embedded_signup.config_id' => '777000222', 'whatsapp.app_secret' => ES_SECRET]);
    $this->finish = fn (array $overrides = []) => ($this->as)()->post('/whatsapp/meta', ['code' => 'code-a-usage-unique', 'phone_number_id' => '104500000000001', 'waba_id' => '204500000000002', ...$overrides]);
    $this->account = fn () => DB::connection('pgsql_owner')->table('whatsapp_accounts')->where('org_id', $this->org->id)->first();
    $this->graph = fn (array $oauth = ['access_token' => ES_TOKEN], int $status = 200) => Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response($oauth, $status),
        'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
    ]);
});

describe('disponibilité', function (): void {
    it('n\'est proposée que lorsque l\'application Meta est configurée', function (): void {
        ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('embedded', null));

        ($this->configure)();

        ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('embedded', ['app_id' => '555000111', 'config_id' => '777000222', 'version' => config('whatsapp.graph_version')]));
    });

    it('n\'expose jamais le secret de l\'application à la page', function (): void {
        ($this->configure)();

        expect(($this->as)()->get('/whatsapp')->getContent())->not->toContain(ES_SECRET);
    });

    it('refuse quand elle n\'est pas configurée', function (): void {
        Http::fake();

        ($this->finish)()->assertSessionHasErrors(['whatsapp' => 'La connexion avec Meta n\'est pas encore disponible.']);

        Http::assertNothingSent();
        expect(($this->account)())->toBeNull();
    });

    it('est réservée au propriétaire', function (): void {
        ($this->configure)();
        ['user' => $manager, 'org' => $managerOrg] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $managerOrg)->post('/whatsapp/meta', ['code' => 'c', 'phone_number_id' => '104500000000001', 'waba_id' => '204500000000002'])->assertForbidden();
    });
});

describe('inscription', function (): void {
    beforeEach(fn () => ($this->configure)());

    it('échange le code côté serveur, enregistre le compte avec le jeton obtenu et abonne les webhooks', function (): void {
        ($this->graph)();

        ($this->finish)()->assertSessionHasNoErrors()->assertSessionHas('status', 'WhatsApp est connecté.');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/access_token') && $request['client_id'] === '555000111' && $request['client_secret'] === ES_SECRET && $request['code'] === 'code-a-usage-unique');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/204500000000002/subscribed_apps') && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer '.ES_TOKEN));

        $account = ($this->account)();
        expect($account)->phone_number_id->toBe('104500000000001')->waba_id->toBe('204500000000002')->status->toBe('active')
            ->and($account->access_token_enc)->not->toContain('EAAGjeton')
            ->and($this->asOwner(fn () => WhatsappAccount::query()->sole()->access_token_enc))->toBe(ES_TOKEN)
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.connected')->count()))->toBe(1);
    });

    it('ne renvoie jamais le jeton obtenu', function (): void {
        ($this->graph)();
        ($this->finish)();

        expect(($this->as)()->get('/whatsapp')->getContent())->not->toContain('EAAGjeton')->not->toContain(ES_SECRET);
    });

    it('refuse un code que Meta n\'accepte pas, sans rien enregistrer', function (): void {
        ($this->graph)(['error' => ['message' => 'code expiré', 'code' => 100]], 400);

        ($this->finish)()->assertSessionHasErrors(['whatsapp' => 'Meta n\'a pas accepté ces identifiants : vérifiez l\'identifiant du numéro, celui du compte WhatsApp Business et le jeton.']);

        expect(($this->account)())->toBeNull();
    });

    it('refuse une réponse sans jeton', function (): void {
        ($this->graph)(['pas_de_jeton' => true]);

        ($this->finish)()->assertSessionHasErrors('whatsapp');

        expect(($this->account)())->toBeNull();
    });

    it('signale Meta injoignable', function (): void {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        ($this->finish)()->assertSessionHasErrors(['whatsapp' => 'Meta est injoignable pour le moment. Réessayez dans quelques minutes.']);

        expect(($this->account)())->toBeNull();
    });

    it('n\'abonne rien quand la vérification du numéro échoue', function (): void {
        ($this->graph)();
        $this->transport->failVerifyWith = 'meta_190';

        ($this->finish)()->assertSessionHasErrors('whatsapp');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'subscribed_apps'));
        expect(($this->account)())->toBeNull();
        $this->transport->failVerifyWith = null;
    });

    it('garde la connexion quand l\'abonnement aux webhooks est momentanément impossible', function (): void {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => ES_TOKEN]),
            'graph.facebook.com/*/subscribed_apps' => fn () => throw new ConnectionException('timeout'),
        ]);

        ($this->finish)()->assertSessionHasNoErrors();

        expect(($this->account)())->not->toBeNull();
    });

    it('refuse un numéro déjà connecté à un autre établissement', function (): void {
        ($this->graph)();
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $other->id, 'phone_number_id' => '104500000000001', 'access_token_enc' => 'x']));

        ($this->finish)()->assertSessionHasErrors(['whatsapp' => 'Ce numéro est déjà connecté à un autre établissement.']);

        expect(($this->account)())->toBeNull();
    });

    it('valide ce que le navigateur renvoie', function (array $override, string $field): void {
        ($this->graph)();

        ($this->finish)($override)->assertSessionHasErrors($field);

        expect(($this->account)())->toBeNull();
        Http::assertNothingSent();
    })->with([
        'code absent' => [['code' => ''], 'code'],
        'numéro non numérique' => [['phone_number_id' => 'abc'], 'phone_number_id'],
        'compte non numérique' => [['waba_id' => '../x'], 'waba_id'],
    ]);
});

describe('politique de contenu', function (): void {
    it('autorise le module de Meta uniquement sur les pages de connexion WhatsApp, quand l\'application est configurée', function (): void {
        ($this->configure)();

        $csp = ($this->as)()->get('/whatsapp')->headers->get('Content-Security-Policy-Report-Only');
        expect($csp)->toContain('script-src \'self\' https://connect.facebook.net')->toContain('frame-src \'self\' https://www.facebook.com https://web.facebook.com')->toContain('https://graph.facebook.com');

        $elsewhere = ($this->as)()->get('/tableau-de-bord')->headers->get('Content-Security-Policy-Report-Only');
        expect($elsewhere)->not->toContain('facebook')->toContain('frame-src \'self\'');
    });

    it('laisse la fenêtre de Meta répondre à la page de connexion WhatsApp, et seulement à elle', function (): void {
        ($this->configure)();

        expect(($this->as)()->get('/whatsapp')->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin-allow-popups')
            ->and(($this->as)()->get('/tableau-de-bord')->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
    });

    it('n\'ouvre rien à Meta tant que l\'application n\'est pas configurée', function (): void {
        expect(($this->as)()->get('/whatsapp')->headers->get('Content-Security-Policy-Report-Only'))->not->toContain('facebook');
    });
});
