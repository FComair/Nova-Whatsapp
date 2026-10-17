<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Émission des factures : numéro séquentiel sans trou, identité du vendeur et du client FIGÉES à l'émission, TVA calculée.
 * À appeler dans une transaction : le numéro n'est consommé que si la facture est validée.
 */
final class InvoiceService
{
    public function __construct(private readonly VatCalculator $vat) {}

    /**
     * @param  list<array{description: string, quantity?: int, unit_cents: int}>  $lines
     */
    public function issue(Organization $organization, ?Subscription $subscription, array $lines, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Plan $plan = null): Invoice
    {
        $subtotal = array_sum(array_map(fn (array $line): int => ($line['quantity'] ?? 1) * $line['unit_cents'], $lines));
        $totals = $this->vat->compute($organization, $subtotal);
        $number = (string) DB::selectOne('select next_invoice_number(?) as n', [(int) now()->format('Y')])->n;

        $invoice = Invoice::query()->create([
            'org_id' => $organization->id,
            'subscription_id' => $subscription?->id,
            'plan_name' => $plan?->name,
            'number' => $number,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'open',
            'currency' => 'EUR',
            'subtotal_cents' => $totals['subtotal'],
            'vat_rate' => $totals['rate'],
            'vat_cents' => $totals['vat'],
            'total_cents' => $totals['total'],
            'seller_snapshot' => (array) config('rendivoo.billing.seller'),
            'billing_snapshot' => $this->billingIdentity($organization),
            'issued_at' => now(),
        ]);

        foreach ($lines as $line) {
            $quantity = $line['quantity'] ?? 1;
            InvoiceLine::query()->create([
                'org_id' => $organization->id,
                'invoice_id' => $invoice->id,
                'description' => mb_substr($line['description'], 0, 255),
                'quantity' => $quantity,
                'unit_cents' => $line['unit_cents'],
                'total_cents' => $quantity * $line['unit_cents'],
            ]);
        }

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    public function billingIdentity(Organization $organization): array
    {
        return [
            'name' => $organization->billing_name ?: $organization->name,
            'vat_number' => $organization->billing_vat_number,
            'address' => $organization->billing_address,
            'country' => $organization->country,
        ];
    }
}
