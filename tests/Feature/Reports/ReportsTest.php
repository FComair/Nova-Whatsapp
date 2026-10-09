<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Rapports et analyses. Lundi 15 juin 2026, 10:00 UTC (12:00 à Bruxelles) ; période « ce mois-ci » = juin, précédente = mai.
 *
 * Juin : A terminé (payé), B terminé (non payé), C absent, D annulé par le client, E confirmé passé (en attente de résultat),
 * F confirmé à venir. Mai : G et H terminés.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld('2026-06-15 10:00:00', $org);
    app(TenantContext::class)->clear();

    $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);

    // Crée un rendez-vous par le cœur de réservation, puis fixe son état (statut, prix, paiement).
    $this->make = function (string $local, string $status = 'confirmed', array $attributes = [], string $source = 'dashboard', ?Practitioner $practitioner = null, ?Customer $customer = null): Booking {
        return ($this->in)(function () use ($local, $status, $attributes, $source, $practitioner, $customer): Booking {
            $booking = app(BookingCore::class)->create($this->org, $this->request($local, source: $source, practitioner: $practitioner, customer: $customer));
            Booking::query()->whereKey($booking->id)->update(['status' => $status, ...$attributes]);

            return $booking->refresh();
        });
    };

    $this->june = function (): void {
        ($this->make)('2026-06-02 10:00', 'completed', ['amount_collected_cents' => 2500, 'payment_status' => 'paid_onsite', 'payment_method' => 'card']);
        ($this->make)('2026-06-03 10:00', 'completed', [], 'whatsapp');
        ($this->make)('2026-06-04 10:00', 'no_show');
        ($this->make)('2026-06-05 10:00', 'cancelled_by_customer', [], 'whatsapp');
        ($this->make)('2026-06-10 10:00', 'confirmed');
        ($this->make)('2026-06-22 10:00', 'confirmed');
        ($this->make)('2026-05-05 10:00', 'completed');
        ($this->make)('2026-05-06 10:00', 'completed');
    };

    $this->props = function (string $url, ?User $user = null): array {
        $response = ($this->as)($user)->get($url)->assertOk();

        return Assert::fromTestResponse($response)->toArray()['props'];
    };

    // Un second praticien (Marc), actif, qui réalise le service et travaille le mardi de 9 h à 12 h. Créé avec le rôle propriétaire.
    $this->marc = fn (): Practitioner => $this->asOwner(function (): Practitioner {
        $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#336699', 'status' => Practitioner::STATUS_ACTIVE]);
        DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
        PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00']);

        return $practitioner;
    });

    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
});

describe('rendez-vous', function (): void {
    it('compte les rendez-vous de la période et leurs issues', function (): void {
        ($this->june)();

        $metrics = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics'];

        expect($metrics['total']['value'])->toBe(6)
            ->and($metrics['completed']['value'])->toBe(2)
            ->and($metrics['upcoming']['value'])->toBe(1)
            ->and($metrics['awaiting']['value'])->toBe(1)
            ->and($metrics['cancelled']['value'])->toBe(1)
            ->and($metrics['no_show']['value'])->toBe(1)
            ->and($metrics['due']['value'])->toBe(4);
    });

    it('calcule les taux sur la bonne base', function (): void {
        ($this->june)();

        $metrics = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics'];

        // Annulation : annulés ÷ réservés (6). Absence et réalisation : ÷ échus (terminés 2 + absent 1 + en attente 1 = 4).
        expect($metrics['cancellation_rate']['value'])->toEqualWithDelta(1 / 6, 0.0001)
            ->and($metrics['no_show_rate']['value'])->toEqualWithDelta(0.25, 0.0001)
            ->and($metrics['completion_rate']['value'])->toEqualWithDelta(0.5, 0.0001)
            ->and($metrics['avg_per_day']['value'])->toEqualWithDelta(5 / 30, 0.05);
    });

    it('compare à la période précédente sans pourcentage trompeur sur une petite base', function (): void {
        ($this->june)();

        $total = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['total'];

        // Mai : 2 rendez-vous. Écart absolu +4 ; base trop petite (< 5) : pas de pourcentage.
        expect($total['previous'])->toBe(2)->and($total['delta'])->toBe(4)->and($total['pct'])->toBeNull()->and($total['note'])->toBe('small_baseline');
    });

    it('donne un pourcentage quand la base le permet', function (): void {
        foreach (['05', '06', '07', '08', '12'] as $day) {
            ($this->make)("2026-05-{$day} 10:00", 'completed');
        }

        foreach (['02', '03', '04'] as $day) {
            ($this->make)("2026-06-{$day} 10:00", 'completed');
        }

        $total = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['total'];

        // Mai : 5 rendez-vous (base suffisante). Juin : 3. Écart -2, soit -40 %.
        expect($total['previous'])->toBe(5)->and($total['delta'])->toBe(-2)->and($total['pct'])->toEqual(-40)->and($total['note'])->toBeNull();
    });

    it('signale une base nulle sans diviser par zéro', function (): void {
        ($this->make)('2026-06-02 10:00', 'completed');

        $total = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['total'];

        expect($total['previous'])->toBe(0)->and($total['pct'])->toBeNull()->and($total['note'])->toBe('zero_baseline');
    });

    it('exprime l\'écart d\'un taux en points', function (): void {
        ($this->june)();

        $rate = ($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['no_show_rate'];

        expect($rate['pct'])->toBeNull()->and($rate['note'])->toBe('small_baseline');
    });

    it('range un rendez-vous dans le jour local du site, pas le jour UTC', function (): void {
        // 30 juin 23:30 UTC = 1er juillet 01:30 à Bruxelles : hors ouverture, on le crée directement dans le contexte.
        $booking = ($this->make)('2026-06-29 10:00', 'completed');
        ($this->in)(fn () => Booking::query()->whereKey($booking->id)->update([
            'starts_at' => '2026-06-30 23:30:00+00', 'ends_at' => '2026-07-01 00:00:00+00', 'blocked_from' => '2026-06-30 23:30:00+00', 'blocked_until' => '2026-07-01 00:00:00+00',
        ]));

        $june = ($this->props)('/rapports?category=appointments&preset=custom&from=2026-06-01&to=2026-06-30')['report']['metrics']['total']['value'];
        $july = ($this->props)('/rapports?category=appointments&preset=custom&from=2026-07-01&to=2026-07-31')['report']['metrics']['total']['value'];

        expect($june)->toBe(0)->and($july)->toBe(1);
    });

    it('respecte les filtres de source et de statut', function (): void {
        ($this->june)();

        expect(($this->props)('/rapports?category=appointments&preset=this_month&source=whatsapp')['report']['metrics']['total']['value'])->toBe(2)
            ->and(($this->props)('/rapports?category=appointments&preset=this_month&status[]=completed')['report']['metrics']['total']['value'])->toBe(2)
            ->and(($this->props)('/rapports?category=appointments&preset=this_month&status[]=cancelled&status[]=no_show')['report']['metrics']['total']['value'])->toBe(2);
    });

    it('répartit par jour, heure, source et service', function (): void {
        ($this->june)();

        $report = ($this->props)('/rapports?category=appointments&preset=this_month')['report'];

        // Non annulés de juin : 5, tous à 10:00 locales (mardi 2, mercredi 3, jeudi 4, mercredi 10, lundi 22).
        expect($report['heatmap']['total'])->toBe(5)
            ->and($report['heatmap']['cells'][2][10])->toBe(1)
            ->and($report['heatmap']['cells'][3][10])->toBe(2)
            ->and(collect($report['sources'])->pluck('count', 'key')->all())->toBe(['dashboard' => 4, 'whatsapp' => 2])
            ->and($report['services'][0]['count'])->toBe(6)
            ->and($report['series']['granularity'])->toBe('day')
            ->and(array_sum(array_column($report['series']['buckets'], 'total')))->toBe(6);
    });

    it('compte les rendez-vous déplacés (date ou heure), pas les simples changements de praticien', function (): void {
        $booking = ($this->make)('2026-06-02 10:00', 'confirmed');
        ($this->in)(fn () => app(BookingCore::class)->move($this->org, $booking, $this->brussels('2026-06-03 11:00'), Actor::user(null), null, false));
        $other = ($this->make)('2026-06-04 10:00', 'confirmed');
        ($this->in)(fn () => app(BookingCore::class)->move($this->org, $other, $other->starts_at, Actor::user(null), $this->practitioner->id, false));

        expect(($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['rescheduled']['value'])->toBe(1);
    });
});

describe('finances', function (): void {
    it('distingue valeur des rendez-vous et argent encaissé', function (): void {
        ($this->june)();

        $report = ($this->props)('/rapports?category=financial&preset=this_month')['report'];
        $metrics = $report['metrics'];

        // Prix 25 € par rendez-vous. Non annulés : A, B, C, E, F = 5 × 2 500.
        expect($report['currency'])->toBe('EUR')
            ->and($metrics['booked_value']['value'])->toBe(12500)
            ->and($metrics['completed_value']['value'])->toBe(5000)
            ->and($metrics['collected']['value'])->toBe(2500)
            ->and($metrics['outstanding']['value'])->toBe(2500)
            ->and($metrics['no_show_value']['value'])->toBe(2500)
            ->and($metrics['cancelled_value']['value'])->toBe(2500)
            ->and($metrics['avg_value']['value'])->toBe(2500)
            ->and($report['unavailable'])->toContain('refunds', 'discounts');
    });

    it('sépare offert et abandonné de l\'argent à encaisser', function (): void {
        ($this->make)('2026-06-02 10:00', 'completed', ['payment_status' => 'waived']);
        ($this->make)('2026-06-03 10:00', 'completed', ['payment_status' => 'partially_paid', 'amount_collected_cents' => 1000]);

        $metrics = ($this->props)('/rapports?category=financial&preset=this_month')['report']['metrics'];

        expect($metrics['waived']['value'])->toBe(2500)->and($metrics['outstanding']['value'])->toBe(1500)->and($metrics['collected']['value'])->toBe(1000);
    });

    it('détaille par service, praticien, moyen de paiement et statut', function (): void {
        ($this->june)();

        $report = ($this->props)('/rapports?category=financial&preset=this_month')['report'];

        expect($report['services'][0]['value'])->toBe(12500)
            ->and($report['practitioners'][0]['collected'])->toBe(2500)
            ->and($report['locations'])->toBeNull()
            ->and($report['methods'])->toBe([['key' => 'card', 'collected' => 2500]])
            ->and(collect($report['payment_statuses'])->pluck('count', 'key')->all())->toBe(['paid_onsite' => 1, 'unpaid' => 1]);
    });
});

describe('clients', function (): void {
    it('compte nouveaux, actifs et fidèles sans exposer aucune donnée personnelle', function (): void {
        $second = ($this->in)(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470999888', 'name' => 'Deuxième Client']));
        ($this->make)('2026-05-05 10:00', 'completed');
        ($this->make)('2026-06-02 10:00', 'completed');
        ($this->make)('2026-06-03 10:00', 'completed', [], 'whatsapp', null, $second);
        ($this->make)('2026-06-22 10:00', 'confirmed', [], 'dashboard', null, $second);

        $response = ($this->as)()->get('/rapports?category=customers&preset=this_month')->assertOk();
        $report = Assert::fromTestResponse($response)->toArray()['props']['report'];

        expect($report['metrics']['active']['value'])->toBe(2)
            ->and($report['metrics']['returning']['value'])->toBe(1)
            ->and($report['metrics']['with_upcoming']['value'])->toBe(1)
            ->and($report['metrics']['avg_per_customer']['value'])->toEqualWithDelta(1.5, 0.001)
            ->and($report['metrics']['returning_rate']['value'])->toEqualWithDelta(0.5, 0.001)
            ->and($report['metrics']['retention']['value'])->toEqualWithDelta(1.0, 0.001);

        $json = json_encode($report);
        expect($json)->not->toContain('+32470')->and($json)->not->toContain('Client Test')->and($json)->not->toContain('Deuxième');
    });

    it('repère les clients sans rendez-vous récent', function (): void {
        ($this->make)('2026-01-06 10:00', 'completed');

        expect(($this->props)('/rapports?category=customers&preset=this_month')['report']['metrics']['inactive']['value'])->toBe(1);
    });
});

describe('praticiens', function (): void {
    it('chiffre temps prévu, temps réservé et taux d\'occupation, sans parler d\'heures travaillées', function (): void {
        ($this->june)();

        $report = ($this->props)('/rapports?category=practitioners&preset=this_month')['report'];
        $row = $report['rows'][0];

        // Juin 2026 : 22 jours ouvrés × 8 h = 10 560 min prévues. Réservé : 5 rendez-vous non annulés × 30 min = 150.
        expect($row['scheduled_minutes'])->toBe(10560)
            ->and($row['booked_minutes'])->toBe(150)
            ->and($row['available_minutes'])->toBe(10410)
            ->and($row['utilization'])->toEqualWithDelta(150 / 10560, 0.0001)
            ->and($row['working_days'])->toBe(22)
            ->and($row['non_working_days'])->toBe(8)
            ->and($row['days_off'])->toBe(0)
            ->and($row['assigned'])->toBe(6)
            ->and($row['completed'])->toBe(2)
            ->and(json_encode($report))->not->toContain('worked_hours');
    });

    it('retire une journée de congé du temps prévu et la compte comme jour de congé', function (): void {
        ($this->in)(fn () => DB::table('practitioner_time_off')->insert([
            'id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'kind' => 'unavailable',
            'starts_at' => $this->brussels('2026-06-16 00:00'), 'ends_at' => $this->brussels('2026-06-17 00:00'), 'reason' => 'Raison privée',
        ]));

        $report = ($this->props)('/rapports?category=practitioners&preset=this_month')['report'];

        expect($report['rows'][0]['scheduled_minutes'])->toBe(10560 - 480)
            ->and($report['rows'][0]['days_off'])->toBe(1)
            ->and($report['time_off'][0]['days'])->toBe(1)
            ->and(json_encode($report))->not->toContain('Raison privée');
    });

    it('ne mélange pas deux praticiens', function (): void {
        $second = ($this->marc)();
        ($this->make)('2026-06-02 10:00', 'completed', [], 'dashboard', $second);
        ($this->make)('2026-06-03 10:00', 'completed');

        $rows = collect(($this->props)('/rapports?category=practitioners&preset=this_month')['report']['rows'])->keyBy('name');

        expect($rows['Marc']['assigned'])->toBe(1)->and($rows['Marc']['scheduled_minutes'])->toBe(180 * 5)->and($rows['Léa']['assigned'])->toBe(1);
    });
});

describe('droits', function (): void {
    it('refuse les rapports au réceptionniste', function (): void {
        ($this->as)(($this->member)('receptionist'))->get('/rapports')->assertForbidden();
    });

    it('cache les montants au responsable de site tant que le propriétaire ne l\'a pas autorisé', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');

        // Une catégorie refusée renvoie à la vue d'ensemble : aucun chiffre financier n'est calculé.
        ($this->as)($manager)->get('/rapports?category=financial')->assertRedirect();
        $props = ($this->props)('/rapports', $manager);

        expect($props['category'])->toBe('overview')->and($props['access']['financial'])->toBeFalse()->and($props['access']['categories'])->not->toContain('financial');

        $json = json_encode(($this->props)('/rapports?category=appointments', $manager));
        expect($json)->not->toContain('value_cents":2500');
        expect(($this->props)('/rapports?category=appointments', $manager)['report']['services'][0]['value_cents'])->toBeNull();

        ($this->as)()->put('/rapports/reglages', ['managers_financial' => true])->assertRedirect();

        $allowed = ($this->props)('/rapports?category=financial', $manager);
        expect($allowed['category'])->toBe('financial')->and($allowed['report']['metrics']['booked_value']['value'])->toBe(12500);
    });

    it('réserve le réglage des montants au propriétaire', function (): void {
        ($this->as)(($this->member)('site_manager'))->put('/rapports/reglages', ['managers_financial' => true])->assertForbidden();
    });

    it('limite un praticien à ses propres rendez-vous, sans montants ni comparaison avec ses collègues', function (): void {
        $marc = ($this->marc)();
        ($this->june)();
        ($this->make)('2026-06-09 10:00', 'completed', [], 'dashboard', $marc);
        $user = ($this->member)('practitioner', $marc);

        $appointments = ($this->props)('/rapports?category=appointments&preset=this_month', $user);
        $practitioners = ($this->props)('/rapports?category=practitioners&preset=this_month', $user);
        ($this->as)($user)->get('/rapports?category=financial&preset=this_month')->assertRedirect();
        $overview = ($this->props)('/rapports?preset=this_month', $user);

        expect($appointments['report']['metrics']['total']['value'])->toBe(1)
            ->and($appointments['access']['financial'])->toBeFalse()
            ->and($appointments['access']['own_only'])->toBeTrue()
            ->and($practitioners['report']['rows'])->toHaveCount(1)
            ->and($practitioners['report']['rows'][0]['name'])->toBe('Marc')
            ->and($practitioners['report']['rows'][0]['value_cents'])->toBeNull()
            ->and($overview['category'])->toBe('overview')
            ->and($overview['report']['financial'])->toBeNull();

        ($this->as)($user)->get('/rapports/export?format=csv&reports[]=appointments')->assertForbidden();
    });

    it('n\'inclut jamais les rendez-vous d\'un autre établissement', function (): void {
        ($this->june)();
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $this->asOwner(function () use ($other): void {
            $location = DB::table('locations')->where('org_id', $other->id)->value('id');
            $service = DB::table('services')->insertGetId(['org_id' => $other->id, 'name' => 'Autre', 'duration_minutes' => 30, 'price_cents' => 9900], 'id');
            $practitioner = DB::table('practitioners')->insertGetId(['org_id' => $other->id, 'name' => 'Autre praticien', 'color' => '#112233', 'status' => 'active'], 'id');
            $customer = DB::table('customers')->insertGetId(['org_id' => $other->id, 'phone_e164' => '+32470555444', 'name' => 'Autre client'], 'id');
            DB::table('bookings')->insert([
                'org_id' => $other->id, 'location_id' => $location, 'customer_id' => $customer, 'practitioner_id' => $practitioner, 'service_id' => $service,
                'starts_at' => '2026-06-02 08:00:00+00', 'ends_at' => '2026-06-02 08:30:00+00', 'blocked_from' => '2026-06-02 08:00:00+00', 'blocked_until' => '2026-06-02 08:30:00+00',
                'status' => 'completed', 'source' => 'dashboard', 'price_cents' => 9900, 'vat_rate' => 21, 'currency' => 'EUR',
            ]);
        });

        $report = ($this->props)('/rapports?category=financial&preset=this_month')['report'];

        // Le rendez-vous de l'autre établissement (99 €) n'apparaît ni dans le nombre ni dans les montants.
        expect(($this->props)('/rapports?category=appointments&preset=this_month')['report']['metrics']['total']['value'])->toBe(6)
            ->and($report['metrics']['booked_value']['value'])->toBe(12500);
    });

    it('limite un responsable de site à ses sites', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');
        $site = $this->asOwner(fn () => DB::table('locations')->insertGetId(['org_id' => $this->org->id, 'name' => 'Autre site', 'timezone' => 'Europe/Brussels', 'is_default' => false], 'id'));
        $member = $this->asOwner(fn () => OrgMember::query()->where('user_id', $manager->id)->value('id'));
        $this->asOwner(fn () => DB::table('org_member_locations')->insert(['member_id' => $member, 'location_id' => $site, 'org_id' => $this->org->id]));

        // Il ne voit que « Autre site », où il n'y a aucun rendez-vous.
        $props = ($this->props)('/rapports?category=appointments&preset=this_month', $manager);

        expect($props['report']['metrics']['total']['value'])->toBe(0)->and(collect($props['options']['locations'])->pluck('name')->all())->toBe(['Autre site']);
    });
});

describe('export et impression', function (): void {
    it('exporte un CSV nommé d\'après le rapport et la période, sans donnée personnelle', function (): void {
        ($this->june)();

        $response = ($this->as)()->get('/rapports/export?format=csv&preset=this_month&reports[]=appointments&content[]=summary&content[]=detail')->assertOk();
        $body = (string) $response->getContent();

        expect($response->headers->get('Content-Disposition'))->toContain('Appointments_Report_2026-06-01_to_2026-06-30.csv')
            ->and($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($body)->toContain('Date')
            ->and($body)->not->toContain('+32470123456')->and($body)->not->toContain('Client Test');
    });

    it('n\'écrit pas les montants dans l\'export d\'un responsable qui n\'y a pas droit', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');

        $response = ($this->as)($manager)->get('/rapports/export?format=csv&preset=this_month&reports[]=appointments&reports[]=financial&content[]=detail&content[]=summary')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('Appointments_Report_')
            ->and((string) $response->getContent())->not->toContain('EUR')->and((string) $response->getContent())->not->toContain('25.00');
    });

    it('exporte un classeur Excel avec une feuille par tableau', function (): void {
        ($this->june)();

        $response = ($this->as)()->get('/rapports/export?format=xlsx&preset=this_month&reports[]=appointments&reports[]=customers&content[]=summary&content[]=breakdown');
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;

        expect($zip->open($file))->toBeTrue();
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        expect($workbook)->toContain('<sheet ')->and(substr_count($workbook, '<sheet '))->toBeGreaterThan(3)->and($sheet)->toContain('<sheetData>');
        expect($response->headers->get('Content-Disposition'))->toContain('Reports_2026-06-01_to_2026-06-30.xlsx');
    });

    it('désamorce les formules dans les cellules CSV', function (): void {
        ($this->in)(fn () => DB::table('services')->where('id', $this->service->id)->update(['name' => '=HYPERLINK("http://x")']));
        ($this->june)();

        $response = ($this->as)()->get('/rapports/export?format=csv&preset=this_month&reports[]=appointments&content[]=breakdown')->assertOk();

        expect((string) $response->getContent())->toContain("'=HYPERLINK")->and((string) $response->getContent())->not->toContain(';=HYPERLINK')->and((string) $response->getContent())->not->toContain("\n=HYPERLINK");
    });

    it('annonce le contenu de l\'export avant de le produire', function (): void {
        ($this->june)();

        $json = ($this->as)()->getJson('/rapports/export/apercu?format=csv&preset=this_month&reports[]=appointments&content[]=summary&content[]=detail')->assertOk()->json();

        expect($json['filename'])->toBe('Appointments_Report_2026-06-01_to_2026-06-30.csv')
            ->and(collect($json['tables'])->last()['rows'])->toBe(6);
    });

    it('sert la version imprimable avec les mêmes droits', function (): void {
        ($this->june)();

        $props = ($this->props)('/rapports/imprimer?preset=this_month&reports[]=appointments&reports[]=financial&auto=1');

        expect($props['categories'])->toBe(['appointments', 'financial'])->and($props['auto'])->toBeTrue()->and($props['business']['name'])->not->toBeNull();

        $manager = ($this->props)('/rapports/imprimer?preset=this_month&reports[]=appointments&reports[]=financial', ($this->member)('site_manager'));
        expect($manager['categories'])->toBe(['appointments'])->and($manager['reports'])->not->toHaveKey('financial');
    });
});

describe('périodes', function (): void {
    it('calcule les périodes prédéfinies et leur période précédente', function (): void {
        $props = ($this->props)('/rapports?preset=last_month');

        expect($props['period']['from'])->toBe('2026-05-01')->and($props['period']['to'])->toBe('2026-05-31')
            ->and($props['period']['previous'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-30'])
            ->and($props['period']['in_progress'])->toBeFalse();

        $week = ($this->props)('/rapports?preset=this_week')['period'];
        expect($week['from'])->toBe('2026-06-15')->and($week['previous'])->toBe(['from' => '2026-06-08', 'to' => '2026-06-14'])->and($week['in_progress'])->toBeTrue();

        $quarter = ($this->props)('/rapports?preset=this_quarter')['period'];
        expect($quarter['from'])->toBe('2026-04-01')->and($quarter['to'])->toBe('2026-06-30')->and($quarter['previous'])->toBe(['from' => '2026-01-01', 'to' => '2026-03-31']);

        $today = ($this->props)('/rapports?preset=today')['period'];
        expect($today['from'])->toBe('2026-06-15')->and($today['previous'])->toBe(['from' => '2026-06-14', 'to' => '2026-06-14']);
    });

    it('borne et corrige une période personnalisée', function (): void {
        $swapped = ($this->props)('/rapports?preset=custom&from=2026-06-20&to=2026-06-10')['period'];
        expect($swapped['from'])->toBe('2026-06-10')->and($swapped['to'])->toBe('2026-06-20')->and($swapped['previous'])->toBe(['from' => '2026-05-30', 'to' => '2026-06-09']);

        $huge = ($this->props)('/rapports?preset=custom&from=2020-01-01&to=2026-06-20')['period'];
        expect($huge['days'])->toBe(366);

        $garbage = ($this->props)('/rapports?preset=nope&from=xx&to=yy')['period'];
        expect($garbage['from'])->toBe('2026-06-01');
    });

    it('choisit des intervalles lisibles selon la durée', function (): void {
        ($this->june)();

        expect(($this->props)('/rapports?category=appointments&preset=this_month')['report']['series']['granularity'])->toBe('day')
            ->and(($this->props)('/rapports?category=appointments&preset=this_quarter')['report']['series']['granularity'])->toBe('week')
            ->and(($this->props)('/rapports?category=appointments&preset=custom&from=2025-06-01&to=2026-06-30')['report']['series']['granularity'])->toBe('month');
    });
});
