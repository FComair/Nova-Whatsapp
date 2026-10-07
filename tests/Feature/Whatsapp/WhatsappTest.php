<?php

declare(strict_types=1);

use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Jobs\ForwardInboundMessage;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use App\Modules\Whatsapp\Support\FakeTransport;
use App\Modules\Whatsapp\Support\MetaTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * Passerelle WhatsApp : webhook Meta (vérification, signature, déduplication), STOP/START, statuts, transfert à n8n,
 * envoi par l'API interne avec ses garde-fous, isolation entre établissements.
 */
function waInOrg(Organization $org, callable $callback): mixed
{
    return app(TenantContext::class)->run($org->id, $callback);
}

/** @return array<string, mixed> */
function metaPayload(string $from, ?string $text, string $id = 'wamid.IN1', string $phoneNumberId = 'PN-1', ?string $name = null, string $type = 'text'): array
{
    $message = ['from' => $from, 'id' => $id, 'timestamp' => '1780000000', 'type' => $type];
    $type === 'text' ? $message['text'] = ['body' => $text] : null;

    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '3225550000', 'phone_number_id' => $phoneNumberId],
        'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
        'messages' => [$message],
    ]]]]]];
}

beforeEach(function (): void {
    $this->bookingWorld();
    config(['whatsapp.verify_token' => 'verif-secret', 'whatsapp.app_secret' => 'app-secret']);

    $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'display_phone' => '+3225550000', 'access_token_enc' => 'EAAG-token-de-test']));
    app(TenantContext::class)->clear();

    $this->transport = app(WhatsappTransport::class);

    $this->hook = function (array $payload, ?string $signature = null, ?string $secret = null): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, $secret ?? 'app-secret');

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body);
    };
    $this->receive = fn (string $text, string $id = 'wamid.IN1', string $from = '32470123456') => ($this->hook)(metaPayload($from, $text, $id));

    $issued = waInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'n8n', ApiClient::SCOPES));
    $this->client = $issued['client'];
    $this->secret = $issued['secret'];
    app(TenantContext::class)->clear();
    $this->api = function (string $method, string $uri, array $body = [], ?array $credentials = null): TestResponse {
        $content = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $canonical = implode("\n", [$timestamp, $method, $uri, hash('sha256', $content)]);

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NOVA_KEY' => $credentials['key'] ?? $this->client->key_id, 'HTTP_X_NOVA_TIMESTAMP' => $timestamp,
            'HTTP_X_NOVA_SIGNATURE' => hash_hmac('sha256', $canonical, $credentials['secret'] ?? $this->secret),
        ], $content);
    };
});

describe('vérification du webhook', function (): void {
    it('renvoie le défi quand le jeton est bon', function (): void {
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verif-secret&hub.challenge=12345')->assertOk()->assertSee('12345', false);
    });

    it('refuse un mauvais jeton, un mauvais mode et l\'absence de jeton configuré', function (): void {
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1')->assertForbidden();
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=verif-secret&hub.challenge=1')->assertForbidden();

        config(['whatsapp.verify_token' => null]);
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    });
});

describe('signature des notifications', function (): void {
    it('refuse sans signature, avec une fausse signature ou sans secret configuré', function (): void {
        $payload = metaPayload('32470123456', 'Bonjour');

        $this->postJson('/api/v1/webhooks/whatsapp', $payload)->assertForbidden();
        ($this->hook)($payload, 'sha256='.str_repeat('0', 64))->assertForbidden();
        ($this->hook)($payload, secret: 'autre-secret')->assertForbidden();

        config(['whatsapp.app_secret' => null]);
        ($this->hook)($payload, 'sha256=')->assertForbidden();

        $this->asOwner(fn () => expect(WhatsappMessage::query()->count())->toBe(0));
    });

    it('accepte une signature valide et ignore un autre objet', function (): void {
        ($this->hook)(metaPayload('32470123456', 'Bonjour'))->assertOk()->assertJsonPath('stored', 1);
        ($this->hook)(['object' => 'page', 'entry' => []])->assertOk()->assertJsonPath('status', 'ignored');
    });
});

describe('messages entrants', function (): void {
    it('enregistre le message chiffré, crée le client et transmet à n8n', function (): void {
        Queue::fake();

        ($this->hook)(metaPayload('32470999111', 'Bonjour, un rendez-vous ?', 'wamid.NEW', name: 'Nina'))->assertOk();

        waInOrg($this->org, function (): void {
            $customer = Customer::query()->where('phone_e164', '+32470999111')->sole();
            $message = WhatsappMessage::query()->sole();

            expect($customer->name)->toBe('Nina')
                ->and($message)->direction->toBe('in')->status->toBe('received')->type->toBe('text')->body_enc->toBe('Bonjour, un rendez-vous ?')->wa_message_id->toBe('wamid.NEW')
                ->and($message->customer_id)->toBe($customer->id);
        });

        expect($this->asOwner(fn () => DB::table('whatsapp_messages')->value('body_enc')))->not->toContain('rendez-vous');
        Queue::assertPushed(ForwardInboundMessage::class, fn (ForwardInboundMessage $job): bool => $job->orgId === $this->org->id);
    });

    it('retrouve un client existant sans créer de doublon', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour');

        waInOrg($this->org, fn () => expect(Customer::query()->count())->toBe(1)->and(WhatsappMessage::query()->sole()->customer_id)->toBe($this->customer->id));
    });

    it('ne traite qu\'une fois un message reçu deux fois', function (): void {
        Queue::fake();

        ($this->receive)('Bonjour', 'wamid.DUP')->assertJsonPath('stored', 1);
        ($this->receive)('Bonjour', 'wamid.DUP')->assertJsonPath('stored', 0);

        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->count())->toBe(1));
        Queue::assertPushed(ForwardInboundMessage::class, 1);
    });

    it('enregistre un message non textuel sans texte', function (): void {
        Queue::fake();

        ($this->hook)(metaPayload('32470123456', null, 'wamid.IMG', type: 'image'))->assertOk();

        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->sole())->type->toBe('image')->body_enc->toBeNull());
    });

    it('ignore un numéro Meta inconnu ou désactivé', function (): void {
        Queue::fake();

        ($this->hook)(metaPayload('32470123456', 'Bonjour', phoneNumberId: 'PN-INCONNU'))->assertOk()->assertJsonPath('stored', 0);

        $this->asOwner(fn () => WhatsappAccount::query()->update(['status' => 'disabled']));
        ($this->receive)('Bonjour')->assertJsonPath('stored', 0);

        $this->asOwner(fn () => expect(WhatsappMessage::query()->count())->toBe(0));
        Queue::assertNothingPushed();
    });

    it('ignore un expéditeur invalide', function (): void {
        ($this->hook)(metaPayload('abc', 'Bonjour'))->assertJsonPath('stored', 0);
        ($this->hook)(metaPayload('0470123456', 'Bonjour', 'wamid.X'))->assertJsonPath('stored', 0);
    });

    it('sépare les établissements : un message n\'apparaît que chez son propriétaire', function (): void {
        Queue::fake();
        ($this->receive)('Secret de A');
        $other = $this->asOwner(fn () => Organization::factory()->create());

        waInOrg($other, fn () => expect(WhatsappMessage::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0));
    });
});

describe('STOP et START', function (): void {
    it('désabonne, trace, confirme dans la langue du client et ne transmet pas', function (string $keyword): void {
        Queue::fake();
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());

        ($this->receive)($keyword)->assertOk();

        waInOrg($this->org, fn () => expect(Customer::query()->find($this->customer->id)->whatsapp_opted_out_at)->not->toBeNull());
        expect($this->transport->sent)->toHaveCount(1)->and($this->transport->sent[0]['text'])->toBe('U ontvangt geen berichten meer van ons. Antwoord START om ze opnieuw te ontvangen.')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'customer.whatsapp_opt_out')->where('entity_id', $this->customer->id)->count()))->toBe(1);
        Queue::assertNothingPushed();
    })->with(['STOP', 'Stop.', ' arrêt ', 'ARRET!', 'Stoppen']);

    it('réabonne avec START', function (): void {
        Queue::fake();
        ($this->receive)('STOP', 'w1');
        ($this->receive)('Start', 'w2');

        waInOrg($this->org, fn () => expect(Customer::query()->find($this->customer->id)->whatsapp_opted_out_at)->toBeNull());
        expect($this->transport->sent)->toHaveCount(2)->and($this->transport->sent[1]['text'])->toContain('Merci');
    });

    it('ne prend pas une phrase contenant « stop » pour un désabonnement', function (): void {
        Queue::fake();

        ($this->receive)('Je voudrais que ça stop demain');

        waInOrg($this->org, fn () => expect(Customer::query()->find($this->customer->id)->whatsapp_opted_out_at)->toBeNull());
        Queue::assertPushed(ForwardInboundMessage::class);
    });

    it('ne répond pas automatiquement à un client désabonné qui écrit', function (): void {
        Queue::fake();
        ($this->receive)('STOP', 'w1');
        $this->transport->sent = [];

        ($this->receive)('Bonjour ?', 'w2');

        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->where('direction', 'in')->count())->toBe(2));
        expect($this->transport->sent)->toBe([]);
        Queue::assertNothingPushed();
    });
});

describe('accusés d\'état', function (): void {
    it('fait avancer l\'état sans jamais le faire reculer, et consigne un échec', function (): void {
        ($this->receive)('Bonjour', 'in1');
        $sent = ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour !'])->assertCreated();
        $waId = $this->asOwner(fn () => WhatsappMessage::query()->where('direction', 'out')->value('wa_message_id'));
        $status = fn (string $state, array $extra = []) => ($this->hook)(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PN-1'], 'statuses' => [['id' => $waId, 'status' => $state, ...$extra]]]]]]]]);
        $current = fn (): string => $this->asOwner(fn () => WhatsappMessage::query()->where('direction', 'out')->value('status'));

        $status('delivered');
        expect($current())->toBe('delivered');
        $status('read');
        expect($current())->toBe('read');
        $status('delivered');
        expect($current())->toBe('read');
        $status('failed', ['errors' => [['code' => 131026]]]);
        expect($current())->toBe('failed')->and($this->asOwner(fn () => WhatsappMessage::query()->where('direction', 'out')->value('error_code')))->toBe('meta_131026');
        expect($sent->json('data.status'))->toBe('sent');
    });
});

describe('transfert à n8n', function (): void {
    it('envoie le message signé, sans passer le texte par la file', function (): void {
        config(['whatsapp.n8n.url' => 'https://n8n.test/webhook/nova', 'whatsapp.n8n.signing_secret' => 'n8n-secret']);
        Http::fake(['n8n.test/*' => Http::response(['ok' => true])]);

        ($this->receive)('Je voudrais réserver');

        Http::assertSent(function ($request): bool {
            $expected = hash_hmac('sha256', $request->header('X-Nova-Timestamp')[0].'.'.$request->body(), 'n8n-secret');

            return $request->url() === 'https://n8n.test/webhook/nova'
                && $request->header('X-Nova-Signature')[0] === $expected
                && $request['organization_id'] === $this->org->id
                && $request['customer']['phone'] === '+32470123456'
                && $request['message']['text'] === 'Je voudrais réserver';
        });
    });

    it('ne place que des identifiants dans la file', function (): void {
        Queue::fake();
        ($this->receive)('Texte confidentiel');

        Queue::assertPushed(ForwardInboundMessage::class, fn (ForwardInboundMessage $job): bool => ! str_contains(serialize($job), 'confidentiel'));
    });

    it('ne fait rien sans URL ou sans secret', function (): void {
        Http::fake();
        ($this->receive)('Bonjour', 'a1');

        Http::assertNothingSent();
    });

    it('échoue quand n8n répond en erreur, pour que la file réessaie', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour', 'a2');
        $messageId = $this->asOwner(fn () => WhatsappMessage::query()->value('id'));
        config(['whatsapp.n8n.url' => 'https://n8n.test/webhook/nova', 'whatsapp.n8n.signing_secret' => 'n8n-secret']);
        Http::fake(['n8n.test/*' => Http::response('erreur', 500)]);

        expect(fn () => (new ForwardInboundMessage($this->org->id, $messageId))->handle(app(TenantContext::class)))->toThrow(RuntimeException::class, 'HTTP 500');
    });
});

describe('envoi par l\'API interne', function (): void {
    it('envoie une réponse dans la fenêtre de 24 h et la journalise', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour');

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour ! Que puis-je faire ?'])->assertCreated()->assertJsonPath('data.status', 'sent');

        expect($this->transport)->toBeInstanceOf(FakeTransport::class)->and($this->transport->sent)->toBe([['account' => 'PN-1', 'to' => '+32470123456', 'text' => 'Bonjour ! Que puis-je faire ?']]);
        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->where('direction', 'out')->sole())->status->toBe('sent')->body_enc->toBe('Bonjour ! Que puis-je faire ?'));
    });

    it('refuse hors de la fenêtre de service', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour');
        $this->travel(25)->hours();

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Rappel'])->assertStatus(409)->assertJsonPath('error.code', 'window_closed');
        expect($this->transport->sent)->toBe([]);
    });

    it('refuse sans message préalable du client', function (): void {
        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour'])->assertStatus(409)->assertJsonPath('error.code', 'window_closed');
    });

    it('respecte le STOP du client', function (): void {
        Queue::fake();
        ($this->receive)('STOP');
        $this->transport->sent = [];

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Promo !'])->assertStatus(409)->assertJsonPath('error.code', 'opted_out');
        expect($this->transport->sent)->toBe([]);
    });

    it('refuse sans numéro WhatsApp rattaché ou pour un client inconnu', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour');

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470000000', 'text' => 'Bonjour'])->assertNotFound()->assertJsonPath('error.code', 'customer_not_found');

        $this->asOwner(fn () => WhatsappAccount::query()->delete());
        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour'])->assertStatus(409)->assertJsonPath('error.code', 'no_account');
    });

    it('consigne l\'échec de Meta sans le texte dans l\'erreur', function (): void {
        Queue::fake();
        ($this->receive)('Bonjour');
        $this->transport->failWith = 'meta_131047';

        $response = ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Texte sensible'])->assertStatus(502)->assertJsonPath('error.code', 'delivery_failed');

        expect($response->getContent())->not->toContain('sensible');
        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->where('direction', 'out')->sole())->status->toBe('failed')->error_code->toBe('meta_131047'));
    });

    it('valide le corps et exige le périmètre conversations:write', function (): void {
        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '0470', 'text' => ''])->assertUnprocessable();

        $limited = waInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'lecture', ['config:read']));
        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour'], ['key' => $limited['client']->key_id, 'secret' => $limited['secret']])->assertForbidden();
    });
});

describe('envoi réel (Meta Cloud API)', function (): void {
    it('appelle la Graph API avec le jeton de l\'établissement', function (): void {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.META1']]])]);
        app()->singleton(WhatsappTransport::class, fn () => new MetaTransport);
        ($this->receive)('Bonjour');

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour !'])->assertCreated();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v21.0/PN-1/messages'
            && $request->hasHeader('Authorization', 'Bearer EAAG-token-de-test')
            && $request['to'] === '32470123456' && $request['text']['body'] === 'Bonjour !' && $request['messaging_product'] === 'whatsapp');
        waInOrg($this->org, fn () => expect(WhatsappMessage::query()->where('direction', 'out')->value('wa_message_id'))->toBe('wamid.META1'));
    });

    it('traduit une erreur de Meta en code, sans fuite du contenu', function (): void {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid token']], 401)]);
        app()->singleton(WhatsappTransport::class, fn () => new MetaTransport);
        ($this->receive)('Bonjour');

        ($this->api)('POST', '/api/v1/messages', ['customer_phone' => '+32470123456', 'text' => 'Bonjour !'])->assertStatus(502)->assertJsonPath('error.detail', 'meta_190');
    });

    it('chiffre le jeton d\'accès au repos', function (): void {
        expect($this->asOwner(fn () => DB::table('whatsapp_accounts')->value('access_token_enc')))->not->toContain('EAAG');
    });
});
