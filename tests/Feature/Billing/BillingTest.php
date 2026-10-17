<?php

declare(strict_types=1);

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\BillingNotification;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Support\BillingFailure;
use App\Modules\Billing\Support\FakeGateway;
use App\Modules\Privacy\Services\DeletionPipeline;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Facturation : souscription, TVA, factures numérotées, changement de formule, renouvellement, relances, fin d'essai, RGPD.
 * « Maintenant » = lundi 1er juin 2026, 10:00 UTC. Prestataire simulé : aucun débit réel.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'trial', 'trial_ends_at' => now()->addDays(14), 'country' => 'BE']);
    $this->gateway = app(PaymentGateway::class);
    $this->billing = app(BillingService::class);
    $this->basic = Plan::query()->where('code', 'basic')->firstOrFail();
    $this->business = Plan::query()->where('code', 'business')->firstOrFail();

    $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);
    $this->orgRow = fn () => DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->first();
    $this->fresh = fn () => $this->org->refresh();

    /** Souscrit avec la carte demandée (valide par défaut). */
    $this->subscribe = function (?Plan $plan = null, string $method = FakeGateway::OK): Subscription {
        return ($this->in)(function () use ($plan, $method): Subscription {
            $organization = ($this->fresh)();
            $session = $this->billing->beginSetup($organization, ($plan ?? $this->basic)->code);
            $this->gateway->simulateChoice($session->ref, $method);

            return $this->billing->subscribe($organization, $plan ?? $this->basic, $session->ref, $this->owner->id);
        });
    };
    $this->process = fn (): ?string => ($this->in)(fn () => $this->billing->process(($this->fresh)()));
    $this->sub = fn () => ($this->in)(fn () => Subscription::query()->first());
    $this->sent = fn (string $kind): int => Notification::sentNotifications ?? 0;
    $this->failure = function (callable $callback): ?string {
        try {
            $callback();
        } catch (BillingFailure $e) {
            return $e->reason;
        }

        return null;
    };
});

describe('souscription', function (): void {
    it('débite le premier mois, crée l\'abonnement et la facture payée, et active le compte', function (): void {
        $subscription = ($this->subscribe)();

        $invoice = ($this->in)(fn () => Invoice::query()->with('lines')->sole());
        $org = ($this->orgRow)();

        expect($subscription)->status->toBe('active')->provider->toBe('fake')->failed_attempts->toBe(0)
            ->and($subscription->current_period_start->toDateTimeString())->toBe('2026-06-01 10:00:00')
            ->and($subscription->current_period_end->toDateTimeString())->toBe('2026-07-01 10:00:00')
            ->and($invoice)->number->toBe('NOVA-2026-000001')->status->toBe('paid')->subtotal_cents->toBe(2999)->vat_rate->toBe(21)->vat_cents->toBe(630)->total_cents->toBe(3629)
            ->and($invoice->paid_at)->not->toBeNull()
            ->and($invoice->plan_name)->toBe($this->basic->name)
            ->and($invoice->lines)->toHaveCount(1)->and($invoice->lines[0]->description)->toContain('Abonnement Rendivoo Basic')
            ->and($org)->status->toBe('active')->plan_id->toBe($this->basic->id)
            ->and($this->gateway->charges)->toHaveCount(1)->and($this->gateway->charges[0]['amount'])->toBe(3629)
            ->and(($this->in)(fn () => Payment::query()->sole()))->status->toBe('succeeded')->amount_cents->toBe(3629);
    });

    it('fige le vendeur et l\'identité de facturation du client sur la facture', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['billing_name' => 'Aurore SRL', 'billing_vat_number' => 'BE0123456789', 'billing_address' => ['line1' => 'Rue 1', 'city' => 'Bruxelles']])->save());

        ($this->subscribe)();
        $this->asOwner(fn () => $this->org->forceFill(['billing_name' => 'Autre nom'])->save());

        $invoice = ($this->in)(fn () => Invoice::query()->sole());

        expect($invoice->billing_snapshot)->toMatchArray(['name' => 'Aurore SRL', 'vat_number' => 'BE0123456789'])->and($invoice->seller_snapshot)->toHaveKeys(['name', 'vat_number', 'address']);
    });

    it('écrit la ligne de facture dans la langue de l\'établissement', function (string $locale, string $text): void {
        $this->asOwner(fn () => $this->org->forceFill(['locale' => $locale])->save());

        ($this->subscribe)();

        expect(($this->in)(fn () => Invoice::query()->with('lines')->sole()->lines[0]->description))->toContain($text);
    })->with([['fr', 'Abonnement Rendivoo Basic'], ['nl', 'Rendivoo-abonnement Basic'], ['en', 'Rendivoo subscription Basic']]);

    it('ne stocke que le jeton du prestataire, chiffré, et de quoi reconnaître la carte', function (): void {
        ($this->subscribe)();

        $raw = json_encode(DB::connection('pgsql_owner')->table('payment_methods')->first());
        $method = ($this->in)(fn () => PaymentMethod::query()->sole());

        expect($raw)->not->toContain('pm_fake_ok')->and($method)->last4->toBe('4242')->brand->toBe('visa')->and($method->provider_ref_enc)->toBe('pm_fake_ok');
    });

    it('n\'enregistre rien quand la carte est refusée', function (): void {
        $reason = ($this->failure)(fn () => ($this->subscribe)(null, FakeGateway::DECLINED));

        expect($reason)->toBe('payment_failed')
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('payment_methods')->count())->toBe(0)
            ->and(($this->orgRow)()->status)->toBe('trial')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'billing.payment_failed')->count()))->toBe(1);
    });

    it('refuse quand la saisie du moyen de paiement n\'a pas abouti', function (): void {
        $reason = ($this->failure)(fn () => ($this->in)(function (): void {
            $session = $this->billing->beginSetup(($this->fresh)(), 'basic');

            $this->billing->subscribe(($this->fresh)(), $this->basic, $session->ref, null);
        }));

        expect($reason)->toBe('setup_incomplete');
    });

    it('refuse un second abonnement, mais permet de se réabonner après une fin d\'abonnement', function (): void {
        ($this->subscribe)();

        expect(($this->failure)(fn () => ($this->subscribe)()))->toBe('already_subscribed');

        DB::connection('pgsql_owner')->table('subscriptions')->update(['status' => 'canceled']);
        ($this->subscribe)($this->business);

        expect(($this->sub)())->status->toBe('active')->plan_id->toBe($this->business->id)->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(2);
    });

    it('est impossible quand le compte est en pause ou en suppression, ou suspendu par la plateforme', function (string $status, ?string $reason, string $expected): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status, 'suspension_reason' => $reason])->save());

        expect(($this->failure)(fn () => ($this->subscribe)()))->toBe($expected);
    })->with([
        ['paused', null, 'account_unavailable'],
        ['pending_deletion', null, 'account_unavailable'],
        ['suspended', 'Fraude constatée', 'suspended_by_platform'],
    ]);

    it('rétablit un compte suspendu par le système (fin d\'essai ou impayé), mais jamais une suspension de la plateforme', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended', 'suspension_reason' => 'system.trial_ended', 'suspended_at' => now(), 'previous_status' => 'trial'])->save());

        ($this->subscribe)();

        expect(($this->orgRow)())->status->toBe('active')->suspension_reason->toBeNull()->suspended_at->toBeNull()->previous_status->toBeNull();
    });
});

describe('TVA et numérotation', function (): void {
    it('applique 21 % en Belgique, l\'autoliquidation pour un professionnel de l\'UE avec numéro de TVA, sinon 21 %', function (string $country, ?string $vat, int $rate, int $vatCents): void {
        $this->asOwner(fn () => $this->org->forceFill(['country' => $country, 'billing_vat_number' => $vat])->save());

        ($this->subscribe)();

        $invoice = ($this->in)(fn () => Invoice::query()->sole());

        expect($invoice->vat_rate)->toBe($rate)->and($invoice->vat_cents)->toBe($vatCents)->and($invoice->total_cents)->toBe(2999 + $vatCents);
    })->with([
        'Belgique' => ['BE', null, 21, 630],
        'Belgique avec numéro' => ['BE', 'BE0123456789', 21, 630],
        'France avec numéro' => ['FR', 'FR12345678901', 0, 0],
        'France sans numéro' => ['FR', null, 21, 630],
    ]);

    it('numérote les factures de toutes les organisations sans trou ni doublon', function (): void {
        ($this->subscribe)();
        ['org' => $other] = $this->activatedAccount(['status' => 'trial', 'trial_ends_at' => now()->addDays(5)], email: 'autre@example.com');
        app(TenantContext::class)->run($other->id, function () use ($other): void {
            $session = $this->billing->beginSetup($other, 'business');
            $this->gateway->simulateChoice($session->ref, FakeGateway::OK);
            $this->billing->subscribe($other, $this->business, $session->ref, null);
        });

        expect(DB::connection('pgsql_owner')->table('invoices')->orderBy('number')->pluck('number')->all())->toBe(['NOVA-2026-000001', 'NOVA-2026-000002']);
    });

    it('ne consomme pas de numéro quand la transaction est annulée', function (): void {
        ($this->in)(function (): void {
            try {
                DB::transaction(function (): void {
                    DB::selectOne('select next_invoice_number(2026) as n');

                    throw new RuntimeException('annulé');
                });
            } catch (RuntimeException) {
            }
        });

        ($this->subscribe)();

        expect(DB::connection('pgsql_owner')->table('invoices')->value('number'))->toBe('NOVA-2026-000001');
    });

    it('recommence la numérotation à chaque année', function (): void {
        $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00:00', 'UTC'));

        ($this->subscribe)();

        expect(DB::connection('pgsql_owner')->table('invoices')->value('number'))->toBe('NOVA-2027-000001');
    });
});

describe('changement de formule', function (): void {
    it('monte de formule tout de suite et débite le complément au prorata du temps restant', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addDays(15)->setTime(10, 0)); // milieu de la période (30 jours)

        $result = ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->business, $this->owner->id));

        $invoices = DB::connection('pgsql_owner')->table('invoices')->orderBy('number')->get();
        $charge = end($this->gateway->charges);

        expect($result)->toBe('upgraded')->and($invoices)->toHaveCount(2)
            ->and($invoices[1])->status->toBe('paid')->subtotal_cents->toBe(1000)->vat_cents->toBe(210)->total_cents->toBe(1210)   // (4999 − 2999) × 15/30
            ->and($charge['amount'])->toBe(1210)
            ->and(($this->sub)())->plan_id->toBe($this->business->id)->and(($this->orgRow)()->plan_id)->toBe($this->business->id);
    });

    it('ne débite rien si la montée a lieu à la toute fin de la période', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth());

        ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->business, null));

        expect(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(1)->and(($this->sub)()->plan_id)->toBe($this->business->id);
    });

    it('laisse la formule inchangée quand le complément est refusé', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addDays(10));
        $this->gateway->failNext = 'card_declined';

        $reason = ($this->failure)(fn () => ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->business, null)));

        expect($reason)->toBe('payment_failed')->and(($this->sub)()->plan_id)->toBe($this->basic->id)->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(1);
    });

    it('programme une descente pour l\'échéance et l\'applique au renouvellement', function (): void {
        ($this->subscribe)($this->business);

        $result = ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->basic, null));

        expect($result)->toBe('downgrade_scheduled')->and(($this->sub)())->plan_id->toBe($this->business->id)->pending_plan_id->toBe($this->basic->id);

        $this->travelTo(now()->addMonth()->addMinute());
        expect(($this->process)())->toBe('renewed');

        expect(($this->sub)())->plan_id->toBe($this->basic->id)->pending_plan_id->toBeNull()
            ->and(DB::connection('pgsql_owner')->table('invoices')->orderBy('number')->value('subtotal_cents'))->toBe(4999)
            ->and(DB::connection('pgsql_owner')->table('invoices')->orderByDesc('number')->value('subtotal_cents'))->toBe(2999)
            ->and(($this->orgRow)()->plan_id)->toBe($this->basic->id);
    });

    it('refuse une descente quand l\'usage dépasse les limites de la nouvelle formule (E8)', function (): void {
        ($this->subscribe)($this->business);
        $this->asOwner(function (): void {
            foreach (['Léa', 'Marc'] as $name) {
                Practitioner::query()->create(['org_id' => $this->org->id, 'name' => $name, 'color' => '#2A5BD7']);
            }
        });

        $reason = ($this->failure)(fn () => ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->basic, null)));

        expect($reason)->toBe('limit_practitioners')->and(($this->sub)()->pending_plan_id)->toBeNull();
    });

    it('efface une descente programmée quand on choisit la formule actuelle', function (): void {
        ($this->subscribe)($this->business);
        ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->basic, null));

        $result = ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->business, null));

        expect($result)->toBe('unchanged')->and(($this->sub)()->pending_plan_id)->toBeNull();
    });

    it('exige un abonnement', function (): void {
        expect(($this->failure)(fn () => ($this->in)(fn () => $this->billing->changePlan(($this->fresh)(), $this->business, null))))->toBe('no_subscription');
    });
});

describe('annulation', function (): void {
    it('reste actif jusqu\'à la fin de la période payée, puis suspend le compte et prévient', function (): void {
        ($this->subscribe)();
        ($this->in)(fn () => $this->billing->cancel(($this->fresh)(), $this->owner->id));

        expect(($this->sub)()->cancel_at->toDateTimeString())->toBe('2026-07-01 10:00:00');

        $this->travelTo(now()->addDays(10));
        expect(($this->process)())->toBeNull()->and(($this->orgRow)()->status)->toBe('active');

        $this->travelTo(now()->addDays(21));
        expect(($this->process)())->toBe('ended');

        expect(($this->sub)()->status)->toBe('canceled')->and(($this->orgRow)())->status->toBe('suspended')->suspension_reason->toBe('system.subscription_ended')
            ->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(1);
        Notification::assertSentOnDemand(BillingNotification::class, fn (BillingNotification $n): bool => str_contains(json_encode($n->toMail(new stdClass)->subject), 'Rendivoo'));
    });

    it('peut être annulée avant la fin de la période', function (): void {
        ($this->subscribe)();
        ($this->in)(fn () => $this->billing->cancel(($this->fresh)(), null));
        ($this->in)(fn () => $this->billing->resume(($this->fresh)(), null));

        $this->travelTo(now()->addMonth()->addMinute());

        expect(($this->sub)()->cancel_at)->toBeNull()->and(($this->process)())->toBe('renewed');
    });
});

describe('renouvellement et relances (E7)', function (): void {
    it('ne renouvelle pas avant l\'échéance, puis facture et débite la période suivante', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addDays(29));

        expect(($this->process)())->toBeNull()->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(1);

        $this->travelTo(now()->addDay()->addMinute());
        expect(($this->process)())->toBe('renewed');

        $subscription = ($this->sub)();

        expect($subscription->current_period_start->toDateTimeString())->toBe('2026-07-01 10:00:00')->and($subscription->current_period_end->toDateTimeString())->toBe('2026-08-01 10:00:00')
            ->and(DB::connection('pgsql_owner')->table('invoices')->orderByDesc('number')->first())->number->toBe('NOVA-2026-000002')->status->toBe('paid');
        expect(($this->process)())->toBeNull(); // pas de double facturation
    });

    it('relance après un refus (3 jours plus tard), passe en impayé, prévient, puis suspend au dernier refus', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        $this->gateway->failNext = 'card_declined';

        expect(($this->process)())->toBe('retry_scheduled');

        $subscription = ($this->sub)();
        expect($subscription)->status->toBe('past_due')->failed_attempts->toBe(1)->and($subscription->next_attempt_at->toDateString())->toBe(now()->addDays(3)->toDateString())
            ->and(($this->orgRow)()->status)->toBe('past_due')
            ->and(DB::connection('pgsql_owner')->table('dunning_events')->count())->toBe(1);
        Notification::assertSentOnDemand(BillingNotification::class);

        expect(($this->process)())->toBeNull();          // trop tôt

        $this->gateway->failNext = 'card_declined';
        $this->travelTo(now()->addDays(3)->addMinute());
        expect(($this->process)())->toBe('retry_scheduled');

        $this->gateway->failNext = 'card_declined';
        $this->travelTo(now()->addDays(3)->addMinute());
        expect(($this->process)())->toBe('suspended');

        expect(($this->sub)())->status->toBe('past_due')->failed_attempts->toBe(3)->next_attempt_at->toBeNull()
            ->and(($this->orgRow)())->status->toBe('suspended')->suspension_reason->toBe('system.payment_failed')
            ->and(DB::connection('pgsql_owner')->table('invoices')->where('status', 'open')->count())->toBe(1)   // une seule facture ouverte
            ->and(DB::connection('pgsql_owner')->table('dunning_events')->count())->toBe(3);

        $this->travelTo(now()->addDays(30));
        expect(($this->process)())->toBeNull(); // plus aucune tentative automatique
    });

    it('rétablit le compte quand une relance réussit, avec une seule facture pour la période', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        $this->gateway->failNext = 'card_declined';
        ($this->process)();

        $this->travelTo(now()->addDays(3)->addMinute());
        expect(($this->process)())->toBe('renewed');

        expect(($this->sub)())->status->toBe('active')->failed_attempts->toBe(0)->next_attempt_at->toBeNull()
            ->and(($this->orgRow)()->status)->toBe('active')
            ->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(2)
            ->and(DB::connection('pgsql_owner')->table('payments')->where('status', 'failed')->count())->toBe(1);
    });

    it('utilise les paramètres globaux (nombre et délai des relances)', function (): void {
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'dunning_max_attempts')->update(['value' => '2']);
        DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'dunning_retry_days')->update(['value' => '1']);

        try {
            ($this->subscribe)();
            $this->travelTo(now()->addMonth()->addMinute());
            $this->gateway->failNext = 'card_declined';
            ($this->process)();

            expect(($this->sub)()->next_attempt_at->toDateString())->toBe(now()->addDay()->toDateString());

            $this->gateway->failNext = 'card_declined';
            $this->travelTo(now()->addDay()->addMinute());

            expect(($this->process)())->toBe('suspended');
        } finally {
            DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'dunning_max_attempts')->update(['value' => '3']);
            DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'dunning_retry_days')->update(['value' => '3']);
        }
    });

    it('régularise avec un nouveau moyen de paiement, et lève la suspension due à l\'impayé', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        foreach (range(1, 3) as $ignored) {
            $this->gateway->failNext = 'card_declined';
            ($this->process)();
            $this->travelTo(now()->addDays(3)->addMinute());
        }
        expect(($this->orgRow)()->status)->toBe('suspended');

        $paid = ($this->in)(function (): bool {
            $session = $this->billing->beginSetup(($this->fresh)(), null);
            $this->gateway->simulateChoice($session->ref, FakeGateway::OK);

            return $this->billing->updatePaymentMethod(($this->fresh)(), $session->ref, $this->owner->id);
        });

        expect($paid)->toBeTrue()->and(($this->sub)())->status->toBe('active')->failed_attempts->toBe(0)
            ->and(($this->orgRow)())->status->toBe('active')->suspension_reason->toBeNull()
            ->and(DB::connection('pgsql_owner')->table('invoices')->where('status', 'open')->count())->toBe(0);
    });

    it('refuse une nouvelle carte qui échoue et laisse le compte suspendu', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        foreach (range(1, 3) as $ignored) {
            $this->gateway->failNext = 'card_declined';
            ($this->process)();
            $this->travelTo(now()->addDays(3)->addMinute());
        }

        $reason = ($this->failure)(fn () => ($this->in)(function (): void {
            $session = $this->billing->beginSetup(($this->fresh)(), null);
            $this->gateway->simulateChoice($session->ref, FakeGateway::DECLINED);
            $this->billing->updatePaymentMethod(($this->fresh)(), $session->ref, null);
        }));

        expect($reason)->toBe('payment_failed')->and(($this->orgRow)()->status)->toBe('suspended');
    });

    it('ne lève jamais une suspension décidée par la plateforme, même si l\'impayé est réglé', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        $this->gateway->failNext = 'card_declined';
        ($this->process)();
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended', 'suspension_reason' => 'Fraude constatée'])->save());

        ($this->in)(function (): void {
            $session = $this->billing->beginSetup(($this->fresh)()->forceFill(['status' => 'past_due']), null);
            $this->gateway->simulateChoice($session->ref, FakeGateway::OK);
            $this->billing->updatePaymentMethod(($this->fresh)(), $session->ref, null);
        });

        expect(($this->orgRow)()->status)->toBe('suspended');
    });

    it('sans moyen de paiement, un renouvellement échoue proprement', function (): void {
        ($this->subscribe)();
        DB::connection('pgsql_owner')->table('subscriptions')->update(['payment_method_id' => null]);
        $this->travelTo(now()->addMonth()->addMinute());

        expect(($this->process)())->toBe('retry_scheduled')->and(DB::connection('pgsql_owner')->table('payments')->where('failure_code', 'no_payment_method')->count())->toBe(1);
    });
});

describe('essai', function (): void {
    it('prévient une fois avant la fin, puis suspend à la fin sans abonnement', function (): void {
        $this->travelTo(now()->addDays(10));
        expect(($this->process)())->toBeNull();                                 // 4 jours avant la fin : trop tôt

        $this->travelTo(now()->addDays(1));
        expect(($this->process)())->toBe('trial_reminded')->and(($this->process)())->toBeNull();
        Notification::assertSentOnDemandTimes(BillingNotification::class, 1);

        $this->travelTo(now()->addDays(3)->addMinute());
        expect(($this->process)())->toBe('trial_ended');

        expect(($this->orgRow)())->status->toBe('suspended')->suspension_reason->toBe('system.trial_ended')->previous_status->toBe('trial');
        Notification::assertSentOnDemandTimes(BillingNotification::class, 2);
    });

    it('ne touche pas un essai qui a déjà un abonnement', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addDays(20));

        expect(($this->process)())->toBeNull()->and(($this->orgRow)()->status)->toBe('active');
    });

    it('fixe la fin d\'essai à l\'inscription selon le paramètre global', function (): void {
        $this->post('/inscription', $this->validRegistration(['email' => 'nouveau@example.com']))->assertSessionHasNoErrors();

        $trialEnd = DB::connection('pgsql_owner')->table('organizations')->orderByDesc('created_at')->value('trial_ends_at');

        expect(CarbonImmutable::parse($trialEnd)->toDateString())->toBe(now()->addDays(14)->toDateString());
    });
});

describe('commande planifiée', function (): void {
    it('traite chaque organisation sous son contexte et résume', function (): void {
        ($this->subscribe)();
        $this->travelTo(now()->addMonth()->addMinute());
        app(TenantContext::class)->clear();

        $this->artisan('billing:run')->expectsOutputToContain('renewed: 1')->assertSuccessful();
        $this->artisan('billing:run')->expectsOutputToContain('Rien à traiter.')->assertSuccessful();
    });
});

describe('isolation et sécurité', function (): void {
    it('cache les factures, abonnements et moyens de paiement d\'une autre organisation', function (): void {
        ($this->subscribe)();
        $other = $this->asOwner(fn () => Organization::factory()->create());

        app(TenantContext::class)->run($other->id, function (): void {
            expect(Invoice::query()->count())->toBe(0)->and(Subscription::query()->count())->toBe(0)->and(PaymentMethod::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0);
        });
    });

    it('interdit au rôle client d\'écrire dans le compteur de factures ou de l\'appeler pour une autre année sans la fonction', function (): void {
        expect(fn () => DB::connection('pgsql')->table('invoice_sequences')->update(['last_number' => 0]))->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->selectOne('select next_invoice_number(2026)'))->toThrow(QueryException::class);
    });

    it('rejette une facture dont le total ne correspond pas au sous-total et à la TVA', function (): void {
        ($this->subscribe)();

        expect(fn () => DB::connection('pgsql_owner')->table('invoices')->update(['total_cents' => 1]))->toThrow(QueryException::class);
    });
});

describe('RGPD', function (): void {
    it('programme la suppression même si le serveur d\'e-mail est injoignable', function (): void {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        $admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);

        app(OrganizationLifecycle::class)->scheduleDeletion(PlatformOrganization::query()->findOrFail($this->org->id), $admin->id, null);

        expect(DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->value('status'))->toBe('pending_deletion');
    });

    it('pseudonymise les factures, supprime les moyens de paiement, garde les montants et les paiements', function (): void {
        ($this->subscribe)();
        $this->asOwner(fn () => $this->org->forceFill(['billing_name' => 'Aurore SRL'])->save());
        $admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
        app(OrganizationLifecycle::class)->scheduleDeletion(PlatformOrganization::query()->findOrFail($this->org->id), $admin->id, 'Demande écrite du propriétaire');
        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_scheduled_for' => now()->subMinute()]);

        expect(app(DeletionPipeline::class)->run())->toBe(1);

        $invoice = DB::connection('pgsql_owner')->table('invoices')->first();

        expect($invoice)->number->toBe('NOVA-2026-000001')->total_cents->toBe(3629)->and($invoice->pseudonymised_at)->not->toBeNull()->and(json_decode($invoice->billing_snapshot, true))->toBe([])
            ->and(DB::connection('pgsql_owner')->table('payment_methods')->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('payments')->count())->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('invoice_lines')->count())->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->value('status'))->toBe('canceled')
            ->and(DB::connection('pgsql_owner')->table('subscriptions')->value('payment_method_id'))->toBeNull();
    });
});
