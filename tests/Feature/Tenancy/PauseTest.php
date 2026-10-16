<?php

declare(strict_types=1);

use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Billing\Models\Plan;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Notifications\PauseNotification;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Pause d'établissement (flowchart 4B) : non facturée, 90 jours au plus, message d'absence, fin automatique.
 */
beforeEach(function (): void {
    Notification::fake();
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'active']);
    $this->asOwner(fn () => $this->owner->forceFill(['password' => bcrypt('Mdp-Du-Proprietaire-1!')])->save());
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();
    RateLimiter::clear('pause-password:'.$this->owner->id);
    $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']));
    $this->transport = app(WhatsappTransport::class);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->orgRow = fn () => DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->first();
    $this->pause = fn (array $overrides = []) => ($this->as)()->post('/compte/pause', ['days' => 30, 'message' => 'Fermé, réouverture bientôt.', 'password' => 'Mdp-Du-Proprietaire-1!', 'confirm' => true, ...$overrides]);
    $this->book = fn (string $local) => app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels($local), 'dashboard', Actor::user(null), enforcePolicies: false)));
});

describe('page', function (): void {
    it('affiche les réglages au propriétaire', function (): void {
        ($this->as)()->get('/compte/pause')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Account/Pause')->where('canPause', true)->where('maxDays', 90)->where('status', 'active')->where('upcoming', 0));
    });

    it('est réservée au propriétaire', function (string $role): void {
        ['user' => $member, 'org' => $memberOrg] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($member, $memberOrg)->get('/compte/pause')->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('compte les rendez-vous à venir', function (): void {
        ($this->book)('2026-06-02 10:00');

        ($this->as)()->get('/compte/pause')->assertInertia(fn (Assert $page) => $page->where('upcoming', 1));
    });
});

describe('mise en pause', function (): void {
    it('met le compte en pause, mémorise le statut, la durée et le message d\'absence, trace et prévient le propriétaire', function (): void {
        ($this->pause)()->assertSessionHasNoErrors()->assertRedirect('/compte');

        $org = ($this->orgRow)();

        expect($org)->status->toBe('paused')->previous_status->toBe('active')
            ->and(now()->addDays(30)->diffInMinutes($org->pause_until, false))->toBeLessThan(1)
            ->and(json_decode($org->unavailable_message, true))->toBe(['fr' => 'Fermé, réouverture bientôt.'])
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.paused')->count()))->toBe(1);
        Notification::assertSentOnDemand(PauseNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    });

    it('fonctionne sans message d\'absence', function (): void {
        ($this->pause)(['message' => ''])->assertSessionHasNoErrors();

        expect(($this->orgRow)())->status->toBe('paused')->unavailable_message->toBeNull();
    });

    it('depuis un essai ou un impayé aussi', function (string $status): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());

        ($this->pause)()->assertSessionHasNoErrors();

        expect(($this->orgRow)())->status->toBe('paused')->previous_status->toBe($status);
    })->with(['trial', 'past_due']);

    it('refuse une durée hors bornes, sans mot de passe valide ou sans case cochée', function (array $override, string $field): void {
        ($this->pause)($override)->assertSessionHasErrors($field);

        expect(($this->orgRow)()->status)->toBe('active');
        Notification::assertNothingSent();
    })->with([
        'zéro jour' => [['days' => 0], 'days'],
        'plus que le maximum' => [['days' => 91], 'days'],
        'durée absente' => [['days' => ''], 'days'],
        'mauvais mot de passe' => [['password' => 'nope'], 'password'],
        'case décochée' => [['confirm' => false], 'confirm'],
        'message trop long' => [['message' => str_repeat('a', 501)], 'message'],
    ]);

    it('respecte la durée maximale des paramètres', function (): void {
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'pause_max_days')->update(['value' => '10']);

        ($this->pause)(['days' => 11])->assertSessionHasErrors('days');
        ($this->pause)(['days' => 10])->assertSessionHasNoErrors();

        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'pause_max_days')->update(['value' => '90']);
    });

    it('est refusée tant qu\'un rendez-vous est prévu pendant la pause', function (): void {
        ($this->book)('2026-06-10 10:00');

        ($this->pause)()->assertSessionHasErrors(['pause' => '1 rendez-vous sont prévus pendant la pause : cochez l\'option d\'annulation ou annulez-les d\'abord.']);

        expect(($this->orgRow)()->status)->toBe('active');
    });

    it('ignore les rendez-vous annulés ou postérieurs à la pause', function (): void {
        $booking = ($this->book)('2026-06-03 10:00');
        app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->cancel($this->org, $booking, Actor::user(null)));
        ($this->book)('2026-06-12 10:00');

        ($this->pause)(['days' => 5])->assertSessionHasNoErrors();

        expect(($this->orgRow)()->status)->toBe('paused');
    });
    it('annule les rendez-vous prévus et prévient les clients par WhatsApp quand le propriétaire le choisit', function (): void {
        $booking = ($this->book)('2026-06-10 10:00');
        ($this->book)('2026-09-01 10:00');
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());

        ($this->pause)(['days' => 20, 'cancel_bookings' => true])->assertSessionHasNoErrors()->assertSessionHas('status', 'Votre compte est en pause. 1 rendez-vous annulés, 1 client(s) prévenu(s) par WhatsApp.')->assertSessionMissing('warning');

        expect(($this->orgRow)()->status)->toBe('paused')
            ->and(DB::connection('pgsql_owner')->table('bookings')->where('id', $booking->id)->value('status'))->toBe('cancelled_by_business')
            ->and(DB::connection('pgsql_owner')->table('bookings')->where('status', 'confirmed')->count())->toBe(1)
            ->and($this->transport->templates)->toHaveCount(1)
            ->and($this->transport->templates[0])->toMatchArray(['to' => '+32470123456', 'template' => 'appointment_cancelled', 'language' => 'nl'])
            ->and($this->transport->templates[0]['parameters'])->toBe([$this->org->name, 'Coupe', 'woensdag 10/06/2026', '10:00']);

        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.paused')->sole());
        expect(json_decode($audit->diff, true))->toMatchArray(['bookings_cancelled' => 1]);
    });

    it('signale les clients qu\'il n\'a pas pu prévenir (désinscrits ou sans WhatsApp)', function (): void {
        ($this->book)('2026-06-10 10:00');
        $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => now()])->save());

        ($this->pause)(['days' => 20, 'cancel_bookings' => true])->assertSessionHas('warning', '1 client(s) n\'ont pas pu être prévenus par WhatsApp (désinscrits ou injoignables) : contactez-les directement.');

        expect(($this->orgRow)()->status)->toBe('paused')->and($this->transport->templates)->toBe([]);
    });

    it('ne bloque pas la pause quand WhatsApp est en panne', function (): void {
        ($this->book)('2026-06-10 10:00');
        $this->transport->failWith = '131000';

        ($this->pause)(['days' => 20, 'cancel_bookings' => true])->assertSessionHas('warning');

        expect(($this->orgRow)()->status)->toBe('paused')->and(DB::connection('pgsql_owner')->table('bookings')->where('status', 'cancelled_by_business')->count())->toBe(1);
        $this->transport->failWith = null;
    });

    it('n\'annule rien si la pause est refusée', function (): void {
        ($this->book)('2026-06-10 10:00');

        ($this->pause)(['days' => 20, 'cancel_bookings' => true, 'password' => 'nope'])->assertSessionHasErrors('password');
        ($this->pause)(['days' => 500, 'cancel_bookings' => true])->assertSessionHasErrors('days');

        expect(DB::connection('pgsql_owner')->table('bookings')->where('status', 'confirmed')->count())->toBe(1)->and($this->transport->templates)->toBe([]);
    });

    it('propose l\'annulation depuis la page quand des rendez-vous sont prévus', function (): void {
        ($this->book)('2026-06-10 10:00');

        ($this->as)()->get('/compte/pause')->assertInertia(fn (Assert $page) => $page->where('upcoming', 1)->where('canPause', true));
    });
    it('n\'est pas possible depuis un compte suspendu, en pause ou en suppression', function (string $status): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());

        // Suspendu : écriture refusée ; en pause ou en suppression : seul l'écran d'état est accessible.
        ($this->pause)()->assertRedirect(route('account.state'));

        expect(($this->orgRow)()->status)->toBe($status);
    })->with(['suspended', 'paused', 'pending_deletion']);

    it('est réservée au propriétaire', function (): void {
        ['user' => $manager, 'org' => $managerOrg] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $managerOrg)->post('/compte/pause', ['days' => 5, 'password' => 'x', 'confirm' => true])->assertForbidden();
    });
});

describe('pendant la pause', function (): void {
    beforeEach(fn () => ($this->pause)());

    it('limite le dashboard à l\'écran d\'état, au compte et à la pause', function (): void {
        ($this->as)()->get('/planning')->assertRedirect(route('account.state'));
        ($this->as)()->get('/compte')->assertOk();
        ($this->as)()->get('/compte/pause')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'paused')->where('canPause', false)->where('message', 'Fermé, réouverture bientôt.'));
        ($this->as)()->get('/compte/donnees')->assertOk();
    });

    it('n\'est pas facturée : le passage de facturation ignore le compte', function (): void {
        $this->artisan('billing:run')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();
    });
});

describe('réactivation', function (): void {
    it('par le propriétaire : retrouve le statut d\'avant et efface le message', function (): void {
        ($this->pause)();

        ($this->as)()->delete('/compte/pause')->assertRedirect('/tableau-de-bord')->assertSessionHas('status', 'Votre compte est réactivé.');

        expect(($this->orgRow)())->status->toBe('active')->previous_status->toBeNull()->paused_at->toBeNull()->pause_until->toBeNull()->unavailable_message->toBeNull();
        ($this->as)()->get('/planning')->assertOk();
    });

    it('prolonge l\'essai et la période payée de la durée de la pause', function (): void {
        $plan = Plan::query()->where('code', 'basic')->firstOrFail();
        $start = now()->subDays(10);
        DB::connection('pgsql_owner')->table('subscriptions')->insert(['org_id' => $this->org->id, 'plan_id' => $plan->id, 'status' => 'active', 'provider' => 'fake', 'current_period_start' => $start, 'current_period_end' => $start->copy()->addMonth()]);
        $this->asOwner(fn () => $this->org->forceFill(['trial_ends_at' => now()->addDays(4)])->save());
        ($this->pause)();

        $this->travel(20)->days();
        ($this->as)()->delete('/compte/pause');

        $subscription = DB::connection('pgsql_owner')->table('subscriptions')->where('org_id', $this->org->id)->first();
        expect(abs(now()->diffInDays($subscription->current_period_end, false) - 21))->toBeLessThan(2)
            ->and(abs(now()->diffInDays(($this->orgRow)()->trial_ends_at, false) - 4))->toBeLessThan(1);
    });

    it('n\'a de sens que pour un compte en pause', function (): void {
        ($this->as)()->delete('/compte/pause')->assertSessionHasErrors('pause');
    });
});

describe('fin de la pause (accounts:end-pauses)', function (): void {
    beforeEach(function (): void {
        ($this->pause)(['days' => 20]);
        Notification::fake();
    });

    it('ne fait rien loin de l\'échéance', function (): void {
        $this->travel(5)->days();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('prévient une seule fois, 7 jours avant la fin', function (): void {
        $this->travel(14)->days();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('reminded: 1')->assertSuccessful();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();

        Notification::assertSentOnDemandTimes(PauseNotification::class, 1);
        expect(($this->orgRow)()->status)->toBe('paused');
    });

    it('suspend le compte à l\'échéance, trace, prévient et garde les données', function (): void {
        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('expired: 1')->assertSuccessful();

        expect(($this->orgRow)())->status->toBe('suspended')->suspension_reason->toBe('system.pause_ended')->previous_status->toBe('active')->pause_until->toBeNull()->paused_at->toBeNull();
        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.pause_expired')->sole());
        expect($audit->actor_type)->toBe('system');
        Notification::assertSentOnDemand(PauseNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();
    });

    it('laisse le propriétaire réactiver le compte ou le supprimer après l\'échéance', function (): void {
        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();

        ($this->as)()->get('/compte')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'suspended')->where('reason', 'system.pause_ended'));
        ($this->as)()->get('/compte/donnees')->assertInertia(fn (Assert $page) => $page->where('canRequestDeletion', true));

        ($this->as)()->delete('/compte/pause')->assertRedirect('/tableau-de-bord');

        expect(($this->orgRow)())->status->toBe('active')->suspension_reason->toBeNull()->suspended_at->toBeNull()->previous_status->toBeNull();
    });

    it('programme la suppression automatiquement 60 jours après la suspension, avec un rappel avant', function (): void {
        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();
        Notification::fake();

        $this->travel(53)->days(); // 7 jours avant la limite de 60 jours
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('deletion_reminded: 1')->assertSuccessful();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PauseNotification::class, 1);
        expect(($this->orgRow)()->status)->toBe('suspended');

        $this->travel(8)->days();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('deletion_scheduled: 1')->assertSuccessful();

        $org = ($this->orgRow)();
        expect($org)->status->toBe('pending_deletion')->previous_status->toBe('suspended')
            ->and(now()->addDays(30)->diffInHours($org->deletion_scheduled_for, false))->toBeLessThan(1);
        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'organization.deletion_scheduled')->sole());
        expect($audit->actor_type)->toBe('system')->and(json_decode($audit->diff, true))->toMatchArray(['automatic' => true]);
        Notification::assertSentOnDemand(AccountStatusNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    });

    it('laisse le propriétaire annuler cette suppression : le compte redevient suspendu', function (): void {
        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();
        $this->travel(61)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();

        ($this->as)()->delete('/compte/suppression')->assertSessionHasNoErrors();

        expect(($this->orgRow)())->status->toBe('suspended')->suspension_reason->toBe('system.pause_ended');
    });

    it('ne supprime pas automatiquement une suspension décidée pour une autre raison', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended', 'suspension_reason' => 'Fraude constatée', 'suspended_at' => now()->subDays(200), 'pause_until' => null, 'paused_at' => null])->save());

        $this->artisan('accounts:end-pauses')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();

        expect(($this->orgRow)()->status)->toBe('suspended');
    });

    it('respecte la durée du paramètre suspension_max_days', function (): void {
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'suspension_max_days')->update(['value' => '10']);
        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();

        $this->travel(11)->days();
        $this->artisan('accounts:end-pauses')->expectsOutputToContain('deletion_scheduled: 1')->assertSuccessful();

        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'suspension_max_days')->update(['value' => '60']);
    });
    it('ne compte la pause qu\'une fois sur la période payée, même si la réactivation tarde', function (): void {
        $plan = Plan::query()->where('code', 'basic')->firstOrFail();
        $start = now()->subDays(10);
        DB::connection('pgsql_owner')->table('subscriptions')->where('org_id', $this->org->id)->delete();
        DB::connection('pgsql_owner')->table('subscriptions')->insert(['org_id' => $this->org->id, 'plan_id' => $plan->id, 'status' => 'active', 'provider' => 'fake', 'current_period_start' => $start, 'current_period_end' => $start->copy()->addDays(30)]);

        $this->travel(21)->days();
        $this->artisan('accounts:end-pauses')->assertSuccessful();
        $afterExpiry = DB::connection('pgsql_owner')->table('subscriptions')->where('org_id', $this->org->id)->value('current_period_end');

        $this->travel(10)->days();
        ($this->as)()->delete('/compte/pause');

        $final = DB::connection('pgsql_owner')->table('subscriptions')->where('org_id', $this->org->id)->value('current_period_end');
        expect($final)->toBe($afterExpiry);
    });

    it('ne touche pas les autres organisations', function (): void {
        ['org' => $other] = $this->activatedAccount(['status' => 'active'], 'autre@example.com');
        $this->travel(21)->days();

        $this->artisan('accounts:end-pauses')->assertSuccessful();

        expect(DB::connection('pgsql_owner')->table('organizations')->where('id', $other->id)->value('status'))->toBe('active');
    });
});
it('garde le lien avec les rôles : seul le propriétaire apparaît comme membre autorisé', function (): void {
    expect(OrgMember::ROLE_OWNER)->toBe('owner');
});
