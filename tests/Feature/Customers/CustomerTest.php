<?php

declare(strict_types=1);

use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Fiches clients : liste, recherche, fiche, modification, blocage, droits par rôle et isolation.
 */
function customerInOrg(Organization $org, callable $callback): mixed
{
    return app(TenantContext::class)->run($org->id, $callback);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->addCustomer = fn (string $phone, ?string $name = null): Customer => $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => $phone, 'name' => $name]));
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
    $this->bookFor = fn (Customer $customer, string $start = '2026-06-02 10:00', ?string $notes = null, ?Practitioner $practitioner = null) => customerInOrg($this->org, fn () => app(BookingCore::class)->create($this->org, new NewBooking(
        $this->location->id, $this->service->id, ($practitioner ?? $this->practitioner)->id, $customer->id, $this->brussels($start), 'dashboard', Actor::user(null), notes: $notes,
    )));
});

describe('liste et recherche', function (): void {
    it('liste les clients par ordre alphabétique avec leur prochain rendez-vous', function (): void {
        ($this->addCustomer)('+32470000002', 'Zoé');
        ($this->addCustomer)('+32470000003', 'alice');
        ($this->bookFor)($this->customer);

        ($this->as)()->get('/clients')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index')
            ->has('customers', 3)
            ->where('customers.0.name', 'alice')
            ->where('customers.1.name', 'Client Test')
            ->where('customers.1.last', null)
            ->where('customers.1.next.at', '2026-06-02T10:00')
            ->where('customers.2.name', 'Zoé')
            ->where('customers.2.last', null)
            ->where('pagination.total', 3));
    });

    it('recherche par nom, e-mail ou chiffres du téléphone', function (string $query, int $expected): void {
        ($this->addCustomer)('+32470000002', 'Zoé Martin')->forceFill(['email' => 'zoe@example.com'])->save();

        ($this->as)()->get('/clients?q='.urlencode($query))->assertInertia(fn (Assert $page) => $page->has('customers', $expected)->where('q', $query));
    })->with([
        'nom' => ['martin', 1],
        'e-mail' => ['zoe@', 1],
        'téléphone' => ['470 00 00 02', 1],
        'partiel commun' => ['+32470', 2],
        'aucun résultat' => ['inconnu', 0],
        'joker % non interprété' => ['%', 0],
    ]);

    it('pagine par 25', function (): void {
        foreach (range(10, 40) as $i) {
            ($this->addCustomer)("+3247000{$i}00", "Client {$i}");
        }

        ($this->as)()->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 25)->where('pagination.last_page', 2));
        ($this->as)()->get('/clients?page=2')->assertInertia(fn (Assert $page) => $page->has('customers', 7)->where('pagination.page', 2));
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/clients')->assertRedirect('/connexion');
    });
});

describe('fiche', function (): void {
    it('affiche les informations et l\'historique', function (): void {
        ($this->bookFor)($this->customer, '2026-06-02 10:00', 'Coupe courte');
        ($this->bookFor)($this->customer, '2026-06-03 11:00');

        ($this->as)()->get("/clients/{$this->customer->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.phone', '+32470123456')
            ->has('history', 2)
            ->where('history.0.date', '2026-06-03')
            ->where('history.1.time', '10:00')
            ->where('history.1.notes', 'Coupe courte')
            ->where('history.1.service', 'Coupe'));
    });

    it('masque les notes au réceptionniste', function (): void {
        ($this->bookFor)($this->customer, '2026-06-02 10:00', 'Allergie');
        $receptionist = ($this->member)('receptionist');

        ($this->as)($receptionist)->get("/clients/{$this->customer->id}")->assertInertia(fn (Assert $page) => $page->where('history.0.notes', null));
    });

    it('répond 404 pour un client d\'une autre organisation', function (): void {
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->get("/clients/{$this->customer->id}")->assertNotFound();
        $this->signedIn($stranger, $otherOrg)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 0));
    });
});

describe('modification et blocage', function (): void {
    it('modifie la fiche et n\'audite que les champs modifiés', function (): void {
        ($this->as)()->put("/clients/{$this->customer->id}", ['name' => '  Nouveau Nom ', 'email' => 'NOUVEAU@Example.com', 'language' => 'nl'])
            ->assertSessionHas('status', 'Fiche client enregistrée.');

        $customer = $this->asOwner(fn () => Customer::query()->findOrFail($this->customer->id));
        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'customer.updated')->sole());

        expect($customer->name)->toBe('Nouveau Nom')->and($customer->email)->toBe('nouveau@example.com')->and($customer->language)->toBe('nl')
            ->and(json_decode($audit->diff, true))->toEqual(['fields' => ['name', 'email', 'language']])
            ->and($audit->entity_id)->toBe($this->customer->id);
    });

    it('n\'écrit rien dans le journal quand rien ne change', function (): void {
        ($this->as)()->put("/clients/{$this->customer->id}", ['name' => 'Client Test', 'email' => '', 'language' => ''])->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'customer.updated')->count()))->toBe(0);
    });

    it('valide les champs et ne permet pas de changer le numéro', function (): void {
        ($this->as)()->put("/clients/{$this->customer->id}", ['email' => 'pas-un-email', 'language' => 'de'])->assertSessionHasErrors(['email', 'language']);

        ($this->as)()->put("/clients/{$this->customer->id}", ['name' => 'X', 'phone_e164' => '+32999999999']);

        expect($this->asOwner(fn () => Customer::query()->findOrFail($this->customer->id)->phone_e164))->toBe('+32470123456');
    });

    it('bloque puis débloque, avec trace, et empêche alors la réservation', function (): void {
        ($this->as)()->put("/clients/{$this->customer->id}/blocage", ['blocked' => true])->assertSessionHas('status', 'Client bloqué.');

        expect($this->asOwner(fn () => Customer::query()->findOrFail($this->customer->id)->is_blocked))->toBeTrue();

        ($this->as)()->post('/planning/rendez-vous', [
            'location_id' => $this->location->id, 'service_id' => $this->service->id, 'practitioner_id' => $this->practitioner->id,
            'date' => '2026-06-02', 'time' => '10:00', 'phone' => '+32 470 12 34 56',
        ])->assertSessionHasErrors(['booking' => 'Ce client est bloqué : aucun rendez-vous ne peut lui être attribué.']);

        ($this->as)()->put("/clients/{$this->customer->id}/blocage", ['blocked' => false])->assertSessionHas('status', 'Client débloqué.');

        expect($this->asOwner(fn () => DB::table('audit_logs')->whereIn('action', ['customer.blocked', 'customer.unblocked'])->orderBy('created_at')->pluck('action')->all()))->toBe(['customer.blocked', 'customer.unblocked']);
    });

    it('ne journalise pas un blocage sans effet', function (): void {
        ($this->as)()->put("/clients/{$this->customer->id}/blocage", ['blocked' => false])->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'like', 'customer.%')->count()))->toBe(0);
    });

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->as)()->put("/clients/{$this->customer->id}/blocage", ['blocked' => true])->assertRedirect(route('account.state'));

        expect($this->asOwner(fn () => Customer::query()->findOrFail($this->customer->id)->is_blocked))->toBeFalse();
    });
});

describe('praticien', function (): void {
    it('ne voit que ses propres clients', function (): void {
        $marc = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

            return $practitioner;
        });
        $other = ($this->addCustomer)('+32470000009', 'Cliente de Marc');
        ($this->bookFor)($this->customer);
        ($this->bookFor)($other, '2026-06-02 10:00', 'Privé', $marc);
        $lea = ($this->member)('practitioner', $this->practitioner);

        ($this->as)($lea)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 1)->where('customers.0.id', $this->customer->id));
        ($this->as)($lea)->get("/clients/{$other->id}")->assertForbidden();
        ($this->as)($lea)->put("/clients/{$other->id}", ['name' => 'Piraté'])->assertForbidden();
        ($this->as)($lea)->put("/clients/{$other->id}/blocage", ['blocked' => true])->assertForbidden();
        ($this->as)($lea)->get("/clients/{$this->customer->id}")->assertOk();
    });

    it('ne voit aucun client sans fiche praticien', function (): void {
        $user = ($this->member)('practitioner');

        ($this->as)($user)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 0));
    });
});

describe('langues', function (): void {
    it('affiche la confirmation dans la langue du compte', function (string $locale, string $message): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->as)()->put("/clients/{$this->customer->id}/blocage", ['blocked' => true])->assertSessionHas('status', $message);
    })->with([
        ['fr', 'Client bloqué.'],
        ['nl', 'Klant geblokkeerd.'],
        ['en', 'Customer blocked.'],
    ]);
});
