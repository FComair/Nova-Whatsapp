@php
    $m = $this->metrics();
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' €';
    $cards = [
        ['total', $m['total'], 'now'],
        ['mrr', $money($m['mrr_cents']), 'now'],
        ['renewals_7', $m['renewals_7']['count'].' · '.$money($m['renewals_7']['mrr_cents']), 'now'],
        ['renewals_30', $m['renewals_30']['count'].' · '.$money($m['renewals_30']['mrr_cents']), 'now'],
        ['active_7', $m['active_7'], 'now'],
        ['active_30', $m['active_30'], 'now'],
        ['dormant', $m['dormant'], 'now'],
        ['trials_started', $m['trials_started'], 'period'],
        ['trials_converted', $m['trials_converted'].($m['conversion_percent'] !== null ? ' ('.number_format($m['conversion_percent'], 1, ',', ' ').' %)' : ''), 'period'],
        ['cancelled', $m['cancelled'], 'period'],
        ['failed_payments', $m['failed_payments'].' · '.__('clients.reports.clients_count', ['count' => $m['failed_clients']]), 'period'],
    ];
@endphp

<x-filament-panels::page>
    @include('admin.clients.styles')
    <style>
        .cr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; margin: 0; }
    </style>

    <div class="cp">
        <form data-unsaved-ignore aria-label="{{ __('clients.reports.filters') }}">{{ $this->form }}</form>
        <p style="color: var(--cp-muted); margin: 0">{{ __('clients.reports.period', ['from' => \Illuminate\Support\Carbon::parse($m['period']['from'])->format('d/m/Y'), 'until' => \Illuminate\Support\Carbon::parse($m['period']['until'])->format('d/m/Y')]) }}</p>

        <dl class="cr-cards">
            @foreach ($cards as [$key, $value, $scope])
                <div class="cp-card">
                    <dt>{{ __("clients.reports.cards.{$key}") }}</dt>
                    <dd>{{ $value }}<small>{{ $scope === 'period' ? __('clients.reports.scope_period') : __('clients.reports.scope_now') }}</small></dd>
                </div>
            @endforeach
        </dl>

        <div class="cp-grid" style="grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr))">
            <section class="cp-card" aria-labelledby="r-plan">
                <h3 id="r-plan">{{ __('clients.reports.by_plan') }}</h3>
                <table><thead><tr><th scope="col">{{ __('clients.columns.plan') }}</th><th scope="col">{{ __('clients.reports.clients') }}</th><th scope="col">{{ __('clients.columns.mrr') }}</th></tr></thead>
                    <tbody>@forelse ($m['by_plan'] as $row)<tr><th scope="row" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:inherit">{{ $row['label'] }}</th><td>{{ $row['total'] }}</td><td>{{ $row['mrr_cents'] > 0 ? $money($row['mrr_cents']) : '—' }}</td></tr>@empty<tr><td colspan="3">{{ __('clients.reports.empty') }}</td></tr>@endforelse</tbody></table>
            </section>
            <section class="cp-card" aria-labelledby="r-state">
                <h3 id="r-state">{{ __('clients.reports.by_state') }}</h3>
                <table><tbody>@foreach ($m['by_state'] as $state => $total)<tr><th scope="row" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:inherit">{{ __("clients.states.{$state}") }}</th><td>{{ $total }}</td></tr>@endforeach</tbody></table>
            </section>
            <section class="cp-card" aria-labelledby="r-new">
                <h3 id="r-new">{{ __('clients.reports.new_by_month') }}</h3>
                <table><tbody>@forelse ($m['new_by_month'] as $month => $total)<tr><th scope="row" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:inherit">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }}</th><td>{{ $total }}</td></tr>@empty<tr><td>{{ __('clients.reports.empty') }}</td></tr>@endforelse</tbody></table>
            </section>
            <section class="cp-card" aria-labelledby="r-tickets">
                <h3 id="r-tickets">{{ __('clients.reports.tickets_by_client') }}</h3>
                <table><tbody>@forelse ($m['tickets_by_client'] as $row)<tr><th scope="row" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:inherit">{{ $row['name'] }}</th><td>{{ $row['total'] }}</td></tr>@empty<tr><td>{{ __('clients.reports.empty') }}</td></tr>@endforelse</tbody></table>
            </section>
        </div>

        <section class="cp-card" aria-labelledby="r-defs">
            <h3 id="r-defs">{{ __('clients.reports.definitions') }}</h3>
            <dl style="color: var(--cp-muted); font-size: .8rem; line-height: 1.5; margin: 0">
                @foreach (['states', 'mrr', 'renewals', 'activity', 'trials', 'cancelled', 'failed', 'tickets', 'mrr_history'] as $key)
                    <dt style="font-weight: 600; margin-top: .4rem">{{ __("clients.reports.defs.{$key}.name") }}</dt>
                    <dd style="margin: 0">{{ __("clients.reports.defs.{$key}.text") }}</dd>
                @endforeach
            </dl>
        </section>
    </div>
</x-filament-panels::page>
