<?php

declare(strict_types=1);

use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Calendars\Contracts\HostResolver;
use App\Modules\Calendars\Models\ExternalBusySlot;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Services\CalendarSync;
use App\Modules\Calendars\Support\CalendarFetchFailed;
use App\Modules\Calendars\Support\SafeHttp;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingScenarios;
use Tests\Support\FakeHostResolver;

uses(BookingScenarios::class);

/**
 * Calendriers externes en lecture seule : lecture sûre (SSRF), synchronisation ICS et CalDAV, effet sur les disponibilités.
 * « Maintenant » = lundi 1er juin 2026, 08:00 UTC ; le mardi 2 juin est ouvert de 9 h à 17 h (Bruxelles).
 */
const ICS_URL = 'https://calendar.example.com/private/abc123/basic.ics';

function eventIcs(string ...$events): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".implode("\r\n", $events)."\r\nEND:VCALENDAR\r\n";
}

function busyEvent(string $start, string $end, string $uid = 'e1', string $extra = ''): string
{
    return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:Rendez-vous médical confidentiel\r\n{$extra}END:VEVENT";
}

beforeEach(function (): void {
    $this->bookingWorld();
    $this->resolver = new FakeHostResolver([
        'calendar.example.com' => ['93.184.216.34'],
        'caldav.example.com' => ['93.184.216.35', '2606:2800:220:1:248:1893:25c8:1946'],
        'evil.example.com' => ['10.0.0.5'],
        'mixed.example.com' => ['93.184.216.34', '192.168.1.10'],
        'metadata.example.com' => ['169.254.169.254'],
    ]);
    app()->instance(HostResolver::class, $this->resolver);

    $this->calendar = fn (array $overrides = []): ExternalCalendar => $this->asOwner(fn () => ExternalCalendar::query()->create([
        'org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'name' => 'Google', 'kind' => 'ics', 'url_enc' => ICS_URL, ...$overrides,
    ]));
    $this->sync = fn (ExternalCalendar $calendar): bool => app(CalendarSync::class)->sync(ExternalCalendar::query()->findOrFail($calendar->id), $this->org);
    $this->starts = fn () => collect(app(AvailabilityService::class)->slots($this->org, $this->location, $this->service, '2026-06-02', '2026-06-02'))
        ->map(fn ($slot): string => $slot->startsAt->setTimezone('Europe/Brussels')->format('H:i'))->all();
});

describe('lecture protégée (SSRF)', function (): void {
    it('refuse les adresses dangereuses', function (string $url): void {
        $http = app(SafeHttp::class);

        expect(fn () => $http->send('GET', $url))->toThrow(CalendarFetchFailed::class);
        Http::assertNothingSent();
    })->with([
        'http simple' => ['http://calendar.example.com/a.ics'],
        'ftp' => ['ftp://calendar.example.com/a.ics'],
        'fichier local' => ['file:///etc/passwd'],
        'identifiants dans l\'adresse' => ['https://user:pass@calendar.example.com/a.ics'],
        'autre port' => ['https://calendar.example.com:8443/a.ics'],
        'réseau privé' => ['https://evil.example.com/a.ics'],
        'adresse mixte publique et privée' => ['https://mixed.example.com/a.ics'],
        'métadonnées du cloud' => ['https://metadata.example.com/latest'],
        'IP privée directe' => ['https://10.0.0.1/a.ics'],
        'bouclage IPv4' => ['https://127.0.0.1/a.ics'],
        'bouclage IPv6' => ['https://[::1]/a.ics'],
        'lien local' => ['https://169.254.169.254/latest/meta-data'],
        'hôte inconnu' => ['https://inconnu.example.com/a.ics'],
        'sans hôte' => ['https:///a.ics'],
    ]);

    it('accepte webcal:// comme un https://', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs())]);

        expect(app(SafeHttp::class)->send('GET', 'webcal://calendar.example.com/a.ics')->status())->toBe(200);
    });

    it('revalide chaque redirection', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/interne'])]);

        expect(fn () => app(SafeHttp::class)->send('GET', ICS_URL))->toThrow(CalendarFetchFailed::class, 'blocked_host');
    });

    it('suit une redirection sûre mais pas plus de trois', function (): void {
        Http::fake(['calendar.example.com/private/*' => Http::response('', 302, ['Location' => '/ok.ics']), 'calendar.example.com/ok.ics' => Http::response('bien')]);
        expect(app(SafeHttp::class)->send('GET', ICS_URL)->body())->toBe('bien');

        Http::fake(['calendar.example.com/loop*' => Http::response('', 302, ['Location' => '/loop2'])]);
        expect(fn () => app(SafeHttp::class)->send('GET', 'https://calendar.example.com/loop'))->toThrow(CalendarFetchFailed::class, 'redirect');
    });

    it('borne la taille de la réponse et convertit les délais dépassés', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(str_repeat('a', SafeHttp::MAX_BYTES + 1))]);
        expect(fn () => app(SafeHttp::class)->send('GET', ICS_URL))->toThrow(CalendarFetchFailed::class, 'too_large');

        Http::fake(fn () => throw new ConnectionException('timeout'));
        expect(fn () => app(SafeHttp::class)->send('GET', ICS_URL))->toThrow(CalendarFetchFailed::class, 'timeout');
    });
});

describe('synchronisation ICS', function (): void {
    it('bloque le temps occupé dans les disponibilités du praticien', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))]);
        $calendar = ($this->calendar)();

        expect(($this->starts)())->toContain('10:00');

        expect(($this->sync)($calendar))->toBeTrue();

        $starts = ($this->starts)();

        expect($starts)->toContain('09:30')->not->toContain('09:45')->not->toContain('10:00')->not->toContain('10:45')->toContain('11:00');
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)))->status->toBe('active')->failure_count->toBe(0)->last_error->toBeNull()->last_synced_at->not->toBeNull();
    });

    it('empêche de réserver sur le temps occupé, et propose d\'autres créneaux', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))]);
        ($this->sync)(($this->calendar)());

        try {
            app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:15'));
            $this->fail('Une exception était attendue.');
        } catch (SlotUnavailable $e) {
            expect($e->reason)->toBe('taken')->and($e->suggestions)->toHaveCount(3);
        }

        expect(app(BookingCore::class)->create($this->org, $this->request('2026-06-02 11:00', key: 'ok'))->status)->toBe('confirmed');
    });

    it('libère le créneau quand l\'événement disparaît du calendrier', function (): void {
        $calendar = ($this->calendar)();
        Http::fake(['calendar.example.com/*' => Http::sequence()->push(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))->push(eventIcs())]);

        ($this->sync)($calendar);
        expect(($this->starts)())->not->toContain('10:00');

        ($this->sync)($calendar);
        expect(($this->starts)())->toContain('10:00')->and($this->asOwner(fn () => ExternalBusySlot::query()->count()))->toBe(0);
    });

    it('déplie les récurrences dans l\'horizon de réservation', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs("BEGIN:VEVENT\r\nUID:r\r\nDTSTART;TZID=Europe/Brussels:20260601T130000\r\nDTEND;TZID=Europe/Brussels:20260601T140000\r\nRRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR\r\nEND:VEVENT"))]);

        ($this->sync)(($this->calendar)());

        expect(($this->starts)())->not->toContain('13:15')->toContain('14:00');
        expect($this->asOwner(fn () => ExternalBusySlot::query()->count()))->toBeGreaterThan(30);
    });

    it('n\'affecte pas les autres praticiens ni un calendrier en pause', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))]);
        $calendar = ($this->calendar)();
        ($this->sync)($calendar);

        $this->asOwner(fn () => $calendar->forceFill(['status' => 'paused'])->save());
        expect(($this->starts)())->toContain('10:00');

        $this->asOwner(fn () => $calendar->forceFill(['status' => 'active'])->save());
        $other = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

            return $practitioner;
        });

        $marc = collect(app(AvailabilityService::class)->slots($this->org, $this->location, $this->service, '2026-06-02', '2026-06-02', $other))->map(fn ($s): string => $s->startsAt->setTimezone('Europe/Brussels')->format('H:i'))->all();

        expect($marc)->toContain('10:00');
    });

    it('ne conserve que des horaires, jamais le contenu des événements', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z', extra: "ATTENDEE:mailto:patient@example.com\r\nLOCATION:Clinique privée\r\n")))]);
        ($this->sync)(($this->calendar)());

        $dump = $this->asOwner(fn () => json_encode([DB::table('external_busy_slots')->get(), DB::table('external_calendars')->get()]));

        expect($dump)->not->toContain('médical')->not->toContain('patient@')->not->toContain('Clinique');
    });

    it('ignore les événements issus du flux sortant de Rendivoo', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z', 'abc'.CalendarSync::FEED_UID_SUFFIX)))]);
        ($this->sync)(($this->calendar)());

        expect(($this->starts)())->toContain('10:00');
    });

    it('chiffre l\'adresse et les identifiants au repos', function (): void {
        ($this->calendar)(['kind' => 'caldav', 'username_enc' => 'utilisateur-secret', 'password_enc' => 'mot-de-passe-secret']);

        $raw = $this->asOwner(fn () => json_encode(DB::table('external_calendars')->first()));

        expect($raw)->not->toContain('abc123')->not->toContain('utilisateur-secret')->not->toContain('mot-de-passe-secret');
    });
});

describe('échecs', function (): void {
    it('conserve les dernières plages connues et consigne le code d\'erreur', function (): void {
        $calendar = ($this->calendar)();
        Http::fake(['calendar.example.com/*' => Http::sequence()->push(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))->push('erreur', 503)]);

        ($this->sync)($calendar);
        expect(($this->sync)($calendar))->toBeFalse();

        expect(($this->starts)())->not->toContain('10:00');
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)))->failure_count->toBe(1)->last_error->toBe('http_5xx')->status->toBe('active');
    });

    it('classe les erreurs sans jamais consigner l\'adresse', function (int $status, string $body, string $code): void {
        Http::fake(['calendar.example.com/*' => Http::response($body, $status)]);
        $calendar = ($this->calendar)();

        ($this->sync)($calendar);

        $row = $this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id));
        expect($row->last_error)->toBe($code)->and(json_encode($row->getAttributes()))->not->toContain('abc123');
    })->with([
        'accès refusé' => [401, '', 'http_401'],
        'introuvable' => [404, '', 'http_404'],
        'panne' => [500, '', 'http_5xx'],
        'pas un iCalendar' => [200, '<html>Connexion</html>', 'invalid_ics'],
    ]);

    it('passe en « erreur » après 5 échecs et ralentit les nouvelles tentatives', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response('', 500)]);
        $calendar = ($this->calendar)();

        foreach (range(1, 5) as $ignored) {
            ($this->sync)($calendar);
        }

        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)))->status->toBe('error')->failure_count->toBe(5);

        $this->travel(20)->minutes();
        expect(app(CalendarSync::class)->due())->toHaveCount(0);
        $this->travel(45)->minutes();
        expect(app(CalendarSync::class)->due())->toHaveCount(1);
    });

    it('bloque une adresse privée saisie par un utilisateur', function (): void {
        $calendar = ($this->calendar)(['url_enc' => 'https://evil.example.com/cal.ics']);
        Http::fake();

        expect(($this->sync)($calendar))->toBeFalse();
        Http::assertNothingSent();
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)->last_error))->toBe('blocked_host');
    });
});

describe('CalDAV', function (): void {
    it('interroge le serveur (REPORT, identifiants, période) et lit les événements', function (): void {
        $multistatus = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:response><d:propstat><d:prop><c:calendar-data><![CDATA['.eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')).']]></c:calendar-data></d:prop></d:propstat></d:response>'
            .'<d:response><d:propstat><d:prop><c:calendar-data><![CDATA['.eventIcs(busyEvent('20260602T130000Z', '20260602T140000Z', 'e2')).']]></c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>';
        Http::fake(['caldav.example.com/*' => Http::response($multistatus, 207)]);

        ($this->sync)(($this->calendar)(['kind' => 'caldav', 'url_enc' => 'https://caldav.example.com/dav/cal/', 'username_enc' => 'marie', 'password_enc' => 'secret-caldav']));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'REPORT'
            && $request->hasHeader('Depth', '1')
            && str_starts_with($request->header('Authorization')[0], 'Basic ')
            && str_contains($request->body(), 'time-range') && str_contains($request->body(), '20260531T'));
        expect(($this->starts)())->not->toContain('10:00')->not->toContain('15:00')->toContain('11:00');
    });

    it('refuse une réponse XML avec DOCTYPE (entités externes)', function (): void {
        Http::fake(['caldav.example.com/*' => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:">&a;</d:multistatus>', 207)]);
        $calendar = ($this->calendar)(['kind' => 'caldav', 'url_enc' => 'https://caldav.example.com/dav/']);

        expect(($this->sync)($calendar))->toBeFalse();
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)->last_error))->toBe('invalid_ics');
    });
});

describe('commande planifiée', function (): void {
    it('synchronise les calendriers dus, chacun sous son organisation, en respectant l\'intervalle', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))]);
        ($this->calendar)();
        app(TenantContext::class)->clear();

        $this->artisan('calendars:sync')->expectsOutputToContain('1 calendrier(s) synchronisé(s), 0 en échec.')->assertSuccessful();
        $this->artisan('calendars:sync')->expectsOutputToContain('0 calendrier(s) synchronisé(s)')->assertSuccessful();

        $this->travel(16)->minutes();
        $this->artisan('calendars:sync')->expectsOutputToContain('1 calendrier(s) synchronisé(s)')->assertSuccessful();
    });

    it('ignore les comptes suspendus pour leurs écritures mais pas en pause', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs())]);
        ($this->calendar)();
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'paused'])->save());
        app(TenantContext::class)->clear();

        $this->artisan('calendars:sync')->expectsOutputToContain('0 calendrier(s) synchronisé(s)')->assertSuccessful();
    });
});

describe('isolation', function (): void {
    it('cache les calendriers et plages d\'une autre organisation', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(eventIcs(busyEvent('20260602T080000Z', '20260602T090000Z')))]);
        ($this->sync)(($this->calendar)());
        $other = $this->asOwner(fn () => Organization::factory()->create());

        app(TenantContext::class)->run($other->id, function (): void {
            expect(ExternalCalendar::query()->count())->toBe(0)->and(ExternalBusySlot::query()->count())->toBe(0);
        });
    });
});
