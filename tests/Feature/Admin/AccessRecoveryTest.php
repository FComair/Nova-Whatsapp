<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Notifications\AccessRestoredNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Services\TwoFactor;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * « Déblocage d'accès » (décision 18) : le support réinitialise la double authentification d'un membre qui a tout perdu.
 */
const SUPPORT_PASSWORD = 'mot-de-passe-du-fondateur-2026!';

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();
    Cache::flush();

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => SUPPORT_PASSWORD]);

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'active']);

    // Le propriétaire a activé la double authentification, puis a tout perdu ; son compte est aussi verrouillé.
    $service = app(TwoFactor::class);
    $secret = $service->beginSetup($this->owner);
    $service->confirm($this->owner->refresh(), (new Google2FA)->oathTotp($secret, (new Google2FA)->getTimestamp()));
    $this->asOwner(fn () => $this->owner->forceFill(['failed_login_count' => 3, 'locked_until' => now()->addMinutes(10)])->save());

    $this->page = fn () => Livewire::actingAs($this->admin, 'platform')->test(ViewOrganization::class, ['record' => $this->org->id]);
    $this->data = fn (array $overrides = []): array => [
        'user_id' => $this->owner->id,
        'identity_verified' => true,
        'reset_password' => false,
        'reason' => 'Appareil perdu, identité vérifiée par rappel au numéro enregistré',
        ...$overrides,
    ];
    $this->owner_row = fn () => DB::connection('pgsql_owner')->table('users')->where('id', $this->owner->id)->first();
});

it('réinitialise la double authentification, lève le verrouillage, trace l\'action et prévient la personne', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)())->assertHasNoActionErrors();

    $row = ($this->owner_row)();
    expect($row)->two_factor_secret->toBeNull()->two_factor_recovery_codes->toBeNull()->two_factor_confirmed_at->toBeNull()->failed_login_count->toBe(0)->locked_until->toBeNull();

    $audit = DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'user.access_unblocked')->sole();
    expect($audit)->actor_id->toBe($this->admin->id)->org_id->toBe($this->org->id)->entity_id->toBe($this->owner->id)->reason->toContain('identité vérifiée')
        ->and(json_decode($audit->diff, true))->toEqual(['two_factor_reset' => true, 'password_reset' => false, 'role' => 'owner']);

    Notification::assertSentOnDemand(AccessRestoredNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    Notification::assertNotSentTo($this->owner, ResetPasswordNotification::class);
});

it('laisse le mot de passe inchangé par défaut', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)());

    expect(Hash::check('password-de-test', ($this->owner_row)()->password))->toBeTrue();
});

it('peut aussi invalider le mot de passe et envoyer un lien pour en choisir un nouveau', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)(['reset_password' => true]));

    expect(Hash::check('password-de-test', ($this->owner_row)()->password))->toBeFalse();
    Notification::assertSentTo(User::query()->find($this->owner->id), ResetPasswordNotification::class);
    expect(json_decode(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'user.access_unblocked')->value('diff'), true))->toHaveKey('password_reset', true);
});

it('permet à la personne de se reconnecter sans double authentification, puis de la reconfigurer', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)());

    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'password-de-test'])->assertRedirect('/tableau-de-bord');
});

it('exige la case « identité vérifiée »', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)(['identity_verified' => false]))->assertHasActionErrors(['identity_verified']);

    expect(($this->owner_row)()->two_factor_confirmed_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('accepte un motif vide : l\'action est tracée sans motif', function (): void {
    ($this->page)()->callAction('unblock_access', data: ($this->data)(['reason' => '']))->assertHasNoActionErrors();

    expect(($this->owner_row)()->two_factor_confirmed_at)->toBeNull()
        ->and(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'user.access_unblocked')->sole())->reason->toBeNull();
});

it('refuse un membre d\'une autre organisation', function (): void {
    ['user' => $stranger] = $this->activatedAccount(email: 'autre@example.com');

    ($this->page)()->callAction('unblock_access', data: ($this->data)(['user_id' => $stranger->id]));

    expect(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'user.access_unblocked')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('n\'est pas proposé pour un compte en suppression', function (): void {
    $this->asOwner(fn () => $this->org->forceFill(['status' => 'pending_deletion'])->save());

    ($this->page)()->assertActionHidden('unblock_access');
});

it('ne permet pas au rôle plateforme de lire ou d\'écrire directement la table des utilisateurs', function (): void {
    expect(fn () => DB::connection('pgsql_platform')->table('users')->count())->toThrow(QueryException::class)
        ->and(fn () => DB::connection('pgsql_platform')->table('users')->update(['two_factor_secret' => null]))->toThrow(QueryException::class);
});
