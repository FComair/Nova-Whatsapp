<?php

declare(strict_types=1);

use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * API interne v1 : authentification HMAC, périmètres, disponibilités, réservations idempotentes, isolation.
 */
function apiInOrg(Organization $org, callable $callback): mixed
{
    return app(TenantContext::class)->run($org->id, $callback);
}

beforeEach(function (): void {
    $this->bookingWorld();

    $issued = apiInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'n8n', ApiClient::SCOPES));
    $this->client = $issued['client'];
    $this->secret = $issued['secret'];
    app(TenantContext::class)->clear();

    /** Requête signée. `$tweak` permet de falsifier un en-tête pour les tests d'authentification. */
    $this->api = function (string $method, string $uri, array $body = [], array $headers = [], ?array $credentials = null): TestResponse {
        $content = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($headers['timestamp'] ?? CarbonImmutable::now()->getTimestamp());
        $secret = $credentials['secret'] ?? $this->secret;
        $key = $credentials['key'] ?? $this->client->key_id;
        $canonical = implode("\n", [$timestamp, strtoupper($method), $uri, hash('sha256', $content)]);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NOVA_KEY' => $key,
            'HTTP_X_NOVA_TIMESTAMP' => $timestamp,
            'HTTP_X_NOVA_SIGNATURE' => $headers['signature'] ?? hash_hmac('sha256', $canonical, $secret),
        ];

        if (isset($headers['idempotency'])) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $headers['idempotency'];
        }

        return $this->call($method, $uri, [], [], [], $server, $content);
    };

    $this->bookingBody = fn (array $overrides = []): array => [
        'location_id' => $this->location->id,
        'service_id' => $this->service->id,
        'practitioner_id' => $this->practitioner->id,
        'starts_at' => '2026-06-02T10:00:00+02:00',
        'customer_phone' => '+32470123456',
        'source' => 'whatsapp',
        ...$overrides,
    ];
    $this->book = fn (string $key = 'k1', array $overrides = []) => ($this->api)('POST', '/api/v1/bookings', ($this->bookingBody)($overrides), ['idempotency' => $key]);
});

describe('authentification', function (): void {
    it('accepte une requête correctement signée', function (): void {
        ($this->api)('GET', '/api/v1/config')->assertOk();
    });

    it('refuse sans en-têtes, avec une mauvaise signature, une clé inconnue ou révoquée — même réponse', function (): void {
        $bodies = [
            $this->call('GET', '/api/v1/config', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
            ($this->api)('GET', '/api/v1/config', headers: ['signature' => str_repeat('a', 64)]),
            ($this->api)('GET', '/api/v1/config', credentials: ['key' => 'nk_inconnue']),
            ($this->api)('GET', '/api/v1/config', credentials: ['secret' => 'mauvais-secret']),
        ];

        apiInOrg($this->org, fn () => app(ApiClientService::class)->revoke(ApiClient::query()->findOrFail($this->client->id)));
        $bodies[] = ($this->api)('GET', '/api/v1/config');

        foreach ($bodies as $response) {
            $response->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        }

        expect(collect($bodies)->map(fn ($r) => $r->getContent())->unique())->toHaveCount(1);
    });

    it('refuse un horodatage trop ancien ou trop lointain', function (): void {
        ($this->api)('GET', '/api/v1/config', headers: ['timestamp' => CarbonImmutable::now()->getTimestamp() - 301])->assertUnauthorized()->assertJsonPath('error.code', 'timestamp_out_of_range');
        ($this->api)('GET', '/api/v1/config', headers: ['timestamp' => CarbonImmutable::now()->getTimestamp() + 301])->assertUnauthorized()->assertJsonPath('error.code', 'timestamp_out_of_range');
        ($this->api)('GET', '/api/v1/config', headers: ['timestamp' => CarbonImmutable::now()->getTimestamp() - 299])->assertOk();
    });

    it('lie la signature au corps, au chemin et à la méthode', function (): void {
        $body = json_encode(($this->bookingBody)(), JSON_THROW_ON_ERROR);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, 'POST', '/api/v1/bookings', hash('sha256', $body)]), $this->secret);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NOVA_KEY' => $this->client->key_id, 'HTTP_X_NOVA_TIMESTAMP' => $timestamp, 'HTTP_X_NOVA_SIGNATURE' => $signature, 'HTTP_IDEMPOTENCY_KEY' => 'k1'];

        // Corps modifié, chemin ou requête différents : la signature ne correspond plus.
        $this->call('POST', '/api/v1/bookings', [], [], [], $server, str_replace('10:00', '11:00', $body))->assertUnauthorized();
        $this->call('POST', '/api/v1/customers', [], [], [], $server, $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/bookings?x=1', [], [], [], $server, $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/bookings', [], [], [], $server, $body)->assertCreated();
    });

    it('applique les périmètres', function (): void {
        $limited = apiInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'lecture', ['config:read']));

        ($this->api)('GET', '/api/v1/config', credentials: ['key' => $limited['client']->key_id, 'secret' => $limited['secret']])->assertOk();
        ($this->api)('GET', '/api/v1/bookings?customer_phone=%2B32470123456', credentials: ['key' => $limited['client']->key_id, 'secret' => $limited['secret']])->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
    });

    it('vide le contexte d\'organisation après la requête', function (): void {
        ($this->api)('GET', '/api/v1/config')->assertOk();

        expect(app(TenantContext::class)->current())->toBeNull();
    });

    it('chiffre le secret au repos', function (): void {
        $raw = $this->asOwner(fn () => DB::table('api_clients')->where('id', $this->client->id)->value('secret_enc'));

        expect($raw)->not->toContain($this->secret);
    });

    it('n\'accepte que des périmètres connus', function (): void {
        expect(fn () => apiInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'x', ['admin:all'])))->toThrow(InvalidArgumentException::class);
        expect(fn () => apiInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'x', [])))->toThrow(InvalidArgumentException::class);
    });
});

describe('état du compte', function (): void {
    it('passe en lecture seule quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->api)('GET', '/api/v1/config')->assertOk();
        ($this->book)()->assertForbidden()->assertJsonPath('error.code', 'read_only');
    });

    it('refuse tout quand le compte est en pause ou en suppression', function (string $status): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());

        ($this->api)('GET', '/api/v1/config')->assertStatus(503)->assertJsonPath('error.code', 'account_unavailable');
    })->with(['paused', 'pending_deletion']);

    it('fournit au bot le message d\'absence du propriétaire pendant une pause', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'paused', 'unavailable_message' => ['fr' => 'Fermé jusqu\'au 15 juin.']])->save());

        ($this->api)('GET', '/api/v1/config')->assertStatus(503)->assertJsonPath('error.code', 'account_unavailable')->assertJsonPath('error.unavailable_message', 'Fermé jusqu\'au 15 juin.')->assertJsonPath('error.locale', 'fr');
    });

    it('ne fournit pas de message d\'absence hors pause', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'pending_deletion', 'unavailable_message' => ['fr' => 'Secret']])->save());

        ($this->api)('GET', '/api/v1/config')->assertStatus(503)->assertJsonMissingPath('error.unavailable_message');
    });
});

describe('configuration', function (): void {
    it('expose sites, services, praticiens et politiques sans donnée personnelle', function (): void {
        $response = ($this->api)('GET', '/api/v1/config')->assertOk();

        $response->assertJsonPath('data.locations.0.timezone', 'Europe/Brussels')
            ->assertJsonPath('data.locations.0.hours.0', ['weekday' => 1, 'start' => '09:00', 'end' => '17:00'])
            ->assertJsonPath('data.services.0.name', 'Coupe')
            ->assertJsonPath('data.practitioners.0.service_ids', [$this->service->id])
            ->assertJsonPath('data.policies.hold_minutes', 10);

        expect($response->getContent())->not->toContain('@')->not->toContain('+32');
    });
});

describe('disponibilités', function (): void {
    it('renvoie les créneaux réservables en UTC', function (): void {
        $response = ($this->api)('GET', "/api/v1/availability?location_id={$this->location->id}&service_id={$this->service->id}&from=2026-06-02&to=2026-06-02")->assertOk();

        expect($response->json('data'))->toHaveCount(31)
            ->and($response->json('data.0.starts_at'))->toBe('2026-06-02T07:00:00+00:00')
            ->and($response->json('truncated'))->toBeFalse();
    });

    it('valide les paramètres', function (string $query): void {
        ($this->api)('GET', "/api/v1/availability?{$query}")->assertUnprocessable();
    })->with([
        'sans dates' => ['location_id=00000000-0000-4000-8000-000000000000'],
        'fin avant début' => ['from=2026-06-05&to=2026-06-01'],
    ]);

    it('refuse une période de plus de 31 jours', function (): void {
        ($this->api)('GET', "/api/v1/availability?location_id={$this->location->id}&service_id={$this->service->id}&from=2026-06-01&to=2026-07-15")->assertUnprocessable()->assertJsonPath('error.code', 'range_too_large');
    });

    it('refuse un service non réservable en ligne', function (): void {
        $this->asOwner(fn () => $this->service->forceFill(['bookable_online' => false])->save());

        ($this->api)('GET', "/api/v1/availability?location_id={$this->location->id}&service_id={$this->service->id}&from=2026-06-02&to=2026-06-02")->assertUnprocessable()->assertJsonPath('error.code', 'service_unavailable');
    });

    it('ne connaît pas les sites d\'une autre organisation', function (): void {
        $foreign = $this->asOwner(fn () => Location::factory()->create(['org_id' => Organization::factory()->create()->id]));

        ($this->api)('GET', "/api/v1/availability?location_id={$foreign->id}&service_id={$this->service->id}&from=2026-06-02&to=2026-06-02")->assertUnprocessable();
    });
});

describe('clients', function (): void {
    it('crée puis retrouve un client sans doublon', function (): void {
        ($this->api)('POST', '/api/v1/customers', ['phone' => '+32470999888', 'name' => 'Nina', 'language' => 'nl'])->assertCreated()->assertJsonPath('data.language', 'nl');
        ($this->api)('POST', '/api/v1/customers', ['phone' => '+32470999888', 'name' => 'Autre'])->assertOk()->assertJsonPath('data.name', 'Nina');

        apiInOrg($this->org, fn () => expect(Customer::query()->where('phone_e164', '+32470999888')->count())->toBe(1));
    });

    it('exige un numéro E.164', function (): void {
        ($this->api)('POST', '/api/v1/customers', ['phone' => '0470 99 98 88'])->assertUnprocessable();
    });
});

describe('réservation', function (): void {
    it('crée une réservation confirmée, journalisée avec l\'acteur « client »', function (): void {
        $response = ($this->book)()->assertCreated();

        $response->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.starts_at', '2026-06-02T08:00:00+00:00')->assertJsonPath('data.source', 'whatsapp');

        apiInOrg($this->org, function () use ($response): void {
            $event = BookingEvent::query()->where('booking_id', $response->json('data.id'))->sole();

            expect($event->actor_type)->toBe('customer')->and($event->actor_id)->toBe($this->customer->id);
        });
    });

    it('crée le client inconnu à la volée', function (): void {
        ($this->book)('k2', ['customer_phone' => '+32470555666', 'customer_name' => 'Zoé', 'customer_language' => 'fr'])->assertCreated();

        apiInOrg($this->org, fn () => expect(Customer::query()->where('phone_e164', '+32470555666')->value('name'))->toBe('Zoé'));
    });

    it('est idempotente : un rejeu renvoie la même réservation avec 200', function (): void {
        $first = ($this->book)('rejeu')->assertCreated();
        $second = ($this->book)('rejeu')->assertOk();

        expect($second->json('data.id'))->toBe($first->json('data.id'));
        apiInOrg($this->org, fn () => expect(Booking::query()->count())->toBe(1));
    });

    it('refuse la même clé pour une demande différente', function (): void {
        ($this->book)('mêmeclé')->assertCreated();

        ($this->book)('mêmeclé', ['starts_at' => '2026-06-02T14:00:00+02:00'])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    });

    it('exige l\'en-tête Idempotency-Key', function (): void {
        ($this->api)('POST', '/api/v1/bookings', ($this->bookingBody)())->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    });

    it('isole les clés d\'idempotence entre clients d\'API', function (): void {
        $other = apiInOrg($this->org, fn () => app(ApiClientService::class)->issue($this->org, 'autre', ApiClient::SCOPES));
        ($this->book)('partagée')->assertCreated();

        // Autre client d'API, même clé, autre créneau : pas de collision, pas de conflit.
        ($this->api)('POST', '/api/v1/bookings', ($this->bookingBody)(['starts_at' => '2026-06-02T14:00:00+02:00']), ['idempotency' => 'partagée'], ['key' => $other['client']->key_id, 'secret' => $other['secret']])->assertCreated();
    });

    it('renvoie 409 avec 3 créneaux suggérés quand le créneau est pris', function (): void {
        ($this->book)('k1')->assertCreated();

        ($this->book)('k2', ['starts_at' => '2026-06-02T10:15:00+02:00', 'customer_phone' => '+32470777888'])->assertStatus(409)
            ->assertJsonPath('error.code', 'slot_unavailable')->assertJsonPath('error.reason', 'taken')->assertJsonCount(3, 'error.suggestions');
    });

    it('applique le délai minimum aux clients (contrairement au dashboard)', function (): void {
        // 10:00 à Bruxelles, délai de 2 h : 11:00 le jour même est refusé.
        ($this->book)('k1', ['starts_at' => '2026-06-01T11:00:00+02:00'])->assertStatus(409)->assertJsonPath('error.reason', 'policy');
    });

    it('crée un blocage en attente avec échéance, puis le confirme', function (): void {
        $created = ($this->book)('k1', ['hold' => true])->assertCreated()->assertJsonPath('data.status', 'pending');
        expect($created->json('data.expires_at'))->toBe('2026-06-01T08:10:00+00:00');

        ($this->api)('POST', "/api/v1/bookings/{$created->json('data.id')}/confirm", ['customer_phone' => '+32470123456'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    });

    it('refuse de confirmer un blocage expiré', function (): void {
        $created = ($this->book)('k1', ['hold' => true])->assertCreated();
        $this->travel(11)->minutes();

        ($this->api)('POST', "/api/v1/bookings/{$created->json('data.id')}/confirm", ['customer_phone' => '+32470123456'])->assertStatus(422)->assertJsonPath('error.code', 'hold_expired');
    });

    it('refuse un client bloqué', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['is_blocked' => true])->save());

        ($this->book)()->assertForbidden()->assertJsonPath('error.code', 'customer_blocked');
    });

    it('valide le corps', function (array $override): void {
        ($this->book)('k1', $override)->assertUnprocessable();
    })->with([
        'heure sans fuseau' => [['starts_at' => '2026-06-02T10:00:00']],
        'téléphone non E.164' => [['customer_phone' => '0470123456']],
        'source inconnue' => [['source' => 'dashboard']],
        'service inconnu' => [['service_id' => '00000000-0000-4000-8000-000000000000']],
    ]);
});

describe('annulation, déplacement, consultation', function (): void {
    it('permet au client d\'annuler hors de la fenêtre de 24 h', function (): void {
        $id = ($this->book)('k1', ['starts_at' => '2026-06-03T10:00:00+02:00'])->json('data.id');

        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel", ['customer_phone' => '+32470123456', 'reason' => 'Empêchement'])->assertOk()->assertJsonPath('data.status', 'cancelled_by_customer');
    });

    it('refuse l\'annulation par le client dans la fenêtre, mais l\'accepte pour l\'établissement', function (): void {
        $id = ($this->book)('k1', ['starts_at' => '2026-06-02T09:00:00+02:00'])->json('data.id');

        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel", ['customer_phone' => '+32470123456'])->assertStatus(422)->assertJsonPath('error.code', 'cancellation_too_late');
        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel", ['acting_as' => 'business'])->assertOk()->assertJsonPath('data.status', 'cancelled_by_business');
    });

    it('refuse d\'agir pour un autre client', function (): void {
        $id = ($this->book)('k1', ['starts_at' => '2026-06-03T10:00:00+02:00'])->json('data.id');

        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel", ['customer_phone' => '+32470000000'])->assertForbidden()->assertJsonPath('error.code', 'customer_mismatch');
        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel")->assertForbidden();
    });

    it('déplace un rendez-vous, avec suggestions quand le créneau est pris', function (): void {
        $id = ($this->book)('k1', ['starts_at' => '2026-06-03T10:00:00+02:00'])->json('data.id');
        ($this->book)('k2', ['starts_at' => '2026-06-03T14:00:00+02:00', 'customer_phone' => '+32470777888'])->assertCreated();

        ($this->api)('PUT', "/api/v1/bookings/{$id}/move", ['customer_phone' => '+32470123456', 'starts_at' => '2026-06-03T14:15:00+02:00'])->assertStatus(409)->assertJsonCount(3, 'error.suggestions');
        ($this->api)('PUT', "/api/v1/bookings/{$id}/move", ['customer_phone' => '+32470123456', 'starts_at' => '2026-06-04T15:00:00+02:00'])->assertOk()->assertJsonPath('data.starts_at', '2026-06-04T13:00:00+00:00');
    });

    it('liste les prochains rendez-vous d\'un client, sans les annulés', function (): void {
        ($this->book)('k1', ['starts_at' => '2026-06-03T10:00:00+02:00']);
        $cancelled = ($this->book)('k2', ['starts_at' => '2026-06-04T10:00:00+02:00'])->json('data.id');
        ($this->api)('POST', "/api/v1/bookings/{$cancelled}/cancel", ['acting_as' => 'business']);

        $response = ($this->api)('GET', '/api/v1/bookings?customer_phone=%2B32470123456')->assertOk();

        expect($response->json('data'))->toHaveCount(1)->and($response->json('data.0.starts_at'))->toBe('2026-06-03T08:00:00+00:00');
        ($this->api)('GET', '/api/v1/bookings?customer_phone=%2B32470000000')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('isolation entre organisations', function (): void {
    it('répond 404 pour la réservation d\'une autre organisation', function (): void {
        $id = ($this->book)('k1', ['starts_at' => '2026-06-03T10:00:00+02:00'])->json('data.id');
        $otherOrg = $this->asOwner(fn () => Organization::factory()->create());
        $other = apiInOrg($otherOrg, fn () => app(ApiClientService::class)->issue($otherOrg, 'autre', ApiClient::SCOPES));
        $credentials = ['key' => $other['client']->key_id, 'secret' => $other['secret']];

        ($this->api)('POST', "/api/v1/bookings/{$id}/cancel", ['acting_as' => 'business'], credentials: $credentials)->assertNotFound();
        ($this->api)('GET', '/api/v1/bookings?customer_phone=%2B32470123456', credentials: $credentials)->assertOk()->assertJsonCount(0, 'data');
        ($this->api)('GET', '/api/v1/config', credentials: $credentials)->assertOk()->assertJsonCount(0, 'data.services');
    });
});

it('utilise le cœur de réservation : la contrainte anti double réservation reste la dernière défense', function (): void {
    ($this->book)('k1')->assertCreated();

    apiInOrg($this->org, function (): void {
        $booking = Booking::query()->sole();

        expect(fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00', key: 'direct')))->toThrow(SlotUnavailable::class)
            ->and($booking->source)->toBe('whatsapp');
    });
});
