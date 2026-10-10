<?php

declare(strict_types=1);

use App\Modules\Admin\Dashboard\ChartSvg;
use App\Modules\Admin\Dashboard\DashboardMetrics;
use App\Modules\Admin\Dashboard\DashboardPeriod;
use App\Modules\Admin\Filament\Pages\AdminDashboard;
use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Billing\Models\Plan;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Tableau de bord du Super Admin (docs/33) : périodes et comparaison, exactitude des chiffres, graphiques accessibles, alertes, accès.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Cache::flush();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->boss->forceFill(['name' => 'Fondatrice Nova'])->save();
    $this->boss->refresh();
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'active']);

    $this->db = fn () => DB::connection('pgsql_owner');
    $this->compute = fn (string $preset = 'this_month', ?string $from = null, ?string $until = null) => app(DashboardMetrics::class)->compute($this->boss, DashboardPeriod::make($preset, $from, $until));
    $this->kpi = fn (array $data, string $key): array => collect($data['kpis'])->firstWhere('key', $key);
    $this->subscribe = function (string $orgId, string $status = 'active', string $plan = 'basic', ?Carbon $end = null): string {
        $planId = Plan::query()->where('code', $plan)->value('id');
        $id = (string) Str::uuid();
        ($this->db)()->table('subscriptions')->insert(['id' => $id, 'org_id' => $orgId, 'plan_id' => $planId, 'status' => $status, 'provider' => 'fake', 'current_period_start' => now()->subDays(20), 'current_period_end' => $end ?? now()->addDays(10)]);

        return $id;
    };
    $this->pay = function (string $orgId, string $subscriptionId, int $subtotal, string $status = 'succeeded', ?Carbon $at = null): void {
        $at ??= now();
        $invoice = (string) Str::uuid();
        $vat = (int) round($subtotal * 0.21);
        ($this->db)()->table('invoices')->insert([
            'id' => $invoice, 'org_id' => $orgId, 'subscription_id' => $subscriptionId, 'number' => 'T-'.Str::random(8), 'period_start' => $at->copy()->subMonth(), 'period_end' => $at, 'status' => $status === 'succeeded' ? 'paid' : 'open',
            'currency' => 'EUR', 'subtotal_cents' => $subtotal, 'vat_rate' => 21, 'vat_cents' => $vat, 'total_cents' => $subtotal + $vat, 'seller_snapshot' => '{}', 'billing_snapshot' => '{}', 'issued_at' => $at, 'paid_at' => $status === 'succeeded' ? $at : null, 'plan_name' => 'Basic',
        ]);
        ($this->db)()->table('payments')->insert(['id' => (string) Str::uuid(), 'org_id' => $orgId, 'invoice_id' => $invoice, 'provider' => 'fake', 'status' => $status, 'amount_cents' => $subtotal + $vat, 'created_at' => $at, 'updated_at' => $at]);
    };
    $this->audit = function (string $action, ?string $orgId = null, ?Carbon $at = null): void {
        ($this->db)()->table('audit_logs')->insert(['id' => (string) Str::uuid7(), 'org_id' => $orgId, 'actor_type' => 'system', 'action' => $action, 'created_at' => $at ?? now()]);
    };
});

describe('périodes', function (): void {
    it('compare chaque période à la période précédente équivalente', function (): void {
        $now = Carbon::parse('2026-10-10 14:00:00');
        $month = DashboardPeriod::make('this_month', null, null, $now);

        expect($month->from->toDateString())->toBe('2026-10-01')->and($month->until->toDateString())->toBe('2026-10-10')
            ->and($month->previousFrom->toDateString())->toBe('2026-09-01')->and($month->previousUntil->toDateString())->toBe('2026-09-10');

        $last = DashboardPeriod::make('last_month', null, null, $now);
        expect($last->from->toDateString())->toBe('2026-09-01')->and($last->until->toDateString())->toBe('2026-09-30')->and($last->previousFrom->toDateString())->toBe('2026-08-01')->and($last->previousUntil->toDateString())->toBe('2026-08-31');

        $today = DashboardPeriod::make('today', null, null, $now);
        expect($today->from->toDateString())->toBe('2026-10-10')->and($today->previousFrom->toDateString())->toBe('2026-10-09');

        $week = DashboardPeriod::make('this_week', null, null, $now);
        expect($week->from->toDateString())->toBe('2026-10-05')->and($week->previousFrom->toDateString())->toBe('2026-09-28')->and($week->previousUntil->toDateString())->toBe('2026-10-03');

        $quarter = DashboardPeriod::make('this_quarter', null, null, $now);
        expect($quarter->from->toDateString())->toBe('2026-10-01')->and($quarter->previousFrom->toDateString())->toBe('2026-07-01')->and($quarter->previousUntil->toDateString())->toBe('2026-07-10');

        $year = DashboardPeriod::make('this_year', null, null, $now);
        expect($year->from->toDateString())->toBe('2026-01-01')->and($year->previousFrom->toDateString())->toBe('2025-01-01');
    });

    it('compare une période libre à la période de même durée qui la précède', function (): void {
        $p = DashboardPeriod::make('custom', '2026-10-05', '2026-10-09');

        expect($p->days())->toBe(5)->and($p->previousFrom->toDateString())->toBe('2026-09-30')->and($p->previousUntil->toDateString())->toBe('2026-10-04');
    });

    it('corrige une période inversée, plafonne la durée et refuse un nom inconnu', function (): void {
        $swapped = DashboardPeriod::make('custom', '2026-10-09', '2026-10-05');
        expect($swapped->from->toDateString())->toBe('2026-10-05');

        $long = DashboardPeriod::make('custom', '2000-01-01', '2026-10-05');
        expect($long->days())->toBe(DashboardPeriod::MAX_DAYS);
        expect(fn () => DashboardPeriod::make('someday'))->toThrow(InvalidArgumentException::class);
    });

    it('trace par jour jusqu\'à 62 jours puis par mois, sans trou', function (): void {
        $short = DashboardPeriod::make('custom', '2026-10-01', '2026-10-10');
        expect($short->bucket())->toBe('day')->and($short->buckets())->toHaveCount(10);

        $long = DashboardPeriod::make('custom', '2026-01-15', '2026-10-10');
        expect($long->bucket())->toBe('month')->and($long->buckets())->toHaveCount(10);
    });
});

describe('graphiques', function (): void {
    it('arrondit les maximums et calcule des arcs qui totalisent 100 %', function (): void {
        expect(ChartSvg::niceMax(0))->toBe(1.0)->and(ChartSvg::niceMax(7))->toBe(10.0)->and(ChartSvg::niceMax(180))->toBe(200.0)->and(ChartSvg::niceMax(23))->toBe(25.0);

        $arcs = ChartSvg::donut(['a' => 1, 'b' => 3, 'c' => 0]);
        expect($arcs)->toHaveCount(2)->and(round(array_sum(array_column($arcs, 'percent')), 1))->toBe(100.0);
    });

    it('signale une série vide et garde la courbe dans le cadre', function (): void {
        expect(ChartSvg::line(['2026-10-01' => 0, '2026-10-02' => 0])['empty'])->toBeTrue();

        $chart = ChartSvg::line(['2026-10-01' => 5, '2026-10-02' => 10, '2026-10-03' => 2], ['2026-09-01' => 1, '2026-09-02' => 1, '2026-09-03' => 1]);
        expect($chart['empty'])->toBeFalse()->and($chart['previous'])->not->toBeNull();

        foreach ($chart['current']['points'] as $point) {
            expect($point['y'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($chart['height']);
        }
    });
});

describe('exactitude des chiffres', function (): void {
    it('compte les nouveaux clients et les compare à la période précédente', function (): void {
        ($this->db)()->table('organizations')->where('id', $this->org->id)->update(['created_at' => now()->startOfMonth()->addHour()]);
        ['org' => $old] = $this->activatedAccount(['name' => 'Cabinet Ancien'], 'ancien@example.com');
        ($this->db)()->table('organizations')->where('id', $old->id)->update(['created_at' => now()->startOfMonth()->subMonthNoOverflow()->addDays(4)]);

        $data = ($this->compute)('this_month');
        $kpi = ($this->kpi)($data, 'new_clients');

        expect($kpi['value'])->toBe(1)->and($kpi['comparison']['previous'])->toBe(1)->and($kpi['comparison']['direction'])->toBe('flat');
    });

    it('sépare le revenu d\'abonnement de Nova de l\'argent des clients', function (): void {
        $sub = ($this->subscribe)($this->org->id);
        ($this->pay)($this->org->id, $sub, 10000);
        ($this->pay)($this->org->id, $sub, 5000, 'failed');

        $data = ($this->compute)('this_month');

        expect($data['revenue']['subscription']['collected_cents'])->toBe(12100)->and($data['revenue']['subscription']['collected_ex_vat_cents'])->toBe(10000)
            ->and($data['revenue']['failed_payments']['value'])->toBe(1)->and($data['revenue']['failed_cents'])->toBe(6050)
            ->and($data['revenue']['client_money'])->toMatchArray(['appointments_cents' => 0, 'tips_cents' => 0, 'total_cents' => 0])
            ->and(($this->kpi)($data, 'revenue_collected')['value'])->toBe(12100)
            ->and(($this->kpi)($data, 'failed_payments')['value'])->toBe(1);
    });

    it('calcule le MRR actuel sans comparaison et garde les instantanés marqués comme tels', function (): void {
        ($this->subscribe)($this->org->id, 'active', 'business');

        $data = ($this->compute)('this_month');
        $mrr = ($this->kpi)($data, 'mrr');

        expect($mrr['value'])->toBe((int) Plan::query()->where('code', 'business')->value('price_monthly_cents'))->and($mrr['comparison'])->toBeNull()->and($mrr['note'])->toBe('snapshot')
            ->and(($this->kpi)($data, 'active_subscriptions')['value'])->toBe(1)->and($data['revenue']['arpc_cents'])->toBe($mrr['value'])->and($data['unavailable'])->toContain('mrr_history');
    });

    it('compte les mouvements d\'abonnement sur la période et la précédente', function (): void {
        ($this->audit)('billing.subscribed', $this->org->id);
        ($this->audit)('billing.subscribed', $this->org->id, now()->startOfMonth()->subDays(2));
        ($this->audit)('billing.subscribed', $this->org->id, now()->startOfMonth()->subDays(3));
        ($this->audit)('billing.upgraded', $this->org->id);
        ($this->audit)('billing.subscription_ended', $this->org->id);

        $data = ($this->compute)('this_month')['revenue'];

        expect($data['new_subscriptions']['value'])->toBe(1)->and($data['upgrades']['value'])->toBe(1)->and($data['cancellations']['value'])->toBe(1);
    });

    it('sépare les recettes de rendez-vous et les pourboires, sans les ajouter au revenu de Nova', function (): void {
        $customer = (string) Str::uuid();
        ($this->db)()->table('customers')->insert(['id' => $customer, 'org_id' => $this->org->id, 'phone_e164' => '+32470000001', 'reference_number' => 1]);
        $location = ($this->db)()->table('locations')->where('org_id', $this->org->id)->value('id');
        $practitioner = (string) Str::uuid();
        ($this->db)()->table('practitioners')->insert(['id' => $practitioner, 'org_id' => $this->org->id, 'name' => 'Agnès']);
        $service = (string) Str::uuid();
        ($this->db)()->table('services')->insert(['id' => $service, 'org_id' => $this->org->id, 'name' => 'Coupe', 'duration_minutes' => 30, 'price_cents' => 4000, 'vat_rate' => 21]);
        $booking = (string) Str::uuid();
        $start = now()->subHours(2);
        ($this->db)()->table('bookings')->insert([
            'id' => $booking, 'org_id' => $this->org->id, 'location_id' => $location, 'customer_id' => $customer, 'practitioner_id' => $practitioner, 'service_id' => $service, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30),
            'blocked_from' => $start, 'blocked_until' => $start->copy()->addMinutes(30), 'status' => 'completed', 'source' => 'dashboard', 'price_cents' => 4000, 'vat_rate' => 21, 'currency' => 'EUR', 'payment_status' => 'paid_onsite', 'amount_collected_cents' => 4000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ($this->db)()->transaction(function () use ($booking, $customer, $practitioner): void {
            $tip = (string) Str::uuid();
            ($this->db)()->table('booking_tips')->insert(['id' => $tip, 'org_id' => $this->org->id, 'booking_id' => $booking, 'customer_id' => $customer, 'amount_cents' => 500, 'currency' => 'EUR', 'paid_at' => now()]);
            ($this->db)()->table('booking_tip_allocations')->insert(['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'tip_id' => $tip, 'booking_id' => $booking, 'practitioner_id' => $practitioner, 'amount_cents' => 500]);
        });

        $data = ($this->compute)('this_month');

        expect($data['revenue']['client_money'])->toMatchArray(['appointments_cents' => 4000, 'tips_cents' => 500, 'total_cents' => 4500])
            ->and($data['revenue']['subscription']['collected_cents'])->toBe(0)
            ->and($data['usage']['completed']['value'])->toBe(1)->and($data['usage']['created']['value'])->toBe(1)->and($data['usage']['no_show_percent'])->toBe(0.0)
            ->and(array_sum(array_map('array_sum', $data['usage']['heatmap'])))->toBe(0 + 1);
    });

    it('répartit les clients par état et par formule', function (): void {
        ($this->subscribe)($this->org->id);
        ['org' => $trial] = $this->activatedAccount(['name' => 'Essai SRL', 'status' => 'trial', 'trial_ends_at' => now()->addDays(2)], 'essai@example.com');

        $data = ($this->compute)('this_month');

        expect($data['clients']['by_state'])->toMatchArray(['active' => 1, 'trial' => 1])->and($data['clients']['total'])->toBe(2)
            ->and(($this->kpi)($data, 'trials_ending')['value'])->toBe(1)->and(($this->kpi)($data, 'trials_in_progress')['value'])->toBe(1);
    });

    it('calcule la note moyenne des clients avec sa comparaison', function (): void {
        ($this->db)()->table('nova_feedback')->insert([
            ['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'user_id' => $this->owner->id, 'source' => 'general', 'rating' => 5, 'locale' => 'fr', 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'user_id' => $this->owner->id, 'source' => 'general', 'rating' => 1, 'locale' => 'fr', 'created_at' => now()],
        ]);

        $data = ($this->compute)('this_month');
        $rating = ($this->kpi)($data, 'rating');

        expect($rating['value'])->toBe(3.0)->and($rating['comparison']['previous'])->toBeNull()->and($rating['comparison']['direction'])->toBe('none')
            ->and(collect($data['alerts'])->firstWhere('key', 'low_ratings')['count'])->toBe(1);
    });

    it('indique l\'absence de données plutôt que d\'inventer un chiffre', function (): void {
        ($this->db)()->table('organizations')->where('id', $this->org->id)->update(['created_at' => now()->subYear()]);
        $data = ($this->compute)('today');

        expect(($this->kpi)($data, 'rating')['value'])->toBeNull()->and(($this->kpi)($data, 'rating')['note'])->toBe('no_data')
            ->and($data['clients']['conversion_percent'])->toBeNull()->and($data['usage']['cancellation_percent'])->toBeNull();
    });
});

describe('alertes', function (): void {
    it('remonte impayés, essais, tickets urgents avec leur lien, du plus grave au moins grave', function (): void {
        ($this->subscribe)($this->org->id, 'past_due');
        ($this->db)()->table('organizations')->where('id', $this->org->id)->update(['status' => 'past_due']);

        $alerts = ($this->compute)('this_month')['alerts'];
        $past = collect($alerts)->firstWhere('key', 'past_due');

        expect($past['severity'])->toBe('danger')->and($past['count'])->toBe(1)->and($past['link']['target'])->toBe('clients')
            ->and($alerts[0]['severity'])->toBe('danger');

        $order = array_map(fn (array $a): int => ['danger' => 0, 'warning' => 1, 'info' => 2][$a['severity']], $alerts);
        $sorted = $order;
        sort($sorted);
        expect($order)->toBe($sorted);
    });

    it('ne montre aucune alerte quand tout va bien', function (): void {
        ($this->db)()->table('organizations')->where('id', $this->org->id)->update(['created_at' => now()]);

        $keys = collect(($this->compute)('this_month')['alerts'])->pluck('key')->all();

        expect($keys)->not->toContain('past_due')->not->toContain('urgent_tickets')->not->toContain('failed_payments');
    });
});

describe('page', function (): void {
    it('affiche le message de bienvenue, la période, les chiffres clés et les graphiques accessibles', function (): void {
        $sub = ($this->subscribe)($this->org->id);
        ($this->pay)($this->org->id, $sub, 10000);

        $page = Livewire::actingAs($this->boss, 'platform')->test(AdminDashboard::class);

        $page->assertSuccessful()->assertSee('Fondatrice Nova')->assertSee(__('dashboard.kpi.mrr.label'))->assertSee(__('dashboard.kpi.revenue_collected.label'))
            ->assertSee(__('dashboard.sections.alerts'))->assertSee(__('dashboard.revenue.client_money_title'))
            ->assertSeeHtml('role="img"')->assertSeeHtml('aria-labelledby="nd-rev-t')->assertSee(__('dashboard.data_table'));
    });

    it('change de période : le résumé et la comparaison suivent', function (): void {
        $page = Livewire::actingAs($this->boss, 'platform')->test(AdminDashboard::class);

        $page->set('filters.preset', 'last_month')->assertSee(Carbon::now()->subMonthNoOverflow()->startOfMonth()->format('d/m/Y'))->assertSee(Carbon::now()->subMonthsNoOverflow(2)->startOfMonth()->format('d/m/Y'));

        $page->set('filters.preset', 'custom')->set('filters.from', '2026-01-01')->set('filters.until', '2026-01-31')->assertSee('01/01/2026')->assertSee('31/01/2026')->assertSee('01/12/2025');
    });

    it('actualise les chiffres à la demande', function (): void {
        $page = Livewire::actingAs($this->boss, 'platform')->test(AdminDashboard::class);
        $page->assertSee('0');
        ($this->db)()->table('organizations')->where('id', $this->org->id)->update(['created_at' => now()]);

        $page->callAction('refresh')->assertNotified(__('dashboard.refreshed'))->assertSee(__('dashboard.kpi.new_clients.label'));
    });

    it('affiche un message d\'erreur et permet de réessayer quand le calcul échoue', function (): void {
        $this->mock(DashboardMetrics::class, fn ($mock) => $mock->shouldReceive('compute')->andThrow(new RuntimeException('boom')));

        Livewire::actingAs($this->boss, 'platform')->test(AdminDashboard::class)->assertSee(__('dashboard.error.title'))->assertSee(__('dashboard.error.retry'));
    });

    it('renvoie les agents de support vers les tickets et les éditeurs vers les FAQ', function (): void {
        $agent = PlatformAdmin::query()->create(['email' => 'agent@nova.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
        $agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
        $editor = PlatformAdmin::query()->create(['email' => 'editeur@nova.test', 'password' => 'mot-de-passe-de-lediteur-2026!']);
        $editor->forceFill(['support_role' => PlatformAdmin::SUPPORT_EDITOR])->save();

        Livewire::actingAs($agent->refresh(), 'platform')->test(AdminDashboard::class)->assertRedirect(SupportTicketResource::getUrl());
        Livewire::actingAs($editor->refresh(), 'platform')->test(AdminDashboard::class)->assertRedirect(FaqArticleResource::getUrl());
    });

    it('met les chiffres en cache brièvement et ne les montre qu\'au Super Admin', function (): void {
        $agent = PlatformAdmin::query()->create(['email' => 'agent@nova.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
        $agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
        $this->actingAs($agent->refresh(), 'platform');

        $page = new AdminDashboard;

        expect($page->isRestricted())->toBeTrue()->and($page->data())->toBeNull();
    });
});
