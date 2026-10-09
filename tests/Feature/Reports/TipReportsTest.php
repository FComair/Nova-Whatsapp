<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingTips;
use App\Modules\Booking\Support\Actor;
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
 * Pourboires dans les rapports financiers et les exports : toujours séparés du montant du service, jamais fondus dans les
 * chiffres existants. Lundi 15 juin 2026 ; période « ce mois-ci » = juin, précédente = mai.
 *
 * Juin (25 € par service) : A payé, pourboire 5 € à Léa · B payé, pourboire 10 € réparti Léa 6 € / Marc 4 € · C terminé non payé,
 * sans pourboire · D chez Marc, payé, pourboire 2 € à Marc. Mai : E payé, pourboire 3 € à Léa.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld('2026-06-15 10:00:00', $org);
    app(TenantContext::class)->clear();
    // Exports et libellés en anglais : les assertions lisent les en-têtes des fichiers.
    $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'en'])->save());

    $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);

    $this->marc = $this->asOwner(function (): Practitioner {
        $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#336699', 'status' => Practitioner::STATUS_ACTIVE]);
        DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
        PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return $practitioner;
    });

    // Un rendez-vous terminé et payé, avec ou sans pourboire (réparti si des parts sont données).
    $this->visit = function (string $local, string $payment = 'paid_onsite', ?int $tip = null, array $allocations = [], ?Practitioner $practitioner = null): Booking {
        return ($this->in)(function () use ($local, $payment, $tip, $allocations, $practitioner): Booking {
            $booking = app(BookingCore::class)->create($this->org, $this->request($local, practitioner: $practitioner));
            Booking::query()->whereKey($booking->id)->update(['status' => 'completed']);
            $booking->refresh();
            app(BookingCore::class)->recordPayment($booking, $payment, null, 'card', Actor::user($this->owner->id));

            if ($tip !== null) {
                app(BookingTips::class)->record($booking->refresh(), $tip, $allocations, Actor::user($this->owner->id));
            }

            return $booking->refresh();
        });
    };

    $this->june = function (): void {
        ($this->visit)('2026-06-02 10:00', 'paid_onsite', 500);
        ($this->visit)('2026-06-03 10:00', 'paid_onsite', 1000, [['practitioner_id' => $this->practitioner->id, 'amount_cents' => 600], ['practitioner_id' => $this->marc->id, 'amount_cents' => 400]]);
        ($this->visit)('2026-06-04 10:00', 'unpaid');
        ($this->visit)('2026-06-09 10:00', 'paid_onsite', 200, [], $this->marc);
        ($this->visit)('2026-05-05 10:00', 'paid_onsite', 300);
    };

    $this->props = function (string $url, ?User $user = null): array {
        $response = ($this->as)($user)->get($url)->assertOk();

        return Assert::fromTestResponse($response)->toArray()['props'];
    };
    $this->financial = fn (string $query = ''): array => ($this->props)('/rapports?category=financial&preset=this_month'.$query)['report'];

    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com", 'locale' => 'en']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });

    // Contenu texte d'un export CSV, lignes séparées.
    $this->csv = fn (string $query): string => (string) ($this->as)()->get('/rapports/export?format=csv&preset=this_month'.$query)->assertOk()->getContent();
});

describe('rapport financier', function (): void {
    it('montre les pourboires à part : total, nombre, moyenne, total payé', function (): void {
        ($this->june)();

        $metrics = ($this->financial)()['metrics'];

        // 5 + 10 + 2 = 17 €, 3 pourboires, moyenne 5,67 € ; le service encaissé : 3 rendez-vous payés × 25 € = 75 €.
        expect($metrics['tips']['value'])->toBe(1700)
            ->and($metrics['tips_count']['value'])->toBe(3)
            ->and($metrics['tips_avg']['value'])->toBe(567)
            ->and($metrics['collected']['value'])->toBe(7500)
            ->and($metrics['total_paid']['value'])->toBe(9200);
    });

    it('ne change aucun chiffre existant : l\'encaissé et la valeur du service restent sans pourboire', function (): void {
        ($this->june)();
        $with = ($this->financial)()['metrics'];

        $this->asOwner(fn () => DB::table('booking_tips')->delete());
        $without = ($this->financial)()['metrics'];

        foreach (['booked_value', 'completed_value', 'collected', 'outstanding', 'waived', 'no_show_value', 'cancelled_value', 'avg_value', 'collection_rate'] as $key) {
            expect($with[$key]['value'])->toEqual($without[$key]['value']);
        }

        expect($without['tips']['value'])->toBe(0)->and($without['tips_count']['value'])->toBe(0)->and($without['tips_avg']['value'])->toBe(0)->and($without['total_paid']['value'])->toBe(7500);
    });

    it('compare les pourboires à la période précédente', function (): void {
        ($this->june)();

        $tips = ($this->financial)()['metrics']['tips'];

        // Mai : 3 €. Base trop petite (< 50 €) : écart absolu seulement, sans pourcentage.
        expect($tips['previous'])->toBe(300)->and($tips['delta'])->toBe(1400)->and($tips['pct'])->toBeNull()->and($tips['note'])->toBe('small_baseline');
    });

    it('affiche zéro, sans erreur, quand il n\'y a aucun pourboire', function (): void {
        ($this->visit)('2026-06-02 10:00', 'paid_onsite');

        $report = ($this->financial)();

        expect($report['metrics']['tips']['value'])->toBe(0)->and($report['metrics']['tips_avg']['value'])->toBe(0)->and($report['tips_by_practitioner'])->toBe([])->and($report['tips_split'])->toBe(0);
    });

    it('attribue les pourboires au bon praticien, y compris les parts', function (): void {
        ($this->june)();

        $rows = collect(($this->financial)()['tips_by_practitioner'])->keyBy('name');

        // Léa : 5 + 6 = 11 € (2 parts) ; Marc : 4 + 2 = 6 € (2 parts).
        expect($rows['Léa']['tips'])->toBe(1100)->and($rows['Léa']['count'])->toBe(2)
            ->and($rows['Marc']['tips'])->toBe(600)->and($rows['Marc']['count'])->toBe(2)
            ->and($rows['Léa']['share'])->toEqualWithDelta(1100 / 1700, 0.0001)
            ->and(array_sum(array_column(($this->financial)()['tips_by_practitioner'], 'tips')))->toBe(1700);
    });

    it('compte les pourboires répartis entre plusieurs praticiens', function (): void {
        ($this->june)();

        expect(($this->financial)()['tips_split'])->toBe(1);
    });

    it('ventile les pourboires par service et par jour, séparés des montants du service', function (): void {
        ($this->june)();

        $report = ($this->financial)();

        expect($report['services'][0]['tips'])->toBe(1700)->and($report['services'][0]['collected'])->toBe(7500)->and($report['services'][0]['value'])->toBe(10000)
            ->and($report['locations'])->toBeNull();

        $buckets = collect($report['series']['buckets'])->keyBy('key');
        expect($buckets['2026-06-02']['tips'])->toBe(500)->and($buckets['2026-06-03']['tips'])->toBe(1000)->and($buckets['2026-06-09']['tips'])->toBe(200)
            ->and($buckets['2026-06-03']['collected'])->toBe(2500)
            ->and(array_sum(array_column($report['series']['buckets'], 'tips')))->toBe(1700);
    });

    it('respecte la période, le praticien et le statut de paiement', function (): void {
        ($this->june)();

        // Période personnalisée : seul le 3 juin.
        $day = ($this->props)('/rapports?category=financial&preset=custom&from=2026-06-03&to=2026-06-03')['report'];
        expect($day['metrics']['tips']['value'])->toBe(1000)->and($day['metrics']['tips_count']['value'])->toBe(1);

        // Praticien du rendez-vous = Marc : son rendez-vous D seulement (2 €).
        $marc = ($this->financial)('&practitioner='.$this->marc->id);
        expect($marc['metrics']['tips']['value'])->toBe(200)->and($marc['tips_by_practitioner'][0]['name'])->toBe('Marc');

        // Statut de paiement « non payé » : C, sans pourboire.
        $unpaid = ($this->financial)('&payment=unpaid');
        expect($unpaid['metrics']['tips']['value'])->toBe(0)->and($unpaid['metrics']['collected']['value'])->toBe(0);

        // « payé sur place » : les trois rendez-vous avec pourboire.
        expect(($this->financial)('&payment=paid_onsite')['metrics']['tips']['value'])->toBe(1700);

        // Filtre de service inconnu : rien.
        expect(($this->financial)('&service=00000000-0000-4000-8000-000000000000')['metrics']['tips']['value'])->toBe(0);
    });
});

describe('rapport praticiens', function (): void {
    it('donne les pourboires reçus par praticien, à part de la valeur et de l\'encaissé', function (): void {
        ($this->june)();

        $rows = collect(($this->props)('/rapports?category=practitioners&preset=this_month')['report']['rows'])->keyBy('name');

        expect($rows['Léa']['tips_cents'])->toBe(1100)->and($rows['Léa']['collected_cents'])->toBe(5000)
            ->and($rows['Marc']['tips_cents'])->toBe(600)->and($rows['Marc']['collected_cents'])->toBe(2500);
    });

    it('n\'expose aucun pourboire sans droit aux montants', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');

        $rows = ($this->props)('/rapports?category=practitioners&preset=this_month', $manager)['report']['rows'];

        expect(collect($rows)->pluck('tips_cents')->unique()->all())->toBe([null]);
        expect(json_encode(($this->props)('/rapports?category=appointments&preset=this_month', $manager)['report']))->not->toContain('tips');
    });
});

describe('droits', function (): void {
    it('cache les pourboires au responsable de site sans droit aux montants, puis les montre une fois autorisé', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');

        ($this->as)($manager)->get('/rapports?category=financial&preset=this_month')->assertRedirect();

        ($this->as)()->put('/rapports/reglages', ['managers_financial' => true])->assertRedirect();

        $props = ($this->props)('/rapports?category=financial&preset=this_month', $manager);
        expect($props['report']['metrics']['tips']['value'])->toBe(1700);
    });

    it('n\'ouvre aucun rapport financier ni pourboire à un praticien', function (): void {
        ($this->june)();
        $user = ($this->member)('practitioner', $this->marc);

        ($this->as)($user)->get('/rapports?category=financial&preset=this_month')->assertRedirect();
        ($this->as)($user)->get('/rapports/export?format=csv&reports[]=financial')->assertForbidden();
        expect(json_encode(($this->props)('/rapports?preset=this_month', $user)['report']))->not->toContain('tips');
    });
});

describe('exports', function (): void {
    it('sépare dans l\'export montant du service, pourboire et total payé, avec devise, statut, date et référence', function (): void {
        ($this->june)();

        $body = ($this->csv)('&reports[]=financial&content[]=detail&staff=1');

        // Colonnes distinctes.
        foreach (['Appointment reference', 'Service amount (EUR)', 'Service amount collected (EUR)', 'Tip (EUR)', 'Total paid (service collected + tip) (EUR)', 'Tip recipient', 'Tip allocation', 'Currency', 'Payment', 'Payment date'] as $label) {
            expect($body)->toContain($label);
        }

        // Rendez-vous B : service 25, encaissé 25, pourboire 10, total payé 35, réparti Léa 6,00 / Marc 4,00.
        $line = collect(explode("\n", $body))->first(fn (string $row) => str_contains($row, '03/06/2026') && str_contains($row, 'Léa: 6.00'));

        expect($line)->not->toBeNull()
            ->and($line)->toContain(';25;25;10;35;')
            ->and($line)->toContain('Léa; Marc')
            ->and($line)->toContain('Léa: 6.00; Marc: 4.00')
            ->and($line)->toContain(';EUR;');
    });

    it('exporte le détail des pourboires, une ligne par bénéficiaire', function (): void {
        ($this->june)();

        $body = ($this->csv)('&reports[]=financial&content[]=detail&staff=1');
        $rows = collect(explode("\n", $body))->filter(fn (string $row) => str_contains($row, 'Tip share'));

        expect($rows)->toHaveCount(1);

        $tips = collect(explode("\n", $body))->filter(fn (string $row) => preg_match('/^[0-9a-f-]{36};\d\d\/\d\d\/2026;Coupe;/', $row) === 1);

        // A (Léa 5), B (Léa 6, Marc 4), D (Marc 2) : 4 lignes, dont deux pour le pourboire réparti.
        expect($tips)->toHaveCount(4)
            ->and($tips->filter(fn (string $row) => str_contains($row, ';10;')))->toHaveCount(2)
            ->and($tips->sum(fn (string $row) => (float) explode(';', $row)[4]))->toEqualWithDelta(17.0, 0.001);
    });

    it('n\'écrit pas le bénéficiaire sans le droit au détail par praticien', function (): void {
        ($this->june)();

        $body = ($this->csv)('&reports[]=financial&content[]=detail');

        expect($body)->toContain('Tip (EUR)')->and($body)->not->toContain('Tip recipient')->and($body)->not->toContain('Tip allocation')->and($body)->not->toContain('Tip share');
    });

    it('ajoute les pourboires aux tableaux du rapport financier et des praticiens', function (): void {
        ($this->june)();

        $financial = ($this->csv)('&reports[]=financial&content[]=summary&content[]=breakdown&staff=1');
        $practitioners = ($this->csv)('&reports[]=practitioners&content[]=breakdown&staff=1');

        expect($financial)->toContain('Tips')->and($financial)->toContain('Total paid (service collected + tips)')->and($financial)->toContain('Tips by practitioner')
            ->and($practitioners)->toContain('Tips (EUR)');
    });

    it('ne contient aucun pourboire pour un responsable sans droit aux montants', function (): void {
        ($this->june)();
        $manager = ($this->member)('site_manager');

        $body = (string) ($this->as)($manager)->get('/rapports/export?format=csv&preset=this_month&reports[]=appointments&reports[]=financial&content[]=detail&content[]=summary&staff=1')->assertOk()->getContent();

        expect($body)->not->toContain('Tip')->and($body)->not->toContain('Total paid');
    });

    it('applique les filtres et la période à l\'export', function (): void {
        ($this->june)();

        $body = ($this->csv)('&reports[]=financial&content[]=detail&staff=1&payment=unpaid');
        $day = (string) ($this->as)()->get('/rapports/export?format=csv&preset=custom&from=2026-06-09&to=2026-06-09&reports[]=financial&content[]=detail&staff=1')->assertOk()->getContent();

        // Non payé : le seul rendez-vous C, sans pourboire ; le 9 juin : D seulement.
        expect(collect(explode("\n", $body))->filter(fn (string $row) => preg_match('/^[0-9a-f-]{36};/', $row) === 1))->toHaveCount(1)
            ->and($body)->not->toContain('Marc: 4.00')
            ->and($day)->toContain('09/06/2026')->and($day)->not->toContain('03/06/2026')
            ->and($body)->toContain('Payment status: Unpaid');
    });

    it('exporte un classeur avec les colonnes de pourboire', function (): void {
        ($this->june)();

        $response = ($this->as)()->get('/rapports/export?format=xlsx&preset=this_month&reports[]=financial&content[]=detail&staff=1')->assertOk();
        $zip = new ZipArchive;

        expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
        $sheets = '';

        for ($i = 1; $i <= 6; $i++) {
            $sheets .= (string) $zip->getFromName("xl/worksheets/sheet{$i}.xml");
        }

        $zip->close();

        expect($sheets)->toContain('Tip (EUR)')->and($sheets)->toContain('Total paid (service collected + tip) (EUR)')->and($sheets)->toContain('Tip recipient');
    });

    it('imprime les pourboires avec la même séparation', function (): void {
        ($this->june)();

        $props = ($this->props)('/rapports/imprimer?preset=this_month&reports[]=financial');

        expect($props['reports']['financial']['metrics']['tips']['value'])->toBe(1700)
            ->and($props['reports']['financial']['metrics']['collected']['value'])->toBe(7500)
            ->and($props['reports']['financial']['metrics']['total_paid']['value'])->toBe(9200);
    });
});
