<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Modules\Admin\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Services\PlanManager;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Support\FakeGateway;
use App\Modules\Tenancy\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin › Formules : création, publication, versions, protection des abonnés, suppression et migration (docs/40).
 */
beforeEach(function (): void {
    Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->manager = fn (): PlanManager => app(PlanManager::class);
    $this->plan = fn (string $code): PlatformPlan => PlatformPlan::query()->where('code', $code)->firstOrFail();
    $this->make = fn (array $data = []): PlatformPlan => ($this->manager)()->create([
        'name' => 'Pro', 'plan_type' => 'monthly', 'billing_frequency' => 'monthly', 'price_cents' => 7999, 'currency' => 'EUR',
        'limits' => ['practitioners' => 10, 'locations' => 2, 'conversations' => 1000, 'appointments' => 800],
        'public_description' => ['fr' => 'Pour les équipes.'],
        'features' => [['label' => ['fr' => 'Rapports avancés', 'en' => 'Advanced reports'], 'state' => 'included', 'value' => null]],
        ...$data,
    ], $this->admin->id);

    ['org' => $this->org, 'user' => $this->owner] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'trial', 'trial_ends_at' => now()->addDays(14), 'country' => 'BE']);
    $this->subscribeTo = function (Plan $plan): Subscription {
        $billing = app(BillingService::class);
        $gateway = app(PaymentGateway::class);

        return app(TenantContext::class)->run($this->org->id, function () use ($plan, $billing, $gateway): Subscription {
            $organization = $this->org->refresh();
            $session = $billing->beginSetup($organization, $plan->code);
            $gateway->simulateChoice($session->ref, FakeGateway::OK);

            return $billing->subscribe($organization, $plan, $session->ref, $this->owner->id);
        });
    };
});

describe('création et publication', function (): void {
    it('crée une formule en brouillon avec un code unique tiré du nom', function (): void {
        $plan = ($this->make)();
        $other = ($this->make)(['name' => 'Pro']);

        expect($plan->status)->toBe('draft')->and($plan->is_active)->toBeFalse()->and($plan->code)->toBe('pro')->and($other->code)->toBe('pro-2')
            ->and($plan->price_monthly_cents)->toBe(7999)->and($plan->family_id)->toBe($plan->id)->and($plan->version)->toBe(1)
            ->and($plan->features[0]['label']['fr'])->toBe('Rapports avancés');
    });

    it('ne rend jamais visible un brouillon, une formule inactive ou archivée', function (): void {
        $plan = ($this->make)();
        $codes = fn () => Plan::query()->available()->pluck('code')->all();

        expect(($codes)())->not->toContain('pro');

        ($this->manager)()->setStatus($plan, 'active', $this->admin->id);
        expect(($codes)())->toContain('pro');

        ($this->manager)()->setStatus($plan->fresh(), 'inactive', $this->admin->id);
        expect(($codes)())->not->toContain('pro');

        ($this->manager)()->setStatus($plan->fresh(), 'archived', $this->admin->id);
        expect(($codes)())->not->toContain('pro')->and(PlatformPlan::query()->where('code', 'pro')->exists())->toBeTrue();
    });

    it('respecte les dates de disponibilité', function (): void {
        $plan = ($this->make)(['status' => 'active', 'available_from' => now()->addDay(), 'available_until' => now()->addDays(10)]);

        expect(Plan::query()->available()->where('code', 'pro')->exists())->toBeFalse();

        $this->travelTo(now()->addDays(2));
        expect(Plan::query()->available()->where('code', 'pro')->exists())->toBeTrue();

        $this->travelTo(now()->addDays(20));
        expect(Plan::query()->available()->where('code', 'pro')->exists())->toBeFalse();
    });

    it('refuse de publier un paiement unique, une formule achetable sans prix, un bouton incohérent', function (array $override, string $message): void {
        expect(fn () => ($this->make)(['status' => 'active', ...$override]))->toThrow(DomainException::class, __($message));
    })->with([
        'paiement unique' => [['plan_type' => 'one_time', 'cta_type' => 'contact_sales', 'cta_url' => 'mailto:ventes@rendivoo.test'], 'plans.errors.one_time_unsupported'],
        'sans prix' => [['price_cents' => 0], 'plans.errors.price_required'],
        'achat en ligne pour une formule sur mesure' => [['plan_type' => 'custom', 'cta_type' => 'choose_plan'], 'plans.errors.cta_not_purchasable'],
        'contact sans adresse' => [['plan_type' => 'custom', 'cta_type' => 'contact_sales'], 'plans.errors.cta_url_required'],
    ]);

    it('valide les champs avec des messages clairs', function (array $override, string $message): void {
        expect(fn () => ($this->make)($override))->toThrow(InvalidArgumentException::class, __($message));
    })->with([
        'nom vide' => [['name' => '  '], 'plans.errors.name'],
        'prix négatif' => [['price_cents' => -1], 'plans.errors.price'],
        'devise inconnue' => [['currency' => 'XYZ'], 'plans.errors.currency'],
        'période personnalisée hors limites' => [['billing_frequency' => 'custom', 'billing_interval_months' => 99], 'plans.errors.interval'],
        'segments sans choix' => [['visibility' => 'segments'], 'plans.errors.segments'],
        'fin avant début' => [['available_from' => '2026-07-01', 'available_until' => '2026-06-01'], 'plans.errors.window'],
        'adresse de bouton invalide' => [['cta_url' => 'javascript:alert(1)'], 'plans.errors.cta_url'],
        'fonctionnalité sans libellé français' => [['features' => [['label' => ['en' => 'Only English'], 'state' => 'included']]], 'plans.errors.feature_label'],
        'limite négative' => [['limits' => ['practitioners' => -3]], 'plans.errors.limit'],
    ]);

    it('génère un code d\'invitation pour une formule sur invitation et garde une seule formule recommandée', function (): void {
        $invite = ($this->make)(['visibility' => 'invite_only']);
        $first = ($this->make)(['name' => 'Premier', 'status' => 'active', 'is_recommended' => true]);
        $second = ($this->make)(['name' => 'Second', 'status' => 'active', 'is_recommended' => true]);

        expect($invite->invite_code)->toHaveLength(10)
            ->and($first->fresh()->is_recommended)->toBeFalse()->and($second->fresh()->is_recommended)->toBeTrue();
    });

    it('duplique en brouillon avec son propre code, sans copier code d\'invitation ni recommandation', function (): void {
        $plan = ($this->make)(['visibility' => 'invite_only', 'status' => 'active', 'is_recommended' => true]);

        $copy = ($this->manager)()->duplicate($plan, $this->admin->id);

        expect($copy->status)->toBe('draft')->and($copy->code)->not->toBe($plan->code)->and($copy->name)->toContain(__('plans.copy_suffix'))
            ->and($copy->is_recommended)->toBeFalse()->and($copy->invite_code)->not->toBe($plan->invite_code)->and($copy->family_id)->toBe($copy->id);
    });
});

describe('protection des abonnés', function (): void {
    it('crée une nouvelle version quand le prix d\'une formule avec abonnés change, et laisse les abonnés intacts', function (): void {
        $basic = ($this->plan)('basic');
        $subscription = ($this->subscribeTo)($basic);

        $new = ($this->manager)()->update($basic, ['price_cents' => 3999], $this->admin->id);

        $old = PlatformPlan::query()->findOrFail($basic->id);
        expect($new->id)->not->toBe($basic->id)->and($new->code)->toBe('basic')->and($new->version)->toBe(2)->and($new->price_cents)->toBe(3999)
            ->and($old->code)->toBe('basic~v1')->and($old->price_cents)->toBe(2999)->and($old->superseded_by)->toBe($new->id)->and($old->status)->toBe('archived')
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->where('id', $subscription->id)->value('plan_id'))->toBe($basic->id)
            ->and(Plan::query()->available()->where('code', 'basic')->value('id'))->toBe($new->id);
    });

    it('facture encore l\'ancien prix au renouvellement d\'un abonné après un changement de prix', function (): void {
        $basic = ($this->plan)('basic');
        ($this->subscribeTo)($basic);

        ($this->manager)()->update($basic, ['price_cents' => 3999], $this->admin->id);

        $this->travelTo(now()->addMonth()->addMinute());
        app(TenantContext::class)->run($this->org->id, fn () => app(BillingService::class)->process($this->org->refresh()));

        $totals = DB::connection('pgsql_owner')->table('invoices')->orderBy('issued_at')->pluck('subtotal_cents')->all();
        expect($totals)->toBe([2999, 2999]);
    });

    it('modifie sur place ce qui n\'est pas commercial, même avec des abonnés', function (): void {
        $basic = ($this->plan)('basic');
        ($this->subscribeTo)($basic);

        $same = ($this->manager)()->update($basic, ['public_description' => ['fr' => 'Nouveau texte'], 'position' => 9], $this->admin->id);

        expect($same->id)->toBe($basic->id)->and($same->fresh()->publicDescription('fr'))->toBe('Nouveau texte')->and($same->fresh()->version)->toBe(1);
    });

    it('modifie sur place, sans nouvelle version, une formule sans abonné', function (): void {
        $plan = ($this->make)(['status' => 'active']);

        $same = ($this->manager)()->update($plan, ['price_cents' => 8999], $this->admin->id);

        expect($same->id)->toBe($plan->id)->and($same->fresh()->price_cents)->toBe(8999)->and(PlatformPlan::query()->where('family_id', $plan->family_id)->count())->toBe(1);
    });

    it('refuse de modifier une version remplacée', function (): void {
        $basic = ($this->plan)('basic');
        ($this->subscribeTo)($basic);
        ($this->manager)()->update($basic, ['price_cents' => 3999], $this->admin->id);

        expect(fn () => ($this->manager)()->update(PlatformPlan::query()->findOrFail($basic->id), ['name' => 'X'], $this->admin->id))->toThrow(DomainException::class);
    });

    it('archive une formule sans toucher à ses abonnés', function (): void {
        $basic = ($this->plan)('basic');
        $subscription = ($this->subscribeTo)($basic);

        ($this->manager)()->setStatus($basic, 'archived', $this->admin->id);

        expect(PlatformPlan::query()->findOrFail($basic->id)->status)->toBe('archived')
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->where('id', $subscription->id)->value('plan_id'))->toBe($basic->id)
            ->and(Plan::query()->available()->where('code', 'basic')->exists())->toBeFalse();
    });

    it('ne remet pas en brouillon une formule qui a des abonnés', function (): void {
        $basic = ($this->plan)('basic');
        ($this->subscribeTo)($basic);

        expect(fn () => ($this->manager)()->setStatus($basic, 'draft', $this->admin->id))->toThrow(DomainException::class);
    });

    it('refuse de supprimer une formule qui a des abonnés, et programme leur migration à l\'échéance', function (): void {
        $basic = ($this->plan)('basic');
        $business = ($this->plan)('business');
        ($this->subscribeTo)($basic);
        ($this->manager)()->setStatus($basic, 'inactive', $this->admin->id);

        expect(fn () => ($this->manager)()->delete($basic->fresh(), $this->admin->id))->toThrow(DomainException::class, __('plans.errors.has_subscribers', ['count' => 2]));

        $count = ($this->manager)()->migrateSubscribers($basic->fresh(), $business, $this->admin->id);

        expect($count)->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->value('pending_plan_id'))->toBe($business->id)
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->value('plan_id'))->toBe($basic->id);
    });

    it('programme la migration à l\'échéance : le prix de la formule de destination s\'applique alors', function (): void {
        $basic = ($this->plan)('basic');
        $business = ($this->plan)('business');
        ($this->subscribeTo)($basic);

        ($this->manager)()->migrateSubscribers($basic, $business, $this->admin->id);
        $this->travelTo(now()->addMonth()->addMinute());
        app(TenantContext::class)->run($this->org->id, fn () => app(BillingService::class)->process($this->org->refresh()));

        expect(DB::connection('pgsql_owner')->table('subscriptions')->value('plan_id'))->toBe($business->id)
            ->and(DB::connection('pgsql_owner')->table('invoices')->orderBy('issued_at')->pluck('subtotal_cents')->all())->toBe([2999, 4999]);
    });

    it('refuse une migration vers soi-même, un brouillon ou une formule non achetable', function (): void {
        $basic = ($this->plan)('basic');
        $draft = ($this->make)();
        $custom = ($this->make)(['name' => 'Sur mesure', 'plan_type' => 'custom', 'price_cents' => 0, 'cta_type' => 'contact_sales', 'cta_url' => 'mailto:v@rendivoo.test', 'status' => 'active']);

        expect(fn () => ($this->manager)()->migrateSubscribers($basic, $basic, $this->admin->id))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->manager)()->migrateSubscribers($basic, $draft, $this->admin->id))->toThrow(DomainException::class)
            ->and(fn () => ($this->manager)()->migrateSubscribers($basic, $custom, $this->admin->id))->toThrow(DomainException::class);
    });

    it('supprime un brouillon sans référence, et trace la suppression', function (): void {
        $plan = ($this->make)();

        ($this->manager)()->delete($plan, $this->admin->id);

        expect(PlatformPlan::query()->whereKey($plan->id)->exists())->toBeFalse()
            ->and(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'plan.deleted')->count())->toBe(1);
    });
});

describe('écran', function (): void {
    it('liste les formules avec leurs abonnés et filtre par statut', function (): void {
        ($this->subscribeTo)(($this->plan)('basic'));
        $draft = ($this->make)();
        $page = fn () => Livewire::actingAs($this->admin, 'platform')->test(ListPlans::class);

        $page()->assertSuccessful()->assertCanSeeTableRecords(PlatformPlan::query()->get())
            ->assertTableColumnStateSet('subscribers_count', 1, ($this->plan)('basic'))
            ->assertTableColumnStateSet('subscribers_count', 0, $draft);
        $page()->filterTable('status', ['draft'])->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([($this->plan)('basic')]);
        $page()->filterTable('with_subscribers', true)->assertCanSeeTableRecords([($this->plan)('basic')])->assertCanNotSeeTableRecords([$draft]);
        $page()->searchTable('Business')->assertCanSeeTableRecords([($this->plan)('business')])->assertCanNotSeeTableRecords([$draft]);
    });

    it('refuse la suppression d\'une formule avec abonnés depuis l\'écran, avec un message clair', function (): void {
        ($this->subscribeTo)(($this->plan)('basic'));

        Livewire::actingAs($this->admin, 'platform')->test(ListPlans::class)->callTableAction('delete', ($this->plan)('basic'));

        expect(PlatformPlan::query()->where('code', 'basic')->exists())->toBeTrue();
    });

    it('crée une formule depuis le formulaire, en euros, et la montre en brouillon', function (): void {
        Livewire::actingAs($this->admin, 'platform')->test(CreatePlan::class)
            ->fillForm([
                'name' => 'Équipe', 'status' => 'draft', 'plan_type' => 'monthly', 'billing_frequency' => 'quarterly', 'price_cents' => '89,90', 'currency' => 'EUR', 'tax_mode' => 'excluded',
                'setup_fee_cents' => '0', 'visibility' => 'public', 'cta_type' => 'choose_plan', 'public_description' => ['fr' => 'Pour les équipes.'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = PlatformPlan::query()->where('name', 'Équipe')->firstOrFail();
        expect($plan->status)->toBe('draft')->and($plan->price_cents)->toBe(8990)->and($plan->billing_interval_months)->toBe(3)->and($plan->price_monthly_cents)->toBe(2997);
    });
});
