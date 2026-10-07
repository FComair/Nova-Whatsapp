<?php

declare(strict_types=1);

use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Résumé du jour du tableau de bord (flowchart 2B). « Maintenant » = lundi 1er juin 2026, 08:00 UTC = 10:00 à Bruxelles.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'active', 'timezone' => 'Europe/Brussels']);
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->book = fn (string $local, ?Practitioner $practitioner = null) => app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, new NewBooking(
        $this->location->id, $this->service->id, ($practitioner ?? $this->practitioner)->id, $this->customer->id, $this->brussels($local), 'dashboard', Actor::user(null), enforcePolicies: false,
    )));
    $this->summary = fn (?User $user = null) => ($this->as)($user)->get('/tableau-de-bord')->assertOk();
});

it('affiche un jour vide', function (): void {
    ($this->summary)()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->where('summary.date', '2026-06-01')
        ->where('summary.counts', ['today' => 0, 'remaining' => 0, 'completed' => 0, 'cancelled' => 0, 'tomorrow' => 0, 'week' => 0, 'new_customers' => 1])
        ->where('summary.next', null)->where('summary.appointments', []));
});

it('liste les rendez-vous du jour dans l\'ordre, avec le prochain', function (): void {
    ($this->book)('2026-06-01 09:00');
    ($this->book)('2026-06-01 14:00');
    ($this->book)('2026-06-01 11:00');

    ($this->summary)()->assertInertia(fn (Assert $page) => $page
        ->where('summary.counts.today', 3)->where('summary.counts.remaining', 2)
        ->where('summary.appointments.0.starts', '09:00')->where('summary.appointments.0.is_past', true)->where('summary.appointments.0.is_next', false)
        ->where('summary.appointments.1.starts', '11:00')->where('summary.appointments.1.is_next', true)
        ->where('summary.appointments.2.starts', '14:00')
        ->where('summary.appointments.1.customer', 'Client Test')->where('summary.appointments.1.service', 'Coupe')->where('summary.appointments.1.practitioner', 'Léa')
        ->where('summary.next.starts', '11:00'));
});

it('compte demain, les 7 prochains jours et les annulations du jour', function (): void {
    ($this->book)('2026-06-01 15:00');
    ($this->book)('2026-06-02 10:00');
    ($this->book)('2026-06-05 10:00');
    ($this->book)('2026-06-12 10:00');
    $cancelled = ($this->book)('2026-06-01 16:00');
    app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->cancel($this->org, $cancelled, Actor::user(null)));

    ($this->summary)()->assertInertia(fn (Assert $page) => $page
        ->where('summary.counts.today', 1)->where('summary.counts.cancelled', 1)->where('summary.counts.tomorrow', 1)->where('summary.counts.week', 3)
        ->has('summary.appointments', 1));
});

it('suit le fuseau de l\'établissement pour la limite du jour', function (): void {
    // Ouvert toute la journée pour pouvoir placer un rendez-vous juste avant et juste après minuit (heure de Bruxelles).
    $this->asOwner(function (): void {
        DB::table('business_hours')->update(['starts_at' => '00:00', 'ends_at' => '23:59']);
        DB::table('practitioner_schedules')->update(['starts_at' => '00:00', 'ends_at' => '23:59']);
    });
    $this->travelTo($this->brussels('2026-06-01 21:00'));
    ($this->book)('2026-06-01 23:00');
    ($this->book)('2026-06-02 00:30');

    ($this->summary)()->assertInertia(fn (Assert $page) => $page->where('summary.date', '2026-06-01')->where('summary.counts.today', 1)->where('summary.counts.tomorrow', 1)->where('summary.appointments.0.starts', '23:00'));
});

it('ne montre au praticien que ses propres rendez-vous, sans compteur de clients', function (): void {
    $other = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']));
    ['user' => $user] = $this->activatedAccount(email: 'lea@example.com', role: 'practitioner');
    $this->asOwner(function () use ($user): void {
        OrgMember::query()->where('user_id', $user->id)->update(['org_id' => $this->org->id]);
        $this->practitioner->forceFill(['user_id' => $user->id])->save();
    });
    ($this->book)('2026-06-01 11:00');

    $this->flushSession();
    ($this->summary)($user)->assertInertia(fn (Assert $page) => $page->where('summary.counts.today', 1)->where('summary.counts.new_customers', null)->where('firstSteps', null));
});

it('limite le responsable de site à ses sites', function (): void {
    $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Annexe']));
    ['user' => $manager] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');
    $this->asOwner(function () use ($manager, $second): void {
        $member = OrgMember::query()->where('user_id', $manager->id)->firstOrFail();
        $member->forceFill(['org_id' => $this->org->id])->save();
        DB::table('org_member_locations')->insert(['org_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $second->id]);
    });
    ($this->book)('2026-06-01 11:00');

    $this->flushSession();
    ($this->summary)($manager)->assertInertia(fn (Assert $page) => $page->where('summary.counts.today', 0)->where('summary.multi_site', false));
});

describe('premiers pas', function (): void {
    it('guide le propriétaire et disparaît quand tout est fait', function (): void {
        ($this->summary)()->assertInertia(fn (Assert $page) => $page->where('firstSteps', [
            ['key' => 'whatsapp', 'done' => false],
            ['key' => 'two_factor', 'done' => false],
            ['key' => 'first_booking', 'done' => false],
            ['key' => 'calendar', 'done' => false],
            ['key' => 'billing', 'done' => false],
        ]));

        ($this->book)('2026-06-02 10:00');
        $this->asOwner(fn () => ExternalCalendar::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'name' => 'Google', 'kind' => 'ics', 'url_enc' => 'https://calendar.example.com/a.ics']));

        ($this->summary)()->assertInertia(fn (Assert $page) => $page->where('firstSteps.2.done', true)->where('firstSteps.3.done', true)->where('firstSteps.1.done', false));
    });

    it('n\'est pas proposé aux autres rôles', function (string $role): void {
        ['user' => $member, 'org' => $memberOrg] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($member, $memberOrg)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('firstSteps', null));
    })->with(['site_manager', 'receptionist']);
});

describe('essai', function (): void {
    it('indique les jours restants', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'trial', 'trial_ends_at' => now()->addDays(5)->addHours(2)])->save());

        ($this->summary)()->assertInertia(fn (Assert $page) => $page->where('trial.days', 6));
    });

    it('n\'affiche rien hors essai', function (): void {
        ($this->summary)()->assertInertia(fn (Assert $page) => $page->where('trial', null));
    });
});
