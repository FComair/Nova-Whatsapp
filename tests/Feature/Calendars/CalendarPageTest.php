<?php

declare(strict_types=1);

use App\Modules\Calendars\Contracts\HostResolver;
use App\Modules\Calendars\Models\ExternalBusySlot;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Services\CalendarFeedService;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;
use Tests\Support\FakeHostResolver;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Écran des calendriers externes : droits par rôle, ajout contrôlé, aucune adresse ni identifiant renvoyé au navigateur.
 */
const PAGE_ICS = 'https://calendar.example.com/private/secret-token-42/basic.ics';

function pageIcs(): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e1\r\nDTSTART:20260602T080000Z\r\nDTEND:20260602T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();
    app()->instance(HostResolver::class, new FakeHostResolver(['calendar.example.com' => ['93.184.216.34'], 'caldav.example.com' => ['93.184.216.35'], 'evil.example.com' => ['10.0.0.5']]));
    RateLimiter::clear('calendar-sync');

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
    $this->add = fn (array $overrides = []) => ($this->as)()->post('/calendriers', ['practitioner_id' => $this->practitioner->id, 'name' => 'Google', 'kind' => 'ics', 'url' => PAGE_ICS, ...$overrides]);
    $this->existing = fn (?Practitioner $practitioner = null): ExternalCalendar => $this->asOwner(fn () => ExternalCalendar::query()->create([
        'org_id' => $this->org->id, 'practitioner_id' => ($practitioner ?? $this->practitioner)->id, 'name' => 'Existant', 'kind' => 'ics', 'url_enc' => PAGE_ICS,
    ]));
});

describe('affichage', function (): void {
    it('liste les praticiens, leurs calendriers et l\'adresse du flux, sans jamais renvoyer l\'adresse ICS', function (): void {
        ($this->existing)();

        $response = ($this->as)()->get('/calendriers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Calendars/Index')
            ->has('practitioners', 1)
            ->where('practitioners.0.name', 'Léa')
            ->where('practitioners.0.calendars.0.name', 'Existant')
            ->where('practitioners.0.calendars.0.status', 'active')
            ->where('practitioners.0.feed_url', fn (string $url): bool => str_starts_with($url, url('/ics/nf_')) && str_ends_with($url, '.ics'))
            ->where('limit', 5));

        expect($response->getContent())->not->toContain('secret-token-42');
    });

    it('limite le praticien à ses propres calendriers', function (): void {
        $marc = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

            return $practitioner;
        });
        $lea = ($this->member)('practitioner', $this->practitioner);
        $marcCalendar = ($this->existing)($marc);

        ($this->as)($lea)->get('/calendriers')->assertInertia(fn (Assert $page) => $page->has('practitioners', 1)->where('practitioners.0.id', $this->practitioner->id));
        ($this->as)($lea)->post("/calendriers/{$marcCalendar->id}/synchroniser")->assertForbidden();
        ($this->as)($lea)->delete("/calendriers/{$marcCalendar->id}")->assertForbidden();
        ($this->as)($lea)->post('/calendriers', ['practitioner_id' => $marc->id, 'name' => 'X', 'kind' => 'ics', 'url' => PAGE_ICS])->assertForbidden();
        ($this->as)($lea)->post("/calendriers/flux/{$marc->id}/renouveler")->assertForbidden();
    });

    it('refuse l\'accès au réceptionniste', function (): void {
        $receptionist = ($this->member)('receptionist');

        ($this->as)($receptionist)->get('/calendriers')->assertForbidden();
    });

    it('limite le responsable de site aux praticiens de ses sites', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Autre site']));
        $manager = ($this->member)('site_manager');
        $this->asOwner(function () use ($manager, $second): void {
            DB::table('org_member_locations')->insert(['org_id' => $this->org->id, 'member_id' => OrgMember::query()->where('user_id', $manager->id)->value('id'), 'location_id' => $second->id]);
        });

        ($this->as)($manager)->get('/calendriers')->assertInertia(fn (Assert $page) => $page->has('practitioners', 0));
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/calendriers')->assertRedirect('/connexion');
    });
});

describe('ajout', function (): void {
    it('connecte un calendrier lisible, le synchronise et le trace sans son adresse', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(pageIcs())]);

        ($this->add)()->assertSessionHasNoErrors()->assertSessionHas('status', 'Calendrier connecté.');

        $calendar = $this->asOwner(fn () => ExternalCalendar::query()->sole());
        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'calendar.connected')->sole());

        expect($calendar)->name->toBe('Google')->status->toBe('active')->last_synced_at->not->toBeNull()
            ->and($this->asOwner(fn () => ExternalBusySlot::query()->count()))->toBe(1)
            ->and(json_encode($audit))->not->toContain('secret-token-42');
    });

    it('n\'enregistre pas un calendrier qui ne se lit pas, et explique pourquoi', function (int $status, string $message): void {
        Http::fake(['calendar.example.com/*' => Http::response('', $status)]);

        ($this->add)()->assertSessionHasErrors(['url' => $message]);

        expect($this->asOwner(fn () => ExternalCalendar::query()->count()))->toBe(0);
    })->with([
        [401, 'Accès refusé : vérifiez l\'identifiant et le mot de passe.'],
        [404, 'Calendrier introuvable : l\'adresse est peut-être erronée ou expirée.'],
        [500, 'Le service du calendrier est en panne. Réessayez plus tard.'],
    ]);

    it('refuse les adresses dangereuses avant tout appel réseau', function (string $url, string $message): void {
        Http::fake();

        ($this->add)(['url' => $url])->assertSessionHasErrors('url');

        Http::assertNothingSent();
        expect($this->asOwner(fn () => ExternalCalendar::query()->count()))->toBe(0);
    })->with([
        'réseau privé' => ['https://evil.example.com/a.ics', 'blocked'],
        'http simple' => ['http://calendar.example.com/a.ics', 'regex'],
        'port inhabituel' => ['https://calendar.example.com:8080/a.ics', 'invalid'],
        'identifiants dans l\'adresse' => ['https://u:p@calendar.example.com/a.ics', 'invalid'],
    ]);

    it('affiche l\'erreur dans la langue du compte', function (string $locale, string $message): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->add)(['url' => 'https://evil.example.com/a.ics'])->assertSessionHasErrors(['url' => $message]);
    })->with([
        ['fr', 'Cette adresse n\'est pas accessible publiquement et ne peut pas être utilisée.'],
        ['nl', 'Dit adres is niet openbaar bereikbaar en kan niet worden gebruikt.'],
        ['en', 'This address is not publicly reachable and cannot be used.'],
    ]);

    it('valide le formulaire', function (array $override, string $field): void {
        ($this->add)($override)->assertSessionHasErrors($field);
    })->with([
        'nom manquant' => [['name' => ''], 'name'],
        'type inconnu' => [['kind' => 'exchange'], 'kind'],
        'CalDAV sans identifiants' => [['kind' => 'caldav', 'url' => 'https://caldav.example.com/dav/'], 'username'],
        'praticien inconnu' => [['practitioner_id' => '00000000-0000-4000-8000-000000000000'], 'practitioner_id'],
    ]);

    it('connecte un calendrier CalDAV avec identifiants chiffrés', function (): void {
        Http::fake(['caldav.example.com/*' => Http::response('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:propstat><d:prop><c:calendar-data>'.htmlspecialchars(pageIcs()).'</c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>', 207)]);

        ($this->add)(['kind' => 'caldav', 'url' => 'https://caldav.example.com/dav/', 'username' => 'marie', 'password' => 'mot-de-passe-caldav'])->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => json_encode(DB::table('external_calendars')->first())))->not->toContain('mot-de-passe-caldav')->not->toContain('marie');
        Http::assertSent(fn ($request): bool => $request->method() === 'REPORT');
    });

    it('accepte au plus 5 calendriers par praticien', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(pageIcs())]);
        foreach (range(1, 5) as $i) {
            ($this->existing)();
        }

        ($this->add)()->assertSessionHasErrors(['url' => 'Vous pouvez connecter au plus 5 calendriers par praticien.']);
    });

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->add)()->assertRedirect(route('account.state'));
    });
});

describe('gestion', function (): void {
    it('synchronise à la demande, au plus une fois par minute', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(pageIcs())]);
        $calendar = ($this->existing)();

        ($this->as)()->post("/calendriers/{$calendar->id}/synchroniser")->assertSessionHas('status', 'Calendrier synchronisé.');
        ($this->as)()->post("/calendriers/{$calendar->id}/synchroniser")->assertSessionHasErrors('calendar');

        expect($this->asOwner(fn () => ExternalBusySlot::query()->count()))->toBe(1);
    });

    it('met en pause et reprend', function (): void {
        $calendar = ($this->existing)();

        ($this->as)()->put("/calendriers/{$calendar->id}/pause")->assertSessionHas('status', 'Synchronisation en pause.');
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)->status))->toBe('paused');

        ($this->as)()->put("/calendriers/{$calendar->id}/pause")->assertSessionHas('status', 'Synchronisation reprise.');
        expect($this->asOwner(fn () => ExternalCalendar::query()->find($calendar->id)->status))->toBe('active');
    });

    it('déconnecte : le calendrier et ses plages disparaissent, l\'action est tracée', function (): void {
        Http::fake(['calendar.example.com/*' => Http::response(pageIcs())]);
        $calendar = ($this->existing)();
        ($this->as)()->post("/calendriers/{$calendar->id}/synchroniser");

        ($this->as)()->delete("/calendriers/{$calendar->id}")->assertSessionHas('status', 'Calendrier déconnecté.');

        expect($this->asOwner(fn () => ExternalCalendar::query()->count()))->toBe(0)->and($this->asOwner(fn () => ExternalBusySlot::query()->count()))->toBe(0)
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'calendar.disconnected')->count()))->toBe(1);
    });

    it('renouvelle l\'adresse du flux et l\'ancienne cesse de fonctionner', function (): void {
        $old = app(TenantContext::class)->run($this->org->id, fn () => app(CalendarFeedService::class)->tokenFor($this->org, $this->practitioner));

        ($this->as)()->post("/calendriers/flux/{$this->practitioner->id}/renouveler")->assertSessionHas('status', 'Nouvelle adresse générée.');

        $this->get("/ics/{$old}.ics")->assertNotFound();
    });

    it('répond 404 pour le calendrier d\'une autre organisation', function (): void {
        $calendar = ($this->existing)();
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->delete("/calendriers/{$calendar->id}")->assertNotFound();
        $this->signedIn($stranger, $otherOrg)->get('/calendriers')->assertInertia(fn (Assert $page) => $page->has('practitioners', 0));
    });
});
