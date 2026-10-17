<?php

declare(strict_types=1);

use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Services\CalendarFeedService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Notifications\ConfirmDeletionNotification;
use App\Modules\Privacy\Notifications\DeletionCompletedNotification;
use App\Modules\Privacy\Services\DeletionPipeline;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * « Mes données » : export, demande et annulation de suppression, effacement d'un client final (E14).
 */
const OWNER_PASSWORD = 'un-mot-de-passe-solide-42';

beforeEach(function (): void {
    Notification::fake();
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(['name' => 'Salon Aurore']);
    $this->asOwner(fn () => $this->owner->forceFill(['password' => bcrypt(OWNER_PASSWORD)])->save());
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();
    RateLimiter::clear('account-export:'.$this->owner->id);
    RateLimiter::clear('account-data-password:'.$this->owner->id);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->member = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });
    $this->setStatus = fn (string $status, array $extra = []) => $this->asOwner(fn () => $this->org->forceFill(['status' => $status, ...$extra])->save());
    $this->orgRow = fn () => DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->first();
    // Suppression en deux étapes : demande (mot « SUPPRIMER » + mot de passe), puis lien reçu par e-mail.
    $this->request = fn (array $overrides = []) => ($this->as)()->post('/compte/suppression', ['password' => OWNER_PASSWORD, 'confirm_word' => 'SUPPRIMER', 'confirm' => true, ...$overrides]);
    $this->token = function (): string {
        $tokens = [];
        Notification::assertSentOnDemand(ConfirmDeletionNotification::class, function ($notification) use (&$tokens): bool {
            $tokens[] = (new ReflectionProperty($notification, 'token'))->getValue($notification);

            return true;
        });

        return (string) end($tokens);
    };
    $this->confirm = fn (?string $token = null) => ($this->as)()->get(URL::temporarySignedRoute('account.deletion.confirm', now()->addHour(), ['token' => $token ?? ($this->token)()]));
    $this->schedule = function (): void {
        ($this->request)();
        ($this->confirm)();
    };
});

describe('page', function (): void {
    it('affiche l\'état de la suppression au propriétaire', function (): void {
        ($this->as)()->get('/compte/donnees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Account/Data')
            ->where('organizationName', 'Salon Aurore')
            ->where('canRequestDeletion', true)
            ->where('canCancelDeletion', false)
            ->where('deletionDelayDays', 30));
    });

    it('est réservée au propriétaire', function (string $role): void {
        ($this->as)(($this->member)($role))->get('/compte/donnees')->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('reste accessible quand le compte est suspendu, en pause ou en suppression, alors que le reste est fermé', function (string $status): void {
        ($this->setStatus)($status);

        ($this->as)()->get('/compte/donnees')->assertOk();
    })->with(['suspended', 'paused', 'pending_deletion']);

    it('exige d\'être connecté', function (): void {
        $this->get('/compte/donnees')->assertRedirect('/connexion');
    });
});

describe('export', function (): void {
    beforeEach(function (): void {
        app(TenantContext::class)->run($this->org->id, function (): void {
            app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-02 10:00'), 'dashboard', Actor::user(null), notes: 'Allergie au latex'));
            WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'wa_message_id' => 'w1', 'type' => 'text', 'body_enc' => 'Bonjour, je voudrais un rendez-vous', 'status' => 'received']);
            $issued = app(ApiClientService::class)->issue($this->org, 'n8n', ApiClient::SCOPES);
            $this->apiSecret = $issued['secret'];
            $this->apiKey = $issued['client']->key_id;
            $this->feedToken = app(CalendarFeedService::class)->tokenFor($this->org, $this->practitioner);
            ExternalCalendar::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'name' => 'Google', 'kind' => 'ics', 'url_enc' => 'https://calendar.example.com/private/jeton-secret-123/basic.ics', 'username_enc' => 'utilisateur-secret']);
        });
        $this->asOwner(fn () => Organization::factory()->create(['name' => 'Cabinet Voisin']));
    });

    it('exige le mot de passe actuel', function (): void {
        ($this->as)()->post('/compte/donnees/export', ['password' => 'pas-le-bon'])->assertSessionHasErrors(['password' => 'Mot de passe incorrect.']);
        ($this->as)()->post('/compte/donnees/export', [])->assertSessionHasErrors('password');
    });

    it('télécharge un fichier JSON valide avec les données déchiffrées de l\'organisation', function (): void {
        $response = ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD]);

        $response->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
        expect($response->headers->get('Content-Disposition'))->toContain('rendivoo-export-'.now()->format('Ymd').'.json');

        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($data['organization']['name'])->toBe('Salon Aurore')
            ->and($data['customers'])->toHaveCount(1)->and($data['customers'][0]['phone_e164'])->toBe('+32470123456')
            ->and($data['bookings'])->toHaveCount(1)->and($data['bookings'][0]['notes_enc'])->toBe('Allergie au latex')
            ->and($data['whatsapp_messages'][0]['body_enc'])->toBe('Bonjour, je voudrais un rendez-vous')
            ->and($data['services'][0]['name'])->toBe('Coupe')
            ->and(collect($data['team'])->pluck('email')->all())->toContain('proprio@example.com')
            ->and($data['external_calendars'][0])->toBe(['name' => 'Google', 'kind' => 'ics', 'status' => 'active', 'practitioner_id' => $this->practitioner->id]);
    });

    it('n\'exporte jamais un secret ni les données d\'une autre organisation', function (): void {
        $json = ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->streamedContent();

        expect($json)->not->toContain($this->apiSecret)->not->toContain($this->apiKey)->not->toContain($this->feedToken)->not->toContain('jeton-secret-123')->not->toContain('utilisateur-secret')
            ->not->toContain('Cabinet Voisin')->not->toContain($this->owner->password)->not->toContain('idempotency_key');
    });

    it('trace l\'export sans son contenu', function (): void {
        ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->streamedContent();

        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.exported')->sole());

        expect($audit)->actor_id->toBe($this->owner->id)->and($audit->diff)->toBeNull();
    });

    it('est limité à 3 exports par heure', function (): void {
        foreach (range(1, 3) as $ignored) {
            ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->assertOk()->streamedContent();
        }

        ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->assertSessionHasErrors(['password' => 'Trois exports par heure au plus. Réessayez plus tard.']);
    });

    it('bloque après 5 mots de passe faux, même avec le bon ensuite', function (): void {
        foreach (range(1, 5) as $ignored) {
            ($this->as)()->post('/compte/donnees/export', ['password' => 'nope']);
        }

        ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->assertSessionHasErrors('password');
    });

    it('reste possible pendant une suspension ou une suppression programmée', function (string $status): void {
        ($this->setStatus)($status);

        ($this->as)()->post('/compte/donnees/export', ['password' => OWNER_PASSWORD])->assertOk()->streamedContent();
    })->with(['suspended', 'paused', 'pending_deletion']);

    it('est réservé au propriétaire', function (): void {
        ($this->as)(($this->member)('site_manager'))->post('/compte/donnees/export', ['password' => 'x'])->assertForbidden();
    });
});

describe('demande de suppression (en deux étapes)', function (): void {
    it('envoie un lien de confirmation sans rien programmer', function (): void {
        ($this->request)()->assertSessionHasNoErrors()->assertSessionHas('status', 'Un e-mail de confirmation vous a été envoyé : cliquez sur le lien (valable 24 h) pour programmer la suppression.');

        expect(($this->orgRow)())->status->toBe('trial')->deletion_scheduled_for->toBeNull()->deletion_confirm_token_hash->not->toBeNull();
        Notification::assertSentOnDemand(ConfirmDeletionNotification::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
        Notification::assertNotSentTo($this->owner, AccountStatusNotification::class);
    });

    it('ne conserve que l\'empreinte du jeton', function (): void {
        ($this->request)();

        expect(($this->orgRow)()->deletion_confirm_token_hash)->toBe(hash('sha256', ($this->token)()))->not->toContain(($this->token)());
    });

    it('programme la suppression au clic sur le lien : délai des paramètres, statut mémorisé, audit, e-mail', function (): void {
        ($this->request)();

        ($this->confirm)()->assertRedirect('/compte/donnees')->assertSessionHas('status', 'Suppression programmée. Un e-mail de confirmation vous a été envoyé.');

        $org = ($this->orgRow)();

        expect($org)->status->toBe('pending_deletion')->previous_status->toBe('trial')->deletion_confirm_token_hash->toBeNull()->deletion_confirm_expires_at->toBeNull()
            ->and(now()->addDays(30)->diffInHours($org->deletion_scheduled_for, false))->toBeLessThan(1)
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.deletion_requested')->count()))->toBe(1)
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.deletion_confirmation_sent')->count()))->toBe(1);
        Notification::assertSentOnDemand(AccountStatusNotification::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    });

    it('refuse sans confirmation, avec un mauvais mot ou un mauvais mot de passe', function (array $override, string $field): void {
        ($this->request)($override)->assertSessionHasErrors($field);

        expect(($this->orgRow)())->status->toBe('trial')->deletion_confirm_token_hash->toBeNull();
        Notification::assertNothingSent();
    })->with([
        'case décochée' => [['confirm' => false], 'confirm'],
        'mot différent' => [['confirm_word' => 'supprimer'], 'confirm_word'],
        'ancienne méthode (nom)' => [['confirm_word' => 'Salon Aurore'], 'confirm_word'],
        'mot absent' => [['confirm_word' => ''], 'confirm_word'],
        'mauvais mot de passe' => [['password' => 'nope'], 'password'],
    ]);

    it('attend le mot dans la langue de l\'interface', function (): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'en'])->save());

        ($this->request)(['confirm_word' => 'DELETE'])->assertSessionHasNoErrors();
        expect(($this->orgRow)()->deletion_confirm_token_hash)->not->toBeNull();
    });

    it('refuse un lien falsifié, périmé, déjà utilisé ou remplacé', function (): void {
        ($this->request)();
        $token = ($this->token)();

        ($this->confirm)('faux-jeton')->assertSessionHasErrors(['deletion' => 'Ce lien de confirmation est invalide ou expiré. Refaites la demande de suppression.']);
        expect(($this->orgRow)()->status)->toBe('trial');

        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_confirm_expires_at' => now()->subMinute()]);
        ($this->confirm)($token)->assertSessionHasErrors('deletion');
        expect(($this->orgRow)()->status)->toBe('trial');

        // Nouvelle demande : l'ancien lien ne fonctionne plus, le nouveau oui, une seule fois.
        ($this->request)();
        ($this->confirm)($token)->assertSessionHasErrors('deletion');
        ($this->confirm)()->assertSessionHasNoErrors();
        expect(($this->orgRow)()->status)->toBe('pending_deletion');
    });

    it('refuse un lien non signé', function (): void {
        ($this->request)();

        ($this->as)()->get('/compte/suppression/confirmer/'.($this->token)())->assertForbidden();
        expect(($this->orgRow)()->status)->toBe('trial');
    });

    it('est possible depuis un compte suspendu, pas depuis un compte déjà en suppression', function (): void {
        ($this->setStatus)('suspended');
        ($this->schedule)();
        expect(($this->orgRow)())->status->toBe('pending_deletion')->previous_status->toBe('suspended');

        ($this->request)()->assertForbidden();
    });

    it('est réservée au propriétaire (étape 1)', function (): void {
        ($this->as)(($this->member)('site_manager'))->post('/compte/suppression', ['password' => 'x', 'confirm_word' => 'SUPPRIMER', 'confirm' => true])->assertForbidden();
    });

    it('est réservée au propriétaire (étape 2)', function (): void {
        ($this->request)()->assertSessionHas('status');
        $token = ($this->token)();

        $this->flushSession();
        $this->signedIn(($this->member)('practitioner'), $this->org)->get(URL::temporarySignedRoute('account.deletion.confirm', now()->addHour(), ['token' => $token]))->assertForbidden();
        expect(($this->orgRow)()->status)->toBe('trial');
    });
    it('ferme le reste de l\'espace une fois la suppression programmée', function (): void {
        ($this->schedule)();

        ($this->as)()->get('/planning')->assertRedirect(route('account.state'));
        ($this->as)()->get('/compte/donnees')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'pending_deletion')->where('canCancelDeletion', true)->where('canRequestDeletion', false));
    });
});

describe('annulation', function (): void {
    it('rétablit le compte tant que l\'effacement n\'a pas commencé', function (): void {
        ($this->schedule)();

        ($this->as)()->delete('/compte/suppression')->assertSessionHas('status', 'Suppression annulée : votre compte est rétabli.');

        expect(($this->orgRow)())->status->toBe('trial')->previous_status->toBeNull()->deletion_scheduled_for->toBeNull()->deletion_requested_at->toBeNull();
        Notification::assertSentOnDemandTimes(AccountStatusNotification::class, 2);
    });

    it('rétablit un compte suspendu dans son état d\'avant', function (): void {
        ($this->setStatus)('suspended');
        ($this->schedule)();

        ($this->as)()->delete('/compte/suppression');

        expect(($this->orgRow)()->status)->toBe('suspended');
    });

    it('est refusée quand l\'effacement a commencé', function (): void {
        ($this->schedule)();
        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_started_at' => now()]);

        ($this->as)()->delete('/compte/suppression')->assertSessionHasErrors(['deletion' => 'L\'effacement a déjà commencé : la suppression ne peut plus être annulée.']);

        expect(($this->orgRow)()->status)->toBe('pending_deletion');
        ($this->as)()->get('/compte/donnees')->assertInertia(fn (Assert $page) => $page->where('canCancelDeletion', false)->where('deletionStarted', true));
    });

    it('n\'a de sens que pour un compte en suppression', function (): void {
        ($this->as)()->delete('/compte/suppression')->assertForbidden();
    });
});

describe('de la demande à l\'effacement', function (): void {
    it('efface réellement les données à l\'échéance, puis interdit l\'annulation', function (): void {
        ($this->schedule)();
        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_scheduled_for' => now()->subMinute()]);

        expect(app(DeletionPipeline::class)->run())->toBe(1);

        expect(DB::connection('pgsql_owner')->table('customers')->where('org_id', $this->org->id)->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('users')->where('email', 'proprio@example.com')->count())->toBe(0)
            ->and(($this->orgRow)())->status->toBe('deleted')->name->toBe('Organisation supprimée');
        Notification::assertSentOnDemand(DeletionCompletedNotification::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    });
});

describe('effacement d\'un client final (E14)', function (): void {
    beforeEach(function (): void {
        $this->erase = fn (?Customer $customer = null, ?User $user = null) => ($this->as)($user)->post('/clients/'.($customer ?? $this->customer)->id.'/effacement');
        $this->seed = function (): void {
            $this->asOwner(fn () => $this->customer->forceFill(['name' => 'Camille Dupont', 'email' => 'camille@example.com', 'language' => 'fr', 'tags' => ['vip']])->save());
            app(TenantContext::class)->run($this->org->id, function (): void {
                $past = app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-01 11:00'), 'dashboard', Actor::user(null), enforcePolicies: false, notes: 'Allergie au latex'));
                WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'wa_message_id' => 'w1', 'type' => 'text', 'body_enc' => 'Bonjour', 'status' => 'received']);
                $this->past = $past;
            });
            $this->asOwner(fn () => $this->customer->forceFill(['is_blocked' => true])->save());
            $this->travelTo(now()->addDays(2)); // le rendez-vous est passé
        };
    });

    it('efface les données personnelles et garde l\'historique sans lien avec une personne', function (): void {
        ($this->seed)();

        ($this->erase)()->assertRedirect('/clients')->assertSessionHas('status', 'Données du client effacées.');

        $row = DB::connection('pgsql_owner')->table('customers')->where('id', $this->customer->id)->first();

        expect($row)->name->toBeNull()->email->toBeNull()->language->toBeNull()->is_blocked->toBeFalse()->and(json_decode($row->tags))->toBe([])
            ->and($row->phone_e164)->toMatch('/^\+999\d{9}$/')->not->toBe('+32470123456')
            ->and($row->anonymized_at)->not->toBeNull()->and($row->deleted_at)->not->toBeNull()
            ->and(DB::connection('pgsql_owner')->table('whatsapp_messages')->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('bookings')->count())->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('bookings')->value('notes_enc'))->toBeNull();
    });

    it('trace l\'effacement sans nom ni numéro', function (): void {
        ($this->seed)();
        ($this->erase)();

        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'customer.erased')->sole());

        expect($audit)->entity_id->toBe($this->customer->id)->and(json_encode($audit))->not->toContain('Camille')->not->toContain('470123456');
    });

    it('sort le client de la liste, et le même numéro devient un nouveau client', function (): void {
        ($this->seed)();
        ($this->erase)();

        ($this->as)()->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 0));
        ($this->as)()->get("/clients/{$this->customer->id}")->assertNotFound();

        $again = app(TenantContext::class)->run($this->org->id, fn () => app(CustomerDirectory::class)->findOrCreate($this->org, '+32470123456', 'Camille'));

        expect($again->id)->not->toBe($this->customer->id);
    });

    it('refuse tant qu\'un rendez-vous à venir existe, puis accepte après son annulation', function (): void {
        ($this->seed)();
        $this->asOwner(fn () => $this->customer->forceFill(['is_blocked' => false])->save());
        $future = app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-08 10:00'), 'dashboard', Actor::user(null), enforcePolicies: false)));

        ($this->erase)()->assertSessionHasErrors(['erase' => 'Ce client a encore des rendez-vous à venir : annulez-les avant d\'effacer ses données.']);
        expect(DB::connection('pgsql_owner')->table('customers')->where('id', $this->customer->id)->value('name'))->toBe('Camille Dupont');

        app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->cancel($this->org, $future, Actor::user(null)));

        ($this->erase)()->assertSessionHasNoErrors();
    });

    it('est réservé au propriétaire et au responsable de site', function (string $role, bool $allowed): void {
        ($this->seed)();
        $user = ($this->member)($role);

        $response = ($this->erase)(null, $user);

        $allowed ? $response->assertRedirect('/clients') : $response->assertForbidden();
    })->with([['site_manager', true], ['receptionist', false], ['practitioner', false]]);

    it('répond 404 pour un client d\'une autre organisation', function (): void {
        ($this->seed)();
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->post("/clients/{$this->customer->id}/effacement")->assertNotFound();
    });

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        ($this->seed)();
        ($this->setStatus)('suspended');

        ($this->erase)()->assertRedirect(route('account.state'));
    });
});
