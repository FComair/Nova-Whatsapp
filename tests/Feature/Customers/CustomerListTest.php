<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerSavedView;
use App\Modules\Customers\Services\ConsentRecorder;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Liste des clients enrichie (docs/21, phase 2) : recherche, filtres, dernier et prochain rendez-vous, actions groupées,
 * vues enregistrées, droits par rôle.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->add = fn (string $phone, ?string $name = null, array $extra = []): Customer => $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => $phone, 'name' => $name] + $extra)->refresh());
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
    $this->bookFor = fn (Customer $customer, string $start = '2026-06-02 10:00') => app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, new NewBooking(
        $this->location->id, $this->service->id, $this->practitioner->id, $customer->id, $this->brussels($start), 'dashboard', Actor::user(null),
    )));
    $this->rows = fn (string $query = '') => ($this->as)()->get('/clients'.$query)->viewData('page')['props']['customers'];
    $this->names = fn (string $query = ''): array => collect(($this->rows)($query))->pluck('name')->sort()->values()->all();
});

describe('rendez-vous et chiffres de la ligne', function (): void {
    it('sépare le dernier rendez-vous (passé) du prochain (à venir) et compte visites et valeur', function (): void {
        $booking = ($this->bookFor)($this->customer, '2026-06-02 10:00');
        ($this->bookFor)($this->customer, '2026-06-19 10:00');
        $this->asOwner(fn () => Booking::query()->whereKey($booking->id)->update(['status' => 'completed', 'price_cents' => 4000, 'amount_collected_cents' => 3500]));
        $this->travelTo(CarbonImmutable::parse('2026-06-10 08:00:00', 'UTC'));

        ($this->as)()->get('/clients')->assertInertia(fn (Assert $page) => $page
            ->where('customers.0.last.at', '2026-06-02T10:00')
            ->where('customers.0.next.at', '2026-06-19T10:00')
            ->where('customers.0.visits', 1)
            ->where('customers.0.value_cents', 3500)
            ->where('customers.0.reference', 'C-'.$this->customer->refresh()->reference_number));
    });
});

describe('recherche', function (): void {
    it('ignore accents et majuscules, et trouve par référence ou par numéro dans tous les formats', function (string $query, int $expected): void {
        ($this->add)('+32470000005', 'Aïcha Martin');

        expect(($this->rows)('?q='.urlencode($query)))->toHaveCount($expected);
    })->with([
        'sans accent' => ['aicha', 1],
        'majuscules' => ['AÏCHA', 1],
        'référence' => ['c-1002', 1],
        'numéro local' => ['0470 00 00 05', 1],
        'numéro international' => ['+32 470 00 00 05', 1],
        'préfixe 00' => ['0032470000005', 1],
        'inconnu' => ['zzz', 0],
    ]);
});

describe('filtres', function (): void {
    beforeEach(function (): void {
        $this->vip = ($this->add)('+32470000010', 'Vip Client', ['tags' => ['vip'], 'acquisition_source' => 'walk_in']);
        $this->quiet = ($this->add)('+32470000011', 'Calme Client');
        $this->gone = ($this->add)('+32470000012', 'Archivé Client');
        $this->asOwner(fn () => $this->gone->setStatus('archived'));
    });

    it('masque les archivés par défaut et les montre sur demande', function (): void {
        expect(($this->names)())->not->toContain('Archivé Client');
        expect(($this->names)('?status=archived'))->toBe(['Archivé Client']);
        expect(($this->names)('?status=all'))->toContain('Archivé Client');
    });

    it('filtre par étiquette et par provenance', function (): void {
        expect(($this->names)('?tag=vip'))->toBe(['Vip Client']);
        expect(($this->names)('?source=walk_in'))->toBe(['Vip Client']);
    });

    it('filtre selon les rendez-vous', function (): void {
        ($this->bookFor)($this->vip, '2026-06-02 10:00');

        expect(($this->names)('?appt=upcoming'))->toBe(['Vip Client']);
        expect(($this->names)('?appt=none'))->toContain('Calme Client')->not->toContain('Vip Client');

        $this->asOwner(fn () => Booking::query()->where('customer_id', $this->vip->id)->update(['status' => 'no_show']));
        expect(($this->names)('?appt=no_show'))->toBe(['Vip Client']);
    });

    it('trouve les clients sans visite récente', function (): void {
        $booking = ($this->bookFor)($this->vip, '2026-06-02 10:00');
        $this->asOwner(fn () => Booking::query()->whereKey($booking->id)->update(['status' => 'completed']));
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

        expect(($this->names)('?appt=inactive&days=90'))->toBe(['Vip Client']);
        expect(($this->names)('?appt=inactive&days=200'))->toBe([]);
    });

    it('filtre selon le consentement WhatsApp', function (): void {
        $this->asOwner(function (): void {
            $recorder = app(ConsentRecorder::class);
            $recorder->record($this->vip, 'marketing', 'granted', 'form');
            $recorder->recordStop($this->quiet);
        });

        expect(($this->names)('?consent=opted_in'))->toBe(['Vip Client']);
        expect(($this->names)('?consent=opted_out'))->toBe(['Calme Client']);
        expect(($this->names)('?consent=unknown'))->toContain('Client Test')->not->toContain('Vip Client');
    });

    it('ignore les filtres inconnus ou mal formés', function (): void {
        expect(($this->names)('?status=nimporte&appt=%27%3B--&practitioner=pas-un-uuid&tag=&visits_min=abc'))->toHaveCount(3);
    });

    it('filtre par nombre de visites terminées', function (): void {
        $booking = ($this->bookFor)($this->vip, '2026-06-02 10:00');
        $this->asOwner(fn () => Booking::query()->whereKey($booking->id)->update(['status' => 'completed']));

        expect(($this->names)('?visits_min=1'))->toBe(['Vip Client']);
        expect(($this->names)('?visits_min=2'))->toBe([]);
    });

    it('trie par valeur décroissante', function (): void {
        $booking = ($this->bookFor)($this->quiet, '2026-06-02 10:00');
        $this->asOwner(fn () => Booking::query()->whereKey($booking->id)->update(['status' => 'completed', 'price_cents' => 5000]));

        $rows = ($this->rows)('?sort=value&dir=desc');
        expect($rows[0]['name'])->toBe('Calme Client');
    });
});

describe('actions groupées', function (): void {
    it('ajoute puis retire une étiquette, et journalise une fois sans données personnelles', function (): void {
        $a = ($this->add)('+32470000021', 'A');
        $b = ($this->add)('+32470000022', 'B');

        ($this->as)()->post('/clients/actions', ['action' => 'tag', 'ids' => [$a->id, $b->id], 'tag' => 'vip'])->assertRedirect();
        expect($this->asOwner(fn () => $a->fresh()->tags))->toBe(['vip']);

        ($this->as)()->post('/clients/actions', ['action' => 'tag', 'ids' => [$a->id], 'tag' => 'vip']);
        expect($this->asOwner(fn () => $a->fresh()->tags))->toBe(['vip']);

        ($this->as)()->post('/clients/actions', ['action' => 'untag', 'ids' => [$a->id], 'tag' => 'vip']);
        expect($this->asOwner(fn () => $a->fresh()->tags))->toBe([]);

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'customer.bulk_tag')->orderBy('created_at')->first());
        expect(json_decode($log->diff, true))->toBe(['count' => 2, 'selected' => 2]);
    });

    it('archive et rétablit, sans toucher un client bloqué', function (): void {
        $a = ($this->add)('+32470000023', 'A');
        $blocked = ($this->add)('+32470000024', 'Bloqué');
        $this->asOwner(fn () => $blocked->setStatus('blocked'));

        ($this->as)()->post('/clients/actions', ['action' => 'archive', 'ids' => [$a->id, $blocked->id]])->assertRedirect();
        expect($this->asOwner(fn () => $a->fresh()->status))->toBe('archived');
        expect($this->asOwner(fn () => $blocked->fresh()->status))->toBe('blocked');

        ($this->as)()->post('/clients/actions', ['action' => 'unarchive', 'ids' => [$a->id]]);
        expect($this->asOwner(fn () => $a->fresh()->status))->toBe('active');
    });

    it('laisse le réceptionniste étiqueter mais pas archiver, et refuse le praticien', function (): void {
        $a = ($this->add)('+32470000025', 'A');
        $receptionist = ($this->member)('receptionist');
        $practitioner = ($this->member)('practitioner', $this->practitioner);

        ($this->as)($receptionist)->post('/clients/actions', ['action' => 'tag', 'ids' => [$a->id], 'tag' => 'x'])->assertRedirect();
        ($this->as)($receptionist)->post('/clients/actions', ['action' => 'archive', 'ids' => [$a->id]])->assertForbidden();
        ($this->as)($practitioner)->post('/clients/actions', ['action' => 'tag', 'ids' => [$a->id], 'tag' => 'x'])->assertForbidden();
    });

    it('ignore les clients d\'un autre établissement', function (): void {
        $foreign = $this->asOwner(function (): Customer {
            $other = Organization::factory()->create();

            return Customer::query()->create(['org_id' => $other->id, 'phone_e164' => '+32470000099', 'name' => 'Étranger']);
        });

        ($this->as)()->post('/clients/actions', ['action' => 'tag', 'ids' => [$foreign->id], 'tag' => 'pirate'])->assertRedirect();
        expect($this->asOwner(fn () => $foreign->fresh()->tags))->toBe([]);
    });

    it('valide la demande', function (): void {
        ($this->as)()->post('/clients/actions', ['action' => 'delete', 'ids' => []])->assertSessionHasErrors(['action', 'ids']);
        ($this->as)()->post('/clients/actions', ['action' => 'tag', 'ids' => [$this->customer->id]])->assertSessionHasErrors(['tag']);
    });
});

describe('vues enregistrées', function (): void {
    it('enregistre une vue personnelle, invisible pour les autres', function (): void {
        $receptionist = ($this->member)('receptionist');

        ($this->as)()->post('/clients/vues', ['name' => 'Mes VIP', 'filters' => ['tag' => 'vip', 'inconnu' => 'x'], 'columns' => ['contact', 'value'], 'sort' => 'value', 'dir' => 'desc'])->assertRedirect();

        $view = $this->asOwner(fn () => CustomerSavedView::query()->firstOrFail());
        expect($view->filters)->toBe(['tag' => 'vip'])->and($view->shared)->toBeFalse();

        ($this->as)($receptionist)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('views', 0));
        ($this->as)()->get('/clients')->assertInertia(fn (Assert $page) => $page->has('views', 1)->where('views.0.mine', true));
    });

    it('partage une vue seulement quand le rôle le permet', function (): void {
        $receptionist = ($this->member)('receptionist');

        ($this->as)($receptionist)->post('/clients/vues', ['name' => 'Tentative', 'shared' => true]);
        ($this->as)()->post('/clients/vues', ['name' => 'Équipe', 'shared' => true]);

        $shared = $this->asOwner(fn () => CustomerSavedView::query()->orderBy('name')->pluck('shared', 'name')->all());
        expect($shared)->toEqual(['Tentative' => false, 'Équipe' => true]);

        ($this->as)($receptionist)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('views', 2));
    });

    it('supprime sa vue mais pas celle des autres', function (): void {
        $receptionist = ($this->member)('receptionist');
        ($this->as)($receptionist)->post('/clients/vues', ['name' => 'À elle']);
        $view = $this->asOwner(fn () => CustomerSavedView::query()->firstOrFail());

        ($this->as)()->delete("/clients/vues/{$view->id}")->assertForbidden();
        ($this->as)($receptionist)->delete("/clients/vues/{$view->id}")->assertRedirect();
        expect($this->asOwner(fn () => CustomerSavedView::query()->count()))->toBe(0);
    });

    it('refuse une colonne inconnue', function (): void {
        ($this->as)()->post('/clients/vues', ['name' => 'X', 'columns' => ['mot_de_passe']])->assertSessionHasErrors(['columns.0']);
    });
});

describe('praticien', function (): void {
    it('ne voit que ses clients, y compris avec filtres et recherche', function (): void {
        ($this->add)('+32470000031', 'Autre Client');
        ($this->bookFor)($this->customer, '2026-06-02 10:00');
        $practitioner = ($this->member)('practitioner', $this->practitioner);

        ($this->as)($practitioner)->get('/clients')->assertInertia(fn (Assert $page) => $page->has('customers', 1)->where('scoped', true)->where('can.tag', false)->where('can.archive', false));
        ($this->as)($practitioner)->get('/clients?q=autre&status=all')->assertInertia(fn (Assert $page) => $page->has('customers', 0));
    });
});
