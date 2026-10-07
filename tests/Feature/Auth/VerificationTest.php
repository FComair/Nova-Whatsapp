<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PhoneCodeSender;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;
use Tests\Support\FakePhoneCodeSender;

uses(AuthScenarios::class);

/**
 * Flowchart 1A (vérifications e-mail puis téléphone) et erreur E2 (flowchart 5A).
 */
beforeEach(function (): void {
    $this->sender = new FakePhoneCodeSender;
    app()->instance(PhoneCodeSender::class, $this->sender);

    // Compte tout juste inscrit : e-mail et téléphone non vérifiés.
    ['user' => $user, 'org' => $org] = $this->activatedAccount(['status' => 'trial']);
    $this->asOwner(fn () => $user->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save());
    $this->user = $user->refresh();
    $this->org = $org;

    $this->link = fn (?User $u = null, ?string $hash = null, int $hours = 24) => URL::temporarySignedRoute(
        'verification.verify',
        now()->addHours($hours),
        ['user' => ($u ?? $this->user)->id, 'hash' => $hash ?? sha1(($u ?? $this->user)->email)],
    );
});

describe('lien de vérification de l\'e-mail', function (): void {
    it('vérifie l\'e-mail puis envoie le code à 6 chiffres au téléphone', function (): void {
        $this->get(($this->link)())->assertRedirect('/verification-telephone');

        expect($this->asOwner(fn () => User::query()->find($this->user->id)->hasVerifiedEmail()))->toBeTrue()
            ->and($this->sender->sent)->toHaveCount(1)
            ->and($this->sender->lastCode())->toMatch('/^\d{6}$/')
            ->and($this->sender->sent[0]['phone'])->toBe($this->user->phone_e164);
    });

    it('rejette un lien expiré (E2) sans vérifier l\'e-mail', function (): void {
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['user' => $this->user->id, 'hash' => sha1($this->user->email)]);

        $this->get($expired)->assertRedirect('/verification-email/expire');

        expect($this->asOwner(fn () => User::query()->find($this->user->id)->hasVerifiedEmail()))->toBeFalse();
    });

    it('rejette un lien dont la signature est falsifiée', function (): void {
        $forged = ($this->link)().'x';

        $this->get($forged)->assertRedirect('/verification-email/expire');
        expect($this->sender->sent)->toBeEmpty();
    });

    it('rejette un lien signé mais dont l\'empreinte ne correspond pas à l\'e-mail', function (): void {
        $this->get(($this->link)(hash: sha1('autre@example.com')))->assertRedirect('/verification-email/expire');

        expect($this->asOwner(fn () => User::query()->find($this->user->id)->hasVerifiedEmail()))->toBeFalse();
    });

    it('rejette le lien d\'un utilisateur inexistant', function (): void {
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['user' => (string) Str::uuid(), 'hash' => sha1('x')]);

        $this->get($link)->assertRedirect('/verification-email/expire');
    });

    it('est valable exactement 24 heures', function (): void {
        $this->travel(23)->hours();
        $this->get(($this->link)())->assertRedirect('/verification-telephone');
    });
});

describe('renvoi du lien', function (): void {
    it('renvoie le lien puis impose le délai de 60 secondes', function (): void {
        Notification::fake();

        $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHas('status');
        Notification::assertSentToTimes($this->user, VerifyEmailNotification::class, 1);

        $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHasErrors('email');
        Notification::assertSentToTimes($this->user, VerifyEmailNotification::class, 1);

        $this->travel(61)->seconds();
        $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHas('status');
        Notification::assertSentToTimes($this->user, VerifyEmailNotification::class, 2);
    });

    it('plafonne à 3 envois par heure', function (): void {
        Notification::fake();

        foreach ([1, 2, 3] as $attempt) {
            $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHas('status');
            $this->travel(61)->seconds();
        }

        $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHasErrors('email');
        Notification::assertSentToTimes($this->user, VerifyEmailNotification::class, 3);
    });

    it('répond de la même façon pour une adresse inconnue (pas d\'énumération de comptes)', function (): void {
        Notification::fake();

        $this->post('/verification-email/renvoi', ['email' => $this->user->email])->assertSessionHas('status', __('ui.verify.email_resent'));
        $this->post('/verification-email/renvoi', ['email' => 'inconnu@example.com'])->assertSessionHas('status', __('ui.verify.email_resent'));

        // Un seul e-mail part réellement : celui du compte existant.
        Notification::assertCount(1);
    });
});

describe('code du téléphone professionnel', function (): void {
    beforeEach(function (): void {
        $this->asOwner(fn () => $this->user->forceFill(['email_verified_at' => now()])->save());
        $this->withSession(['pending_user_id' => $this->user->id]);
        $this->post('/verification-telephone/renvoi')->assertSessionHas('status');
    });

    it('active le compte, ouvre la session et rattache l\'organisation avec le bon code', function (): void {
        $this->post('/verification-telephone', ['code' => $this->sender->lastCode()])->assertRedirect('/tableau-de-bord');

        $this->assertAuthenticatedAs($this->user->refresh());
        expect(session(SetTenantContext::SESSION_KEY))->toBe($this->org->id)
            ->and($this->asOwner(fn () => User::query()->find($this->user->id)->isActivated()))->toBeTrue();
    });

    it('refuse un mauvais code (E2 « Code incorrect »)', function (): void {
        $wrong = $this->sender->lastCode() === '000000' ? '111111' : '000000';

        $this->post('/verification-telephone', ['code' => $wrong])->assertSessionHasErrors(['code' => 'Code incorrect.']);
        $this->assertGuest();
    });

    it('refuse un code de mauvais format', function (): void {
        $this->post('/verification-telephone', ['code' => 'abc'])->assertSessionHasErrors('code');
    });

    it('bloque la vérification après 5 codes faux, même avec le bon code ensuite', function (): void {
        $good = $this->sender->lastCode();
        $wrong = $good === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->post('/verification-telephone', ['code' => $wrong])->assertSessionHasErrors(['code' => 'Code incorrect.']);
        }

        $this->post('/verification-telephone', ['code' => $wrong])->assertSessionHasErrors('code');
        expect(session('errors')->first('code'))->toContain('15 minutes');

        $this->post('/verification-telephone', ['code' => $good])->assertSessionHasErrors('code');
        $this->assertGuest();

        // Après le blocage de 15 minutes, un nouveau code peut être demandé et validé.
        $this->travel(16)->minutes();
        $this->post('/verification-telephone/renvoi')->assertSessionHas('status');
        $this->post('/verification-telephone', ['code' => $this->sender->lastCode()])->assertRedirect('/tableau-de-bord');
    });

    it('refuse un code expiré (E2)', function (): void {
        $code = $this->sender->lastCode();
        $this->travel(11)->minutes();
        Cache::flush();

        $this->post('/verification-telephone', ['code' => $code])->assertSessionHasErrors(['code' => 'Ce code a expiré. Demandez un nouveau code.']);
    });

    it('impose 60 secondes entre deux envois de code', function (): void {
        $this->post('/verification-telephone/renvoi')->assertSessionHasErrors('code');
        expect($this->sender->sent)->toHaveCount(1);

        $this->travel(61)->seconds();
        $this->post('/verification-telephone/renvoi')->assertSessionHas('status');
        expect($this->sender->sent)->toHaveCount(2);
    });

    it('ne stocke jamais le code en clair', function (): void {
        $code = $this->sender->lastCode();
        $stored = Cache::get("phone-code:{$this->user->id}");

        expect($stored)->toBeString()->not->toContain($code)->toHaveLength(64);
    });

    it('invalide l\'ancien code quand un nouveau est demandé et que le numéro change', function (): void {
        $old = $this->sender->lastCode();
        $this->asOwner(fn () => $this->user->forceFill(['phone_e164' => '+32499999999'])->save());

        $this->post('/verification-telephone', ['code' => $old])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('exige d\'avoir d\'abord vérifié l\'e-mail', function (): void {
        $this->asOwner(fn () => $this->user->forceFill(['email_verified_at' => null])->save());

        $this->post('/verification-telephone', ['code' => $this->sender->lastCode()])->assertRedirect('/connexion');
        $this->assertGuest();
    });
});

it('n\'écrit rien dans la base par le rôle client hors du contexte de son organisation', function (): void {
    // Vérifie que l'activation n'a pas laissé de contexte d'organisation actif sur la connexion.
    $this->asOwner(fn () => $this->user->forceFill(['email_verified_at' => now()])->save());
    $this->withSession(['pending_user_id' => $this->user->id])->post('/verification-telephone/renvoi');
    $this->post('/verification-telephone', ['code' => $this->sender->lastCode()]);

    expect(DB::selectOne("select current_setting('app.current_org', true) as v")->v)->toBeIn([null, '']);
});
