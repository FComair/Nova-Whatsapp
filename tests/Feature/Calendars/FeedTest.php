<?php

declare(strict_types=1);

use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Calendars\Models\CalendarFeed;
use App\Modules\Calendars\Services\CalendarFeedService;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * Flux ICS sortant d'un praticien : adresse secrète, aucune donnée de client, jeton renouvelable.
 */
beforeEach(function (): void {
    $this->bookingWorld();
    $this->feeds = app(CalendarFeedService::class);
    $this->token = fn (): string => $this->feeds->tokenFor($this->org, $this->practitioner);
    $this->fetch = fn (string $token) => $this->get('/ics/'.$token.'.ics');
    $this->book = fn (string $start = '2026-06-02 10:00', string $key = 'k1') => app(BookingCore::class)->create($this->org, $this->request($start, key: $key));
});

it('publie les rendez-vous confirmés du praticien au format iCalendar', function (): void {
    $booking = ($this->book)();
    $token = ($this->token)();
    app(TenantContext::class)->clear();

    $response = ($this->fetch)($token)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $ics = $response->getContent();

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")->toContain("UID:{$booking->id}@feed.nova")->toContain('DTSTART:20260602T080000Z')->toContain('DTEND:20260602T083000Z')
        ->toContain('SUMMARY:Rendez-vous Rendivoo : Coupe')->toContain('STATUS:CONFIRMED')->toContain('X-WR-CALNAME:Rendivoo · Léa');
});

it('ne contient aucune donnée de client, ni note', function (): void {
    $this->asOwner(fn () => $this->customer->forceFill(['email' => 'client@example.com'])->save());
    app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-02 10:00'), 'dashboard', Actor::user(null), notes: 'Allergie au latex'));
    $token = ($this->token)();
    app(TenantContext::class)->clear();

    $ics = ($this->fetch)($token)->getContent();

    expect($ics)->not->toContain('Client Test')->not->toContain('470123456')->not->toContain('client@')->not->toContain('latex')->not->toContain('ATTENDEE');
});

it('n\'inclut ni les rendez-vous annulés, ni ceux d\'un autre praticien, ni les plus anciens que 7 jours', function (): void {
    $cancelled = ($this->book)('2026-06-02 10:00', 'a');
    app(BookingCore::class)->cancel($this->org, $cancelled, Actor::user(null));
    ($this->book)('2026-06-03 10:00', 'b');
    $token = ($this->token)();
    app(TenantContext::class)->clear();

    expect(substr_count(($this->fetch)($token)->getContent(), 'BEGIN:VEVENT'))->toBe(1);
});

it('écrit le titre dans la langue de l\'établissement', function (string $locale, string $summary): void {
    $this->asOwner(fn () => $this->org->forceFill(['locale' => $locale])->save());
    $this->org->refresh();
    ($this->book)();
    $token = ($this->token)();
    app(TenantContext::class)->clear();

    expect(($this->fetch)($token)->getContent())->toContain($summary);
})->with([['fr', 'SUMMARY:Rendez-vous Rendivoo : Coupe'], ['nl', 'SUMMARY:Rendivoo-afspraak: Coupe'], ['en', 'SUMMARY:Rendivoo appointment: Coupe']]);

it('coupe les lignes longues à 75 octets sans casser un caractère', function (): void {
    $this->asOwner(fn () => $this->service->forceFill(['name' => str_repeat('Coiffure très élégante ', 4)])->save());
    ($this->book)();
    $token = ($this->token)();
    app(TenantContext::class)->clear();

    $lines = explode("\r\n", ($this->fetch)($token)->getContent());

    expect(collect($lines)->every(fn (string $line): bool => strlen($line) <= 75))->toBeTrue()
        ->and(collect($lines)->contains(fn (string $line): bool => str_starts_with($line, ' ')))->toBeTrue()
        ->and(mb_check_encoding(implode('', $lines), 'UTF-8'))->toBeTrue();
});

describe('jeton', function (): void {
    it('renvoie 404 pour un jeton inconnu ou mal formé', function (string $token): void {
        ($this->fetch)($token)->assertNotFound();
    })->with(['nf_'.'A'.'b', 'nf_'.'0123456789012345678901234567890123456789', 'x', '../../etc/passwd']);

    it('garde le même jeton tant qu\'il n\'est pas renouvelé', function (): void {
        expect(($this->token)())->toBe(($this->token)())->and(str_starts_with(($this->token)(), 'nf_'))->toBeTrue();
    });

    it('invalide l\'ancienne adresse au renouvellement', function (): void {
        $old = ($this->token)();
        $new = $this->feeds->rotate($this->org, $this->practitioner);
        app(TenantContext::class)->clear();

        expect($new)->not->toBe($old);
        ($this->fetch)($old)->assertNotFound();
        ($this->fetch)($new)->assertOk();
        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'calendar_feed.rotated')->count()))->toBe(2);
    });

    it('ne stocke pas le jeton en clair', function (): void {
        $token = ($this->token)();

        $raw = $this->asOwner(fn () => json_encode(DB::table('calendar_feeds')->first()));

        expect($raw)->not->toContain($token)->and(app(TenantContext::class)->run($this->org->id, fn () => CalendarFeed::query()->sole()->token_enc))->toBe($token);
    });

    it('ne donne accès qu\'à un seul praticien', function (): void {
        $marc = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']));
        ($this->book)();
        $token = $this->feeds->tokenFor($this->org, $marc);
        app(TenantContext::class)->clear();

        expect(($this->fetch)($token)->getContent())->not->toContain('BEGIN:VEVENT')->toContain('Rendivoo · Marc');
    });
});

describe('état du compte', function (): void {
    it('reste lisible pour un compte suspendu, introuvable pour un compte en pause ou supprimé', function (string $status, int $expected): void {
        $token = ($this->token)();
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());
        app(TenantContext::class)->clear();

        ($this->fetch)($token)->assertStatus($expected);
    })->with([['suspended', 200], ['paused', 404], ['pending_deletion', 404], ['deleted', 404]]);
});
