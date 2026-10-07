<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\AuditLogResource;
use App\Modules\Admin\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Filament\Resources\PlatformSettingResource;
use App\Modules\Admin\Filament\Resources\PlatformSettingResource\Pages\ListPlatformSettings;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformAuditLog;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Models\PlatformSetting;
use App\Modules\Admin\Notifications\AccountStatusNotification;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Actions sensibles du Super Admin (docs/02 §3.3) : motif, nom saisi, audit, e-mail au propriétaire (plus de re-authentification par action).
 */
const ADMIN_PASSWORD = 'mot-de-passe-du-fondateur-2026!';

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();
    Cache::flush();

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => ADMIN_PASSWORD]);

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'active']);

    $this->page = fn (?string $orgId = null) => Livewire::actingAs($this->admin, 'platform')->test(ViewOrganization::class, ['record' => $orgId ?? $this->org->id]);
    $this->credentials = fn (array $overrides = []): array => [
        'reason' => 'Impayés répétés malgré trois relances',
        ...$overrides,
    ];
    $this->fresh = fn (): PlatformOrganization => PlatformOrganization::query()->findOrFail($this->org->id);
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
});

afterEach(function (): void {
    DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'deletion_delay_days')->update(['value' => '30']);
});

describe('suspension', function (): void {
    it('suspend le compte, garde le statut précédent, trace l\'action et prévient le propriétaire', function (): void {
        ($this->page)()->callAction('suspend', data: ($this->credentials)())->assertHasNoActionErrors();

        $org = ($this->fresh)();
        $audit = ($this->audit)('organization.suspended')->sole();

        expect($org)->status->toBe('suspended')->previous_status->toBe('active')->suspension_reason->toBe('Impayés répétés malgré trois relances')
            ->and($org->suspended_at)->not->toBeNull()
            ->and($audit)->actor_type->toBe('platform_admin')->actor_id->toBe($this->admin->id)->reason->toBe('Impayés répétés malgré trois relances')->org_id->toBe($this->org->id)
            ->and(json_decode($audit->diff, true))->toBe(['status' => ['active', 'suspended']]);

        Notification::assertSentOnDemand(AccountStatusNotification::class, fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');
    });

    it('rend le compte client en lecture seule', function (): void {
        ($this->page)()->callAction('suspend', data: ($this->credentials)());

        $this->signedIn($this->owner, $this->org)->post('/planning/rendez-vous', [])->assertRedirect(route('account.state'));
    });

    it('n\'est pas proposée à un compte déjà suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->page)()->assertActionHidden('suspend')->assertActionVisible('lift');
    });

    it('lève la suspension et retrouve le statut d\'avant', function (): void {
        ($this->page)()->callAction('suspend', data: ($this->credentials)());
        Cache::flush();

        ($this->page)()->callAction('lift', data: ($this->credentials)(['reason' => 'Paiement régularisé par virement']));

        expect(($this->fresh)())->status->toBe('active')->previous_status->toBeNull()->suspension_reason->toBeNull()->suspended_at->toBeNull()
            ->and(($this->audit)('organization.suspension_lifted'))->toHaveCount(1);
        Notification::assertSentOnDemandTimes(AccountStatusNotification::class, 2);
    });

    it('remet un essai échu en « actif » et un essai en cours en « essai »', function (string $trialEnds, string $expected): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'trial', 'trial_ends_at' => $trialEnds])->save());
        ($this->page)()->callAction('suspend', data: ($this->credentials)());
        Cache::flush();

        ($this->page)()->callAction('lift', data: ($this->credentials)());

        expect(($this->fresh)()->status)->toBe($expected);
    })->with([
        'essai échu' => [fn () => now()->subDay()->toDateTimeString(), 'active'],
        'essai en cours' => [fn () => now()->addDays(5)->toDateTimeString(), 'trial'],
    ]);
});

describe('garde-fous', function (): void {
    it('accepte un motif vide : l\'action est faite et tracée sans motif', function (): void {
        ($this->page)()->callAction('suspend', data: ($this->credentials)(['reason' => '']))->assertHasNoActionErrors();

        expect(($this->fresh)())->status->toBe('suspended')->suspension_reason->toBeNull()
            ->and(($this->audit)('organization.suspended')->sole())->reason->toBeNull();
        Notification::assertSentOnDemand(AccountStatusNotification::class);
    });

    it('ne demande ni mot de passe ni code 2FA : la session connectée suffit', function (): void {
        ($this->page)()->callAction('suspend', data: ['reason' => 'Impayés répétés malgré trois relances']);

        expect(($this->fresh)()->status)->toBe('suspended');
    });
});

describe('suppression programmée', function (): void {
    it('programme la suppression au délai des paramètres (30 jours) et prévient le propriétaire', function (): void {
        ($this->page)()->callAction('schedule_deletion', data: ($this->credentials)(['reason' => 'Demande écrite du propriétaire']));

        $org = ($this->fresh)();

        expect($org)->status->toBe('pending_deletion')->previous_status->toBe('active')
            ->and($org->deletion_scheduled_for->isSameDay(now()->addDays(30)))->toBeTrue()
            ->and(($this->audit)('organization.deletion_scheduled'))->toHaveCount(1);
        Notification::assertSentOnDemand(AccountStatusNotification::class);
    });

    it('utilise le délai modifié dans les paramètres globaux', function (): void {
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'deletion_delay_days')->update(['value' => '45']);

        ($this->page)()->callAction('schedule_deletion', data: ($this->credentials)());

        expect(($this->fresh)()->deletion_scheduled_for->isSameDay(now()->addDays(45)))->toBeTrue();
    });

    it('annule la suppression et rétablit le compte', function (): void {
        ($this->page)()->callAction('schedule_deletion', data: ($this->credentials)());
        Cache::flush();

        ($this->page)()->callAction('cancel_deletion', data: ($this->credentials)(['reason' => 'Le propriétaire a changé d\'avis']));

        expect(($this->fresh)())->status->toBe('active')->deletion_scheduled_for->toBeNull()->deletion_requested_at->toBeNull();
    });

    it('n\'est pas proposée à un compte déjà en suppression ou supprimé', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'pending_deletion'])->save());

        ($this->page)()->assertActionHidden('schedule_deletion')->assertActionHidden('suspend')->assertActionVisible('cancel_deletion');
    });
});

describe('langue du propriétaire', function (): void {
    it('écrit l\'e-mail dans la langue du propriétaire', function (string $locale, string $subject): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->page)()->callAction('suspend', data: ($this->credentials)());

        Notification::assertSentOnDemand(AccountStatusNotification::class, function ($notification, $channels, $notifiable) use ($subject): bool {
            app()->setLocale($notification->locale);
            $mail = $notification->toMail($notifiable);
            app()->setLocale('fr');

            return $mail->subject === $subject && str_contains(implode(' ', $mail->introLines), 'Impayés répétés');
        });
    })->with([
        ['fr', 'Votre compte Nova « Salon Aurore » a été suspendu'],
        ['nl', 'Uw Nova-account « Salon Aurore » is opgeschort'],
        ['en', 'Your Nova account « Salon Aurore » has been suspended'],
    ]);
});

describe('paramètres globaux', function (): void {
    it('modifie un délai avec motif, et trace ancienne et nouvelle valeur', function (): void {
        $setting = PlatformSetting::query()->findOrFail('deletion_delay_days');

        Livewire::actingAs($this->admin, 'platform')->test(ListPlatformSettings::class)
            ->callTableAction('edit', $setting, data: ($this->credentials)(['value' => 60]))->assertHasNoTableActionErrors();

        expect(json_decode(DB::connection('pgsql_platform')->table('platform_settings')->where('key', 'deletion_delay_days')->value('value')))->toBe(60)
            ->and(json_decode(($this->audit)('platform_settings.updated')->sole()->diff, true))->toBe(['deletion_delay_days' => [30, 60]]);
    });

    it('refuse une valeur hors bornes', function (): void {
        $setting = PlatformSetting::query()->findOrFail('deletion_delay_days');

        Livewire::actingAs($this->admin, 'platform')->test(ListPlatformSettings::class)
            ->callTableAction('edit', $setting, data: ($this->credentials)(['value' => 0]))->assertHasTableActionErrors(['value']);

        expect(json_decode(DB::connection('pgsql_platform')->table('platform_settings')->where('key', 'deletion_delay_days')->value('value')))->toBe(30);
    });

    it('ne peut pas créer ni supprimer de paramètre', function (): void {
        expect(PlatformSettingResource::canCreate())->toBeFalse()
            ->and(PlatformSettingResource::canDelete(new PlatformSetting))->toBeFalse();
    });
});

describe('journal d\'audit', function (): void {
    it('liste les actions et ne se modifie pas', function (): void {
        ($this->page)()->callAction('suspend', data: ($this->credentials)());
        $entry = PlatformAuditLog::query()->where('action', 'organization.suspended')->firstOrFail();

        Livewire::actingAs($this->admin, 'platform')->test(ListAuditLogs::class)->assertCanSeeTableRecords([$entry]);

        expect(AuditLogResource::canCreate())->toBeFalse()
            ->and(AuditLogResource::canEdit($entry))->toBeFalse()
            ->and(fn () => DB::connection('pgsql_platform')->table('audit_logs')->where('id', $entry->id)->update(['reason' => 'trafiqué']))->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->table('audit_logs')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    });
});

describe('séparation des rôles de base', function (): void {
    it('empêche le rôle plateforme de lire les comptes utilisateurs ou de toucher aux réservations', function (): void {
        expect(fn () => DB::connection('pgsql_platform')->table('users')->count())->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->table('bookings')->update(['status' => 'confirmed']))->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->table('customers')->delete())->toThrow(QueryException::class);
    });

    it('refuse à l\'espace client l\'écriture des paramètres globaux', function (): void {
        expect(fn () => DB::connection('pgsql')->table('platform_settings')->update(['value' => '1']))->toThrow(QueryException::class);
    });
});
