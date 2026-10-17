<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Audit\Audit;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\BillingNotification;
use App\Modules\Billing\Support\BillingFailure;
use App\Modules\Billing\Support\ChargeResult;
use App\Modules\Billing\Support\OwnerContact;
use App\Modules\Billing\Support\SetupSession;
use App\Modules\Billing\Support\StoredMethod;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abonnement, facturation mensuelle, relances d'impayé et fin d'essai (docs/01 §9, flowcharts 3C, 4A, E7).
 *
 * Rendivoo pilote la facturation ; le prestataire de paiement ne fait que recueillir un moyen de paiement et le débiter (jeton seulement).
 * Tout s'exécute sous le contexte de l'organisation (RLS). Aucune durée n'est en dur : essai, nombre et délai des relances viennent
 * de `platform_settings`.
 *
 * Règles (HYPOTHÈSES à valider) : facturation mensuelle ; montée de formule immédiate avec complément au prorata ; descente à
 * l'échéance ; annulation effective à la fin de la période payée ; impayé : relances puis suspension du compte ; essai sans
 * abonnement : suspension à la fin de l'essai.
 */
final class BillingService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly InvoiceService $invoices,
        private readonly VatCalculator $vat,
        private readonly PlatformSettings $settings,
    ) {}

    public function subscription(): ?Subscription
    {
        return Subscription::query()->first();
    }

    public function beginSetup(Organization $organization, ?string $planCode): SetupSession
    {
        $this->assertCanPay($organization);

        return $this->gateway->beginSetup($organization, url('/facturation/retour').($planCode === null ? '' : '?plan='.$planCode));
    }

    /**
     * Souscrit à une formule avec le moyen de paiement saisi : le premier mois est débité AVANT toute écriture ; en cas de refus,
     * rien n'est enregistré.
     *
     * @throws BillingFailure
     */
    public function subscribe(Organization $organization, Plan $plan, string $setupRef, ?string $actorId): Subscription
    {
        $this->assertCanPay($organization);
        $existing = $this->subscription();

        if ($existing !== null && $existing->status !== 'canceled') {
            throw new BillingFailure('already_subscribed');
        }

        $method = $this->gateway->completeSetup($setupRef) ?? throw new BillingFailure('setup_incomplete');
        $start = CarbonImmutable::now();
        $end = $start->addMonths($plan->interval());
        $totals = $this->vat->compute($organization, $plan->netCents($this->homeRate()) + $plan->netSetupFeeCents($this->homeRate()));
        $charge = $this->gateway->charge($method->ref, $totals['total'], $plan->currency, 'subscribe:'.$organization->id.':'.$setupRef, $plan->name);

        if (! $charge->succeeded) {
            Audit::record('billing.payment_failed', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'organizations', $organization->id, ['reason' => $charge->failureCode, 'context' => 'subscribe']);

            throw new BillingFailure('payment_failed', $charge->failureCode);
        }

        return DB::transaction(function () use ($organization, $plan, $method, $start, $end, $charge, $actorId, $existing): Subscription {
            $paymentMethod = $this->storeMethod($organization, $method);
            $attributes = [
                'plan_id' => $plan->id, 'pending_plan_id' => null, 'status' => 'active', 'provider' => $this->gateway->name(),
                'payment_method_id' => $paymentMethod->id, 'current_period_start' => $start, 'current_period_end' => $end,
                'cancel_at' => null, 'failed_attempts' => 0, 'next_attempt_at' => null,
            ];

            if ($existing === null) {
                $subscription = Subscription::query()->create(['org_id' => $organization->id, ...$attributes]);
            } else {
                $existing->forceFill($attributes)->save();
                $subscription = $existing;
            }

            $invoice = $this->invoiceFor($organization, $subscription, $plan, $start, $end, withSetupFee: true);
            $this->markPaid($organization, $invoice, $charge);
            $this->activate($organization, $plan);

            Audit::record('billing.subscribed', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id, ['plan' => $plan->code]);

            return $subscription;
        });
    }

    /**
     * Remplace le moyen de paiement. Si le compte est en impayé, la facture ouverte est aussitôt représentée : réussie, le compte est
     * rétabli.
     *
     * @return bool vrai quand un impayé a été régularisé
     *
     * @throws BillingFailure
     */
    public function updatePaymentMethod(Organization $organization, string $setupRef, ?string $actorId): bool
    {
        $subscription = $this->subscription() ?? throw new BillingFailure('no_subscription');
        $method = $this->gateway->completeSetup($setupRef) ?? throw new BillingFailure('setup_incomplete');

        DB::transaction(function () use ($organization, $subscription, $method): void {
            $paymentMethod = $this->storeMethod($organization, $method);
            $subscription->forceFill(['payment_method_id' => $paymentMethod->id])->save();
        });

        Audit::record('billing.method_updated', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id);

        if ($subscription->status !== 'past_due') {
            return false;
        }

        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', 'open')->orderBy('issued_at')->first();

        if ($invoice === null) {
            return false;
        }

        if (! $this->attempt($organization, $subscription, $invoice, notify: false)) {
            throw new BillingFailure('payment_failed', Payment::query()->where('invoice_id', $invoice->id)->orderByDesc('created_at')->value('failure_code'));
        }

        return true;
    }

    /**
     * Change de formule. Montée : immédiate, complément au prorata du temps restant débité aussitôt. Descente : appliquée à
     * l'échéance, refusée si l'usage dépasse les limites de la nouvelle formule (erreur E8).
     *
     * @return 'upgraded'|'downgrade_scheduled'|'unchanged'
     *
     * @throws BillingFailure
     */
    public function changePlan(Organization $organization, Plan $new, ?string $actorId): string
    {
        $subscription = $this->subscription();

        if ($subscription === null || $subscription->status === 'canceled') {
            throw new BillingFailure('no_subscription');
        }

        $current = Plan::query()->findOrFail($subscription->plan_id);

        if ($new->id === $current->id) {
            $subscription->forceFill(['pending_plan_id' => null])->save();

            return 'unchanged';
        }

        $newPerMonth = $new->netCents($this->homeRate()) / $new->interval();
        $currentPerMonth = $current->netCents($this->homeRate()) / $current->interval();

        if ($newPerMonth < $currentPerMonth) {
            $this->assertFits($new);
            $subscription->forceFill(['pending_plan_id' => $new->id])->save();
            Audit::record('billing.downgrade_scheduled', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id, ['plan' => $new->code]);

            return 'downgrade_scheduled';
        }

        $total = max(1, $subscription->current_period_end->getTimestamp() - $subscription->current_period_start->getTimestamp());
        $remaining = max(0, min($total, $subscription->current_period_end->getTimestamp() - CarbonImmutable::now()->getTimestamp()));
        $subtotal = (int) round(($newPerMonth - $currentPerMonth) * $current->interval() * $remaining / $total);

        DB::transaction(function () use ($organization, $subscription, $new, $current, $subtotal): void {
            if ($subtotal > 0) {
                $invoice = $this->invoices->issue($organization, $subscription, [[
                    'description' => __('billing.invoice.upgrade', ['from' => $current->name, 'to' => $new->name], $this->invoiceLocale($organization)),
                    'unit_cents' => $subtotal,
                ]], now(), $subscription->current_period_end, $new);

                $charge = $this->chargeInvoice($organization, $subscription, $invoice, 'upgrade');

                if (! $charge->succeeded) {
                    throw new BillingFailure('payment_failed', $charge->failureCode);
                }

                $this->markPaid($organization, $invoice, $charge);
            }

            $subscription->forceFill(['plan_id' => $new->id, 'pending_plan_id' => null])->save();
            $organization->forceFill(['plan_id' => $new->id])->save();
        });

        Audit::record('billing.upgraded', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id, ['plan' => $new->code]);

        return 'upgraded';
    }

    public function cancel(Organization $organization, ?string $actorId): void
    {
        $subscription = $this->subscription() ?? throw new BillingFailure('no_subscription');

        $subscription->forceFill(['cancel_at' => $subscription->current_period_end, 'pending_plan_id' => null])->save();
        Audit::record('billing.cancel_requested', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id);
    }

    public function resume(Organization $organization, ?string $actorId): void
    {
        $subscription = $this->subscription() ?? throw new BillingFailure('no_subscription');

        if ($subscription->status === 'canceled') {
            throw new BillingFailure('no_subscription');
        }

        $subscription->forceFill(['cancel_at' => null])->save();
        Audit::record('billing.cancel_reverted', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'subscriptions', $subscription->id);
    }

    /**
     * Passage périodique pour UNE organisation : renouvellement dû, fin d'essai et rappel de fin d'essai.
     * À exécuter sous le contexte de l'organisation.
     *
     * @return string|null événement survenu (`renewed`, `retry_scheduled`, `suspended`, `ended`, `trial_ended`, `trial_reminded`)
     */
    public function process(Organization $organization): ?string
    {
        $subscription = $this->subscription();
        $now = CarbonImmutable::now();

        if ($subscription !== null) {
            $due = in_array($subscription->status, ['active', 'past_due'], true)
                && $subscription->current_period_end <= $now
                && ($subscription->next_attempt_at === null || $subscription->next_attempt_at <= $now)
                && ($subscription->status === 'active' || $subscription->next_attempt_at !== null);

            return $due ? $this->renew($organization, $subscription) : null;
        }

        if ($organization->status !== Organization::STATUS_TRIAL || $organization->trial_ends_at === null) {
            return null;
        }

        if ($organization->trial_ends_at <= $now) {
            $this->suspend($organization, 'system.trial_ended', Organization::STATUS_TRIAL);
            OwnerContact::notify(new BillingNotification(BillingNotification::TRIAL_ENDED, [], OwnerContact::locale()));
            Audit::record('billing.trial_ended', 'system', null, $organization->id, 'organizations', $organization->id);

            return 'trial_ended';
        }

        if ($organization->trial_reminder_sent_at === null && $organization->trial_ends_at <= $now->addDays($this->settings->integer('trial_reminder_days'))) {
            $locale = OwnerContact::locale();
            OwnerContact::notify(new BillingNotification(BillingNotification::TRIAL_ENDING, ['date' => $organization->trial_ends_at->locale($locale)->isoFormat('DD/MM/YYYY')], $locale));
            $organization->forceFill(['trial_reminder_sent_at' => $now])->save();

            return 'trial_reminded';
        }

        return null;
    }

    /**
     * Renouvellement : facture de la période suivante (avec la formule prévue à l'échéance), débit, puis en cas de refus
     * relances espacées (paramètres globaux) et, à la dernière, suspension du compte.
     */
    private function renew(Organization $organization, Subscription $subscription): string
    {
        if ($subscription->cancel_at !== null && $subscription->cancel_at <= now()) {
            $subscription->forceFill(['status' => 'canceled', 'next_attempt_at' => null])->save();
            $this->suspend($organization, 'system.subscription_ended', $organization->status);
            OwnerContact::notify(new BillingNotification(BillingNotification::SUBSCRIPTION_ENDED, [], OwnerContact::locale()));
            Audit::record('billing.subscription_ended', 'system', null, $organization->id, 'subscriptions', $subscription->id);

            return 'ended';
        }

        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->where('status', 'open')->orderBy('issued_at')->first();

        if ($invoice === null) {
            $plan = Plan::query()->findOrFail($subscription->pending_plan_id ?? $subscription->plan_id);
            $start = CarbonImmutable::instance($subscription->current_period_end);
            $invoice = $this->invoiceFor($organization, $subscription, $plan, $start, $start->addMonths($plan->interval()));
        }

        return $this->attempt($organization, $subscription, $invoice, notify: true) ? 'renewed' : ($subscription->refresh()->next_attempt_at === null ? 'suspended' : 'retry_scheduled');
    }

    /**
     * Débite une facture ouverte et en tire les conséquences.
     *
     * @return bool vrai si le paiement a réussi
     */
    private function attempt(Organization $organization, Subscription $subscription, Invoice $invoice, bool $notify): bool
    {
        $charge = $this->chargeInvoice($organization, $subscription, $invoice, 'attempt'.$subscription->failed_attempts);

        if ($charge->succeeded) {
            DB::transaction(function () use ($organization, $subscription, $invoice, $charge): void {
                $this->markPaid($organization, $invoice, $charge);
                $plan = Plan::query()->findOrFail($subscription->pending_plan_id ?? $subscription->plan_id);
                $subscription->forceFill([
                    'plan_id' => $plan->id, 'pending_plan_id' => null, 'status' => 'active', 'failed_attempts' => 0, 'next_attempt_at' => null,
                    'current_period_start' => $invoice->period_start, 'current_period_end' => $invoice->period_end,
                ])->save();
                $this->activate($organization, $plan);
            });
            Audit::record('billing.paid', 'system', null, $organization->id, 'invoices', $invoice->id);

            return true;
        }

        // Régularisation demandée par le propriétaire : un refus ne change ni l'état du compte ni le compteur de relances.
        if (! $notify) {
            Audit::record('billing.payment_failed', 'system', null, $organization->id, 'invoices', $invoice->id, ['reason' => $charge->failureCode, 'context' => 'manual']);

            return false;
        }

        $attempts = $subscription->failed_attempts + 1;
        $max = $this->settings->integer('dunning_max_attempts');
        $final = $attempts >= $max;
        $next = $final ? null : CarbonImmutable::now()->addDays($this->settings->integer('dunning_retry_days'));
        $locale = OwnerContact::locale();

        $subscription->forceFill(['status' => 'past_due', 'failed_attempts' => $attempts, 'next_attempt_at' => $next])->save();
        Audit::record('billing.payment_failed', 'system', null, $organization->id, 'invoices', $invoice->id, ['attempt' => $attempts, 'reason' => $charge->failureCode]);

        DB::table('dunning_events')->insert(['id' => (string) Str::uuid7(), 'org_id' => $organization->id, 'invoice_id' => $invoice->id, 'attempt' => $attempts, 'channel' => 'email', 'sent_at' => now()]);
        OwnerContact::notify(new BillingNotification(
            $final ? BillingNotification::PAYMENT_FAILED_FINAL : BillingNotification::PAYMENT_FAILED,
            ['attempt' => $attempts, 'max' => $max, 'amount' => number_format($invoice->total_cents / 100, 2, $locale === 'en' ? '.' : ',', ' ').' €', 'date' => $next === null ? '' : $next->locale($locale)->isoFormat('DD/MM/YYYY')],
            $locale,
        ));

        if ($final) {
            $this->suspend($organization, 'system.payment_failed', Organization::STATUS_PAST_DUE);
        } elseif (in_array($organization->status, [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE], true)) {
            $organization->forceFill(['status' => Organization::STATUS_PAST_DUE])->save();
        }

        return false;
    }

    private function chargeInvoice(Organization $organization, Subscription $subscription, Invoice $invoice, string $context): ChargeResult
    {
        $method = $subscription->payment_method_id === null ? null : PaymentMethod::query()->find($subscription->payment_method_id);
        $result = $method === null
            ? ChargeResult::failed('no_payment_method')
            : $this->gateway->charge($method->provider_ref_enc, $invoice->total_cents, $invoice->currency, 'invoice:'.$invoice->id.':'.$context, $invoice->number);

        Payment::query()->create([
            'org_id' => $organization->id, 'invoice_id' => $invoice->id, 'provider' => $this->gateway->name(), 'provider_ref' => $result->providerRef,
            'status' => $result->succeeded ? 'succeeded' : 'failed', 'amount_cents' => $invoice->total_cents, 'failure_code' => $result->failureCode,
        ]);

        return $result;
    }

    private function markPaid(Organization $organization, Invoice $invoice, ChargeResult $charge): void
    {
        $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        if (! Payment::query()->where('invoice_id', $invoice->id)->where('provider_ref', $charge->providerRef)->exists()) {
            Payment::query()->create(['org_id' => $organization->id, 'invoice_id' => $invoice->id, 'provider' => $this->gateway->name(), 'provider_ref' => $charge->providerRef, 'status' => 'succeeded', 'amount_cents' => $invoice->total_cents]);
        }
    }

    private function invoiceFor(Organization $organization, Subscription $subscription, Plan $plan, CarbonInterface $start, CarbonInterface $end, bool $withSetupFee = false): Invoice
    {
        $locale = $this->invoiceLocale($organization);
        $lines = [[
            'description' => __('billing.invoice.subscription', ['plan' => $plan->name, 'from' => $start->locale($locale)->isoFormat('DD/MM/YYYY'), 'to' => $end->locale($locale)->isoFormat('DD/MM/YYYY')], $locale),
            'unit_cents' => $plan->netCents($this->homeRate()),
        ]];

        if ($withSetupFee && $plan->setup_fee_cents > 0) {
            $lines[] = ['description' => __('billing.invoice.setup_fee', ['plan' => $plan->name], $locale), 'unit_cents' => $plan->netSetupFeeCents($this->homeRate())];
        }

        return $this->invoices->issue($organization, $subscription, $lines, $start, $end, $plan);
    }

    /** Taux de TVA du pays d'établissement de Rendivoo : celui des prix affichés « TVA comprise ». */
    private function homeRate(): int
    {
        return (int) config('rendivoo.billing.vat.home_rate');
    }

    /** Le compte devient actif avec la formule payée ; une suspension décidée par la plateforme n'est JAMAIS levée par un paiement. */
    private function activate(Organization $organization, Plan $plan): void
    {
        $lifted = $organization->status === Organization::STATUS_SUSPENDED && str_starts_with((string) $organization->suspension_reason, 'system.');
        $changes = ['plan_id' => $plan->id];

        if (in_array($organization->status, [Organization::STATUS_TRIAL, Organization::STATUS_PAST_DUE], true) || $lifted) {
            $changes += ['status' => Organization::STATUS_ACTIVE, 'previous_status' => null, 'suspended_at' => null, 'suspension_reason' => null];
        }

        $organization->forceFill($changes)->save();
    }

    private function suspend(Organization $organization, string $reason, ?string $previous): void
    {
        $organization->forceFill([
            'status' => Organization::STATUS_SUSPENDED, 'previous_status' => $previous, 'suspended_at' => now(), 'suspension_reason' => $reason,
        ])->save();
    }

    /** Un seul moyen de paiement par organisation : le nouveau remplace l'ancien. */
    private function storeMethod(Organization $organization, StoredMethod $method): PaymentMethod
    {
        PaymentMethod::query()->delete();

        return PaymentMethod::query()->create([
            'org_id' => $organization->id, 'provider' => $this->gateway->name(), 'provider_ref_enc' => $method->ref,
            'brand' => $method->brand, 'last4' => $method->last4, 'exp_month' => $method->expMonth, 'exp_year' => $method->expYear,
        ]);
    }

    /**
     * @throws BillingFailure
     */
    private function assertCanPay(Organization $organization): void
    {
        if (in_array($organization->status, [Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION, Organization::STATUS_DELETED], true)) {
            throw new BillingFailure('account_unavailable');
        }

        if ($organization->status === Organization::STATUS_SUSPENDED && ! str_starts_with((string) $organization->suspension_reason, 'system.')) {
            throw new BillingFailure('suspended_by_platform');
        }
    }

    /**
     * @throws BillingFailure
     */
    private function assertFits(Plan $plan): void
    {
        $practitioners = $plan->limits['practitioners'] ?? null;
        $locations = $plan->limits['locations'] ?? null;

        if ($practitioners !== null && Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)->count() > (int) $practitioners) {
            throw new BillingFailure('limit_practitioners', (string) $practitioners);
        }

        if ($locations !== null && Location::query()->count() > (int) $locations) {
            throw new BillingFailure('limit_locations', (string) $locations);
        }
    }

    private function invoiceLocale(Organization $organization): string
    {
        return in_array($organization->locale, (array) config('rendivoo.locales'), true) ? $organization->locale : (string) config('app.fallback_locale');
    }
}
