@php
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' '.$invoice->currency;
@endphp

<div style="display: grid; gap: 1rem; font-size: .9rem">
    <table style="width: 100%; border-collapse: collapse">
        <caption style="text-align: start; font-weight: 600; margin-bottom: .35rem">{{ __('clients.invoices.lines') }}</caption>
        <thead>
            <tr>
                <th scope="col" style="text-align: start">{{ __('clients.invoices.description') }}</th>
                <th scope="col" style="text-align: end">{{ __('clients.invoices.quantity') }}</th>
                <th scope="col" style="text-align: end">{{ __('clients.invoices.unit') }}</th>
                <th scope="col" style="text-align: end">{{ __('admin.invoices.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td style="text-align: end">{{ $line->quantity }}</td>
                    <td style="text-align: end">{{ $money($line->unit_cents) }}</td>
                    <td style="text-align: end">{{ $money($line->total_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><th scope="row" colspan="3" style="text-align: end">{{ __('clients.invoices.subtotal') }}</th><td style="text-align: end">{{ $money($invoice->subtotal_cents) }}</td></tr>
            <tr><th scope="row" colspan="3" style="text-align: end">{{ __('clients.invoices.vat', ['rate' => $invoice->vat_rate]) }}</th><td style="text-align: end">{{ $money($invoice->vat_cents) }}</td></tr>
            <tr><th scope="row" colspan="3" style="text-align: end">{{ __('admin.invoices.total') }}</th><td style="text-align: end; font-weight: 700">{{ $money($invoice->total_cents) }}</td></tr>
        </tfoot>
    </table>

    <table style="width: 100%; border-collapse: collapse">
        <caption style="text-align: start; font-weight: 600; margin-bottom: .35rem">{{ __('clients.invoices.attempts') }}</caption>
        <thead>
            <tr>
                <th scope="col" style="text-align: start">{{ __('clients.invoices.when') }}</th>
                <th scope="col" style="text-align: start">{{ __('admin.invoices.status') }}</th>
                <th scope="col" style="text-align: end">{{ __('clients.invoices.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($payment->created_at)->format('d/m/Y H:i') }}</td>
                    <td>{{ __('clients.invoices.payment.'.$payment->status) }}@if ($payment->failure_code) · {{ $payment->failure_code }} @endif</td>
                    <td style="text-align: end">{{ $money($payment->amount_cents) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('clients.invoices.no_attempts') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
