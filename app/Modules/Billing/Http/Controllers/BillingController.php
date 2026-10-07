<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentMethod;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\VatCalculator;
use App\Modules\Billing\Support\BillingFailure;
use App\Modules\Billing\Support\FakeGateway;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Facturation » (propriétaire uniquement, docs/02 §1) : formule, moyen de paiement, factures, annulation.
 * Accessible même quand le compte est suspendu (pour régulariser) ; les refus (compte en pause, suspension décidée par la
 * plateforme…) viennent du service de facturation.
 */
final class BillingController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly BillingService $billing,
        private readonly PaymentGateway $gateway,
        private readonly VatCalculator $vat,
    ) {}

    public function index(): Response
    {
        $organization = $this->organization();
        $subscription = $this->billing->subscription();
        $method = PaymentMethod::query()->first();
        $plans = Plan::query()->where('is_active', true)->orderBy('position')->get();
        $active = $subscription !== null && $subscription->status !== 'canceled';

        return Inertia::render('Billing/Index', [
            'status' => $organization->status,
            'trialEndsAt' => $organization->trial_ends_at?->toIso8601String(),
            'suspensionReason' => $organization->suspension_reason,
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'price_cents' => $plan->price_monthly_cents, 'limits' => $plan->limits,
            ])->all(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status,
                'plan' => $plans->firstWhere('id', $subscription->plan_id)?->code,
                'pending_plan' => $plans->firstWhere('id', $subscription->pending_plan_id)?->code,
                'period_end' => $subscription->current_period_end->toIso8601String(),
                'cancel_at' => $subscription->cancel_at?->toIso8601String(),
                'failed_attempts' => $subscription->failed_attempts,
                'next_attempt_at' => $subscription->next_attempt_at?->toIso8601String(),
            ],
            'hasActiveSubscription' => $active,
            'method' => $method === null ? null : ['brand' => $method->brand, 'last4' => $method->last4, 'exp_month' => $method->exp_month, 'exp_year' => $method->exp_year],
            'vatRate' => $this->vat->rateFor($organization),
            'invoices' => Invoice::query()->orderByDesc('issued_at')->limit(24)->get()->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id, 'number' => $invoice->number, 'issued_at' => $invoice->issued_at->toIso8601String(), 'total_cents' => $invoice->total_cents, 'status' => $invoice->status,
            ])->all(),
            'identity' => [
                'billing_name' => $organization->billing_name ?? '',
                'vat_number' => $organization->billing_vat_number ?? '',
                'line1' => (string) ($organization->billing_address['line1'] ?? ''),
                'postal_code' => (string) ($organization->billing_address['postal_code'] ?? ''),
                'city' => (string) ($organization->billing_address['city'] ?? ''),
            ],
        ]);
    }

    /** Choix d'une formule : souscription (page du prestataire) ou changement de formule si déjà abonné. */
    public function choosePlan(Request $request): mixed
    {
        $data = $request->validate(['plan' => ['required', 'string']]);
        $plan = Plan::query()->where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $organization = $this->organization();
        $subscription = $this->billing->subscription();

        try {
            if ($subscription !== null && $subscription->status !== 'canceled') {
                $result = $this->billing->changePlan($organization, $plan, (string) $request->user()?->id);

                return back()->with('status', __("ui.billing.plan_{$result}"));
            }

            return Inertia::location($this->billing->beginSetup($organization, $plan->code)->url);
        } catch (BillingFailure $e) {
            return $this->fail($e);
        }
    }

    /** Remplacement du moyen de paiement : ouvre la page du prestataire. */
    public function changeMethod(): mixed
    {
        try {
            return Inertia::location($this->billing->beginSetup($this->organization(), null)->url);
        } catch (BillingFailure $e) {
            return $this->fail($e);
        }
    }

    /** Retour de la page du prestataire : souscription ou nouveau moyen de paiement selon l'abonnement. */
    public function completeReturn(Request $request): RedirectResponse
    {
        $data = $request->validate(['setup_ref' => ['required', 'string', 'max:100'], 'plan' => ['nullable', 'string']]);
        $organization = $this->organization();
        $subscription = $this->billing->subscription();
        $actor = (string) $request->user()?->id;

        try {
            if ($subscription === null || $subscription->status === 'canceled') {
                $plan = Plan::query()->where('code', (string) ($data['plan'] ?? ''))->where('is_active', true)->firstOrFail();
                $this->billing->subscribe($organization, $plan, $data['setup_ref'], $actor);

                return to_route('billing.index')->with('status', __('ui.billing.subscribed'));
            }

            $repaired = $this->billing->updatePaymentMethod($organization, $data['setup_ref'], $actor);

            return to_route('billing.index')->with('status', __($repaired ? 'ui.billing.repaired' : 'ui.billing.method_updated'));
        } catch (BillingFailure $e) {
            return $this->fail($e);
        }
    }

    public function cancel(Request $request): RedirectResponse
    {
        try {
            $this->billing->cancel($this->organization(), (string) $request->user()?->id);
        } catch (BillingFailure $e) {
            return $this->fail($e);
        }

        return back()->with('status', __('ui.billing.cancel_requested'));
    }

    public function resume(Request $request): RedirectResponse
    {
        try {
            $this->billing->resume($this->organization(), (string) $request->user()?->id);
        } catch (BillingFailure $e) {
            return $this->fail($e);
        }

        return back()->with('status', __('ui.billing.cancel_reverted'));
    }

    /** Identité de facturation (figée sur chaque facture au moment de son émission). */
    public function updateIdentity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z]{2}[A-Za-z0-9 .\-]{2,26}$/'],
            'line1' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);
        $organization = $this->organization();

        $organization->forceFill([
            'billing_name' => filled($data['billing_name'] ?? null) ? trim($data['billing_name']) : null,
            'billing_vat_number' => filled($data['vat_number'] ?? null) ? strtoupper(preg_replace('/[\s.\-]/', '', $data['vat_number']) ?? '') : null,
            'billing_address' => ['line1' => $data['line1'] ?? null, 'postal_code' => $data['postal_code'] ?? null, 'city' => $data['city'] ?? null],
        ]);

        $changed = array_keys($organization->getDirty());
        $organization->save();

        if ($changed !== []) {
            Audit::record('billing.identity_updated', 'user', (string) $request->user()?->id, $organization->id, 'organizations', $organization->id, ['fields' => $changed]);
        }

        return back()->with('status', __('ui.billing.identity_saved'));
    }

    public function invoice(Invoice $invoice): Response
    {
        return Inertia::render('Billing/Invoice', [
            'invoice' => [
                'number' => $invoice->number, 'status' => $invoice->status, 'issued_at' => $invoice->issued_at->toIso8601String(), 'paid_at' => $invoice->paid_at?->toIso8601String(),
                'period_start' => $invoice->period_start->toIso8601String(), 'period_end' => $invoice->period_end->toIso8601String(),
                'subtotal_cents' => $invoice->subtotal_cents, 'vat_rate' => $invoice->vat_rate, 'vat_cents' => $invoice->vat_cents, 'total_cents' => $invoice->total_cents,
                'seller' => $invoice->seller_snapshot, 'customer' => $invoice->billing_snapshot,
                'lines' => $invoice->lines()->get()->map(fn ($line): array => ['description' => $line->description, 'quantity' => $line->quantity, 'unit_cents' => $line->unit_cents, 'total_cents' => $line->total_cents])->all(),
            ],
        ]);
    }

    /** Page de simulation du prestataire (développement et tests seulement, prestataire simulé). */
    public function simulation(Request $request): Response
    {
        $this->assertSimulation();

        return Inertia::render('Billing/Simulation', ['setupRef' => $request->string('ref')->toString()]);
    }

    public function simulate(Request $request): mixed
    {
        $this->assertSimulation();
        $data = $request->validate(['ref' => ['required', 'string', 'max:100'], 'card' => ['required', 'in:ok,declined']]);

        $gateway = $this->gateway;
        assert($gateway instanceof FakeGateway);

        return Inertia::location($gateway->simulateChoice($data['ref'], $data['card'] === 'ok' ? FakeGateway::OK : FakeGateway::DECLINED));
    }

    private function assertSimulation(): void
    {
        abort_unless($this->gateway instanceof FakeGateway && app()->environment(['local', 'testing']), 404);
    }

    private function fail(BillingFailure $e): RedirectResponse
    {
        return to_route('billing.index')->withErrors(['billing' => __('ui.billing.errors.'.$e->reason, ['limit' => $e->detail ?? '', 'code' => $e->detail ?? ''])]);
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
