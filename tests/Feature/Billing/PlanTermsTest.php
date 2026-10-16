<?php

declare(strict_types=1);

use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Support\FakeGateway;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Conditions des formules gérées par le Super Admin (docs/40) : période de facturation, TVA comprise ou non, frais de mise en service.
 * « Maintenant » = lundi 1er juin 2026, 10:00 UTC. TVA belge : 21 %.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'trial', 'trial_ends_at' => now()->addDays(14), 'country' => 'BE']);
    $this->billing = app(BillingService::class);
    $this->gateway = app(PaymentGateway::class);

    $this->plan = fn (array $attributes): Plan => $this->asOwner(fn () => Plan::query()->create([
        'id' => $id = (string) Str::uuid7(), 'family_id' => $id, 'code' => 'test-'.Str::lower(Str::random(5)), 'name' => 'Test', 'price_monthly_cents' => 2999, 'price_cents' => 2999,
        'limits' => ['practitioners' => 1, 'locations' => 1, 'conversations' => 100, 'appointments' => 50], 'status' => 'active', 'position' => 9,
        ...$attributes,
    ]));
    $this->subscribe = function (Plan $plan): Subscription {
        return app(TenantContext::class)->run($this->org->id, function () use ($plan): Subscription {
            $organization = $this->org->refresh();
            $session = $this->billing->beginSetup($organization, $plan->code);
            $this->gateway->simulateChoice($session->ref, FakeGateway::OK);

            return $this->billing->subscribe($organization, $plan, $session->ref, $this->owner->id);
        });
    };
    $this->invoices = fn () => app(TenantContext::class)->run($this->org->id, fn () => Invoice::query()->with('lines')->orderBy('issued_at')->get());
});

it('facture une période de trois mois d\'un coup et renouvelle tous les trois mois', function (): void {
    $plan = ($this->plan)(['plan_type' => 'monthly', 'billing_frequency' => 'quarterly', 'billing_interval_months' => 3, 'price_cents' => 8997, 'price_monthly_cents' => 2999]);

    $subscription = ($this->subscribe)($plan);
    $first = ($this->invoices)()->sole();

    expect($subscription->current_period_end->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($first)->subtotal_cents->toBe(8997)->vat_cents->toBe(1889)->total_cents->toBe(10886)
        ->and($this->gateway->charges[0]['amount'])->toBe(10886);

    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:01:00', 'UTC'));
    app(TenantContext::class)->run($this->org->id, fn () => $this->billing->process($this->org->refresh()));

    expect(app(TenantContext::class)->run($this->org->id, fn () => Subscription::query()->first()->current_period_end->toDateTimeString()))->toBe('2026-12-01 10:01:00')
        ->and(($this->invoices)())->toHaveCount(2);
});

it('facture une période annuelle', function (): void {
    $plan = ($this->plan)(['plan_type' => 'annual', 'billing_frequency' => 'yearly', 'billing_interval_months' => 12, 'price_cents' => 29900, 'price_monthly_cents' => 2492]);

    $subscription = ($this->subscribe)($plan);

    expect($subscription->current_period_end->toDateTimeString())->toBe('2027-06-01 10:00:00')->and(($this->invoices)()->sole())->subtotal_cents->toBe(29900);
});

it('retire la TVA d\'un prix affiché TVA comprise pour retrouver le hors-taxe, puis la rajoute', function (): void {
    $plan = ($this->plan)(['price_cents' => 12100, 'price_monthly_cents' => 12100, 'tax_mode' => 'included']);

    ($this->subscribe)($plan);

    expect(($this->invoices)()->sole())->subtotal_cents->toBe(10000)->vat_cents->toBe(2100)->total_cents->toBe(12100)
        ->and($this->gateway->charges[0]['amount'])->toBe(12100);
});

it('facture les frais de mise en service une seule fois, sur la première facture', function (): void {
    $plan = ($this->plan)(['price_cents' => 2999, 'setup_fee_cents' => 5000]);

    ($this->subscribe)($plan);
    $first = ($this->invoices)()->sole();

    expect($first->lines)->toHaveCount(2)->and($first)->subtotal_cents->toBe(7999)->total_cents->toBe(9679)
        ->and($first->lines[1]->description)->toContain('mise en service');

    $this->travelTo(CarbonImmutable::parse('2026-07-01 10:01:00', 'UTC'));
    app(TenantContext::class)->run($this->org->id, fn () => $this->billing->process($this->org->refresh()));

    $renewal = ($this->invoices)()->last();
    expect($renewal->lines)->toHaveCount(1)->and($renewal)->subtotal_cents->toBe(2999);
});

it('ne laisse pas souscrire en ligne une formule sur mesure, d\'essai ou à paiement unique', function (string $type): void {
    $plan = ($this->plan)(['plan_type' => $type, 'price_cents' => 5000]);

    expect($plan->purchasable())->toBeFalse();
    $this->signedIn($this->owner, $this->org)->post('/facturation/abonnement', ['plan' => $plan->code])->assertNotFound();
})->with(['custom', 'free_trial', 'one_time']);

it('garde un abonné sur une formule retirée de la vente et la montre sur sa page Facturation', function (): void {
    $plan = ($this->plan)(['name' => 'Ancienne offre']);
    ($this->subscribe)($plan);
    $this->asOwner(fn () => DB::table('plans')->where('id', $plan->id)->update(['status' => 'archived']));

    $page = $this->signedIn($this->owner, $this->org)->get('/facturation')->assertOk()->original->getData()['page']['props'];

    $held = collect($page['plans'])->firstWhere('code', $plan->code);
    expect($held)->not->toBeNull()->and($held['listed'])->toBeFalse()->and($page['subscription']['plan'])->toBe($plan->code);
});
