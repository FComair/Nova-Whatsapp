<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Réglages > Profil : coordonnées de la personne connectée et informations de l'entreprise (propriétaire seulement).
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(
        ['name' => 'Salon Aurore', 'sector' => 'hairdressing', 'country' => 'BE', 'timezone' => 'Europe/Brussels', 'locale' => 'fr', 'billing_name' => 'Aurore SRL', 'billing_vat_number' => 'BE0123456789'],
    );

    $this->valid = fn (array $override = []) => array_merge([
        'name' => 'Salon Aurore Deux', 'sector' => 'beauty', 'country' => 'FR', 'timezone' => 'Europe/Paris', 'locale' => 'nl',
    ], $override);

    // Un second utilisateur du rôle demandé, créé avec le rôle propriétaire de la base (comme dans les tests du planning).
    $this->memberOf = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });
});

describe('page', function (): void {
    it('montre au propriétaire ses coordonnées, l\'entreprise et la facturation, modifiables', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/reglages/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Account/Profile')
            ->where('personal.email', 'proprio@example.com')
            ->where('personal.pendingEmail', null)
            ->where('canEditCompany', true)
            ->where('company.name', 'Salon Aurore')
            ->where('company.sector', 'hairdressing')
            ->where('company.timezone', 'Europe/Brussels')
            ->where('billing.billing_name', 'Aurore SRL')
            ->where('billing.vat_number', 'BE0123456789')
            ->has('sectors', count(config('nova.sectors')))
            ->has('countries')
            ->has('timezones'));
    });

    it('est en lecture seule pour les autres rôles, sans identité de facturation', function (string $role): void {
        $user = ($this->memberOf)($role);

        $this->signedIn($user, $this->org)->get('/reglages/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canEditCompany', false)
            ->where('billing', null)
            ->where('company.name', 'Salon Aurore')
            ->where('personal.email', "{$role}@example.com"));
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/reglages/profil')->assertRedirect('/connexion');
    });
});

describe('entreprise', function (): void {
    it('enregistre les informations, trace l\'ancienne et la nouvelle valeur', function (): void {
        $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', ($this->valid)())
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        $org = $this->asOwner(fn () => Organization::query()->find($this->org->id));
        expect($org)->name->toBe('Salon Aurore Deux')->sector->toBe('beauty')->country->toBe('FR')->timezone->toBe('Europe/Paris')->locale->toBe('nl');

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.company.updated')->where('org_id', $this->org->id)->first());
        $diff = json_decode($log->diff, true);
        expect($log->actor_id)->toBe($this->owner->id)
            ->and($diff['name'])->toBe(['Salon Aurore', 'Salon Aurore Deux'])
            ->and($diff['timezone'])->toBe(['Europe/Brussels', 'Europe/Paris']);
    });

    it('fait suivre le nouveau fuseau aux sites qui suivaient celui de l\'entreprise', function (): void {
        $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', ($this->valid)(['timezone' => 'Europe/Paris']));

        expect($this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->value('timezone')))->toBe('Europe/Paris');
    });

    it('supprime les espaces autour du nom', function (): void {
        $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', ($this->valid)(['name' => '   Chez Léa  ']));

        expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('Chez Léa');
    });

    it('n\'écrit pas d\'entrée d\'audit quand rien ne change', function (): void {
        $same = ['name' => 'Salon Aurore', 'sector' => 'hairdressing', 'country' => 'BE', 'timezone' => 'Europe/Brussels', 'locale' => 'fr'];

        $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', $same)->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.company.updated')->count()))->toBe(0);
    });

    it('refuse des valeurs invalides sans rien modifier', function (array $override, string $field): void {
        $this->signedIn($this->owner, $this->org)->put('/reglages/profil/entreprise', ($this->valid)($override))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('Salon Aurore');
    })->with([
        'nom trop court' => [['name' => 'A'], 'name'],
        'secteur inconnu' => [['sector' => 'inconnu'], 'sector'],
        'ancien secteur barbier' => [['sector' => 'barber'], 'sector'],
        'pays inconnu' => [['country' => 'ZZ'], 'country'],
        'fuseau inconnu' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'langue inconnue' => [['locale' => 'de'], 'locale'],
    ]);

    it('est refusée aux autres rôles', function (string $role): void {
        $user = ($this->memberOf)($role);

        $this->signedIn($user, $this->org)->put('/reglages/profil/entreprise', ($this->valid)())->assertForbidden();

        expect($this->asOwner(fn () => Organization::query()->find($this->org->id)->name))->toBe('Salon Aurore');
    })->with(['site_manager', 'practitioner', 'receptionist']);
});
