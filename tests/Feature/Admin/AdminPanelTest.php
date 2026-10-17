<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\OrganizationResource;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Tenancy\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin (docs/02 §3) : panel séparé, guard `platform`, 2FA obligatoire, lecture transverse via `nova_platform`.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->withMfa = function (): PlatformAdmin {
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $this->admin->refresh();
    };
});

describe('accès et séparation', function (): void {
    it('affiche la page de connexion du Super Admin', function (): void {
        $this->get('/admin/login')->assertOk();
    });

    it('ne propose pas « Se souvenir de moi » au Super Admin', function (): void {
        $this->get('/admin/login')->assertOk()->assertDontSee('Se souvenir de moi');
    });

    it('renvoie un visiteur non connecté vers la connexion du panel', function (): void {
        $this->get('/admin')->assertRedirect('/admin/login');
    });

    it('refuse à un utilisateur client l\'accès au Super Admin', function (): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount();

        $this->actingAs($user, 'web')->get('/admin')->assertRedirect('/admin/login');
    });

    it('refuse à un Super Admin l\'accès à l\'espace client (guards séparés)', function (): void {
        $admin = ($this->withMfa)();

        // actingAs() rend le guard « platform » par défaut : on revient au guard réel de l'espace client (« web »).
        $this->actingAs($admin, 'platform');
        auth()->shouldUse('web');

        $this->get('/tableau-de-bord')->assertRedirect('/connexion');
        $this->assertGuest('web');
    });

    it('impose la configuration du 2FA avant tout accès au panel', function (): void {
        $response = $this->actingAs($this->admin, 'platform')->get('/admin');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->not->toEndWith('/admin')
            ->and($response->headers->get('Location'))->toContain('/admin/');
    });

    it('donne accès au panel une fois le 2FA configuré', function (): void {
        $admin = ($this->withMfa)();

        $this->actingAs($admin, 'platform')->get('/admin')->assertOk();
        $this->actingAs($admin, 'platform')->get('/admin/organizations')->assertOk();
    });

    it('chiffre le secret 2FA au repos', function (): void {
        ($this->withMfa)();

        $raw = DB::connection('pgsql_owner')->table('platform_admins')->value('app_authentication_secret');

        expect($raw)->not->toBeNull()->not->toContain('JBSWY3DPEHPK3PXP')
            ->and($this->admin->refresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
    });

    it('stocke le mot de passe haché', function (): void {
        $raw = DB::connection('pgsql_owner')->table('platform_admins')->value('password');

        expect($raw)->not->toBe('mot-de-passe-du-fondateur-2026!')
            ->and(Hash::check('mot-de-passe-du-fondateur-2026!', $raw))->toBeTrue();
    });
});

describe('liste des clients', function (): void {
    beforeEach(function (): void {
        $this->asOwner(function (): void {
            $this->salon = Organization::factory()->create(['name' => 'Salon Aurore', 'status' => 'active', 'sector' => 'hairdressing']);
            $this->cabinet = Organization::factory()->create(['name' => 'Cabinet Lumière', 'status' => 'suspended', 'suspension_reason' => 'Paiement échoué']);
            $this->garage = Organization::factory()->create(['name' => 'Garage Nord', 'status' => 'trial']);
            Organization::factory()->create(['name' => 'Ancien Client', 'status' => 'deleted', 'deleted_at' => now()]);
        });
    });

    it('montre les clients de toutes les organisations, sans contexte d\'organisation', function (): void {
        Livewire::actingAs(($this->withMfa)(), 'platform')
            ->test(ListOrganizations::class)
            ->assertCanSeeTableRecords([$this->salon, $this->cabinet, $this->garage])
            ->assertCountTableRecords(3);
    });

    it('masque les clients supprimés', function (): void {
        Livewire::actingAs(($this->withMfa)(), 'platform')
            ->test(ListOrganizations::class)
            ->assertCanNotSeeTableRecords(PlatformOrganization::query()->where('name', 'Ancien Client')->get());
    });

    it('recherche par nom', function (): void {
        Livewire::actingAs(($this->withMfa)(), 'platform')
            ->test(ListOrganizations::class)
            ->searchTable('Aurore')
            ->assertCanSeeTableRecords([$this->salon])
            ->assertCanNotSeeTableRecords([$this->cabinet, $this->garage]);
    });

    it('filtre par statut', function (): void {
        Livewire::actingAs(($this->withMfa)(), 'platform')
            ->test(ListOrganizations::class)
            ->filterTable('status', 'suspended')
            ->assertCanSeeTableRecords([$this->cabinet])
            ->assertCanNotSeeTableRecords([$this->salon, $this->garage]);
    });

    it('affiche la fiche d\'un client avec son statut et le motif de suspension', function (): void {
        $this->actingAs(($this->withMfa)(), 'platform')
            ->get(OrganizationResource::getUrl('view', ['record' => $this->cabinet]))
            ->assertOk()
            ->assertSee('Cabinet Lumière')
            ->assertSee('Suspendu')
            ->assertSee('Paiement échoué');
    });

    it('est en lecture seule : ni création, ni modification, ni suppression', function (): void {
        expect(OrganizationResource::canCreate())->toBeFalse()
            ->and(OrganizationResource::canEdit($this->salon))->toBeFalse()
            ->and(OrganizationResource::canDelete($this->salon))->toBeFalse();
    });

    it('rend les libellés dans la langue de l\'interface', function (string $locale, string $label): void {
        app()->setLocale($locale);

        expect(OrganizationResource::getPluralModelLabel())->toBe($label);
    })->with([['fr', 'Clients'], ['nl', 'Klanten'], ['en', 'Customers']]);
});

describe('commande rendivoo:create-admin', function (): void {
    it('crée un Super Admin sans configurer son 2FA (imposé à la première connexion)', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'associe@rendivoo.test'])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'Un-mot-de-passe-tres-solide-2026!')
            ->expectsQuestion('Confirmez le mot de passe', 'Un-mot-de-passe-tres-solide-2026!')
            ->assertSuccessful();

        $row = DB::connection('pgsql_owner')->table('platform_admins')->where('email', 'associe@rendivoo.test')->first();

        expect($row)->not->toBeNull()
            ->and($row->app_authentication_secret)->toBeNull()
            ->and(Hash::check('Un-mot-de-passe-tres-solide-2026!', $row->password))->toBeTrue();
    });

    it('refuse un mot de passe faible', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'faible@rendivoo.test'])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'court')
            ->expectsQuestion('Confirmez le mot de passe', 'court')
            ->assertFailed();

        expect(DB::connection('pgsql_owner')->table('platform_admins')->where('email', 'faible@rendivoo.test')->exists())->toBeFalse();
    });

    it('refuse un e-mail déjà utilisé', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'fondateur@rendivoo.test'])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'Un-mot-de-passe-tres-solide-2026!')
            ->expectsQuestion('Confirmez le mot de passe', 'Un-mot-de-passe-tres-solide-2026!')
            ->assertFailed();
    });

    it('refuse une confirmation différente', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'autre@rendivoo.test'])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'Un-mot-de-passe-tres-solide-2026!')
            ->expectsQuestion('Confirmez le mot de passe', 'Autre-chose-de-tres-solide-2026!')
            ->assertFailed();
    });
});
