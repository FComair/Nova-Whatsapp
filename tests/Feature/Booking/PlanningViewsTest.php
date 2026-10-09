<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Tenancy\Support\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Vues du planning : jour, 3 jours, semaine de travail, praticiens. Le serveur charge les bons jours, avec leurs horaires.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->book = fn (string $start, string $key): Booking => app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request($start, key: $key)));
    $this->plan = fn (string $query) => $this->signedIn($this->owner, $this->org)->get('/planning?'.$query);
});

it('affiche la semaine par défaut', function (): void {
    ($this->plan)('date=2026-06-03')->assertInertia(fn (Assert $page) => $page->where('view', 'week')->has('week.days', 7)->where('week.start', '2026-06-01')->where('week.end', '2026-06-07'));
});

it('revient à la semaine pour une vue inconnue', function (): void {
    ($this->plan)('date=2026-06-03&view=mois')->assertInertia(fn (Assert $page) => $page->where('view', 'week')->has('week.days', 7));
});

it('charge un seul jour en vue jour, avec ses horaires d\'ouverture', function (): void {
    ($this->book)('2026-06-02 10:00', 'a');
    ($this->book)('2026-06-03 10:00', 'b');

    ($this->plan)('date=2026-06-02&view=day')->assertInertia(fn (Assert $page) => $page
        ->where('view', 'day')
        ->has('week.days', 1)
        ->where('week.days.0.date', '2026-06-02')
        ->where('week.days.0.open', [['start' => 540, 'end' => 1020]])
        ->has('bookings', 1)
        ->where('bookings.0.date', '2026-06-02'));
});

it('charge 3 jours glissants, même sur la semaine suivante', function (): void {
    ($this->book)('2026-06-05 10:00', 'a');
    ($this->book)('2026-06-08 10:00', 'b');
    ($this->book)('2026-06-10 10:00', 'c');

    ($this->plan)('date=2026-06-07&view=3days')->assertInertia(fn (Assert $page) => $page
        ->where('week.start', '2026-06-07')
        ->where('week.end', '2026-06-09')
        ->has('week.days', 3)
        ->where('week.days.1.open', [['start' => 540, 'end' => 1020]])
        ->has('bookings', 1)
        ->where('bookings.0.date', '2026-06-08'));
});

it('charge la semaine entière en vue praticiens', function (): void {
    ($this->book)('2026-06-02 10:00', 'a');
    ($this->book)('2026-06-04 10:00', 'b');

    ($this->plan)('date=2026-06-03&view=practitioners')->assertInertia(fn (Assert $page) => $page->where('view', 'practitioners')->has('week.days', 7)->has('bookings', 2)->has('practitioners', 1));
});

it('calcule la plage horaire sur les jours affichés seulement', function (): void {
    // Samedi : ni ouverture ni horaire de praticien, donc la plage par défaut ; en semaine : 07:00–19:00 pour 09:00–17:00.
    ($this->plan)('date=2026-06-06&view=day')->assertInertia(fn (Assert $page) => $page->where('range', ['from' => 360, 'to' => 1320]));
    ($this->plan)('date=2026-06-02&view=day')->assertInertia(fn (Assert $page) => $page->where('range', ['from' => 420, 'to' => 1140]));
});
