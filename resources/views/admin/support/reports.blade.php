@php
    $m = $this->metrics();
    $hours = fn (?float $value): string => $value === null ? '—' : ($value >= 48 ? number_format($value / 24, 1, ',', ' ').' '.__('tickets.reports.days_unit') : number_format($value, 1, ',', ' ').' '.__('tickets.reports.hours_unit'));
    $percent = fn (?float $value): string => $value === null ? '—' : number_format($value, 1, ',', ' ').' %';
    $cards = [
        ['open', $m['open'], null, null],
        ['unassigned', $m['unassigned'], $m['unassigned'] > 0 ? 'warning' : null, null],
        ['waiting_on_client', $m['waiting_on_client'], null, null],
        ['overdue', $m['overdue'], $m['overdue'] > 0 ? 'danger' : null, null],
        ['at_risk', $m['at_risk'], $m['at_risk'] > 0 ? 'warning' : null, null],
        ['resolved_today', $m['resolved_today'], null, null],
        ['resolved_week', $m['resolved_week'], null, null],
        ['resolved_month', $m['resolved_month'], null, null],
        ['created', $m['created'], null, 'period'],
        ['first_response', $hours($m['first_response_hours']), null, 'period'],
        ['first_response_on_time', $percent($m['first_response_on_time']), null, 'period'],
        ['resolution', $hours($m['resolution_hours']), null, 'period'],
        ['reopened', $m['reopened'], null, 'period'],
    ];
@endphp

<x-filament-panels::page>
    <style>
        .nr { --nr-border: #e5e7eb; --nr-muted: #6b7280; display: grid; gap: 1.25rem; }
        .dark .nr { --nr-border: #374151; --nr-muted: #9ca3af; }
        .nr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; margin: 0; }
        .nr-card { border: 1px solid var(--nr-border); border-radius: .75rem; padding: .85rem 1rem; }
        .nr-card--danger { border-color: #dc2626; }
        .nr-card--warning { border-color: #d97706; }
        .nr-cards .nr-card dt { font-size: .75rem; color: var(--nr-muted); }
        .nr-cards .nr-card dd { margin: .2rem 0 0; font-size: 1.5rem; font-weight: 700; }
        .nr-cards .nr-card small { display: block; font-size: .7rem; color: var(--nr-muted); margin-top: .15rem; }
        .nr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); gap: 1rem; }
        .nr table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .nr th, .nr td { text-align: start; padding: .35rem .25rem; border-bottom: 1px solid var(--nr-border); }
        .nr td:last-child, .nr th:last-child { text-align: end; }
        .nr h2 { font-size: .95rem; font-weight: 600; margin: 0 0 .5rem; }
        .nr-defs { color: var(--nr-muted); font-size: .8rem; line-height: 1.5; }
        .nr-defs dt { font-weight: 600; margin-top: .5rem; }
        .nr-defs dd { margin: 0; }
    </style>

    <div class="nr">
        <form aria-label="{{ __('tickets.reports.filters') }}">{{ $this->form }}</form>

        <p class="nr-defs" style="margin:0">{{ __('tickets.reports.period', ['from' => \Illuminate\Support\Carbon::parse($m['period']['from'])->format('d/m/Y'), 'until' => \Illuminate\Support\Carbon::parse($m['period']['until'])->format('d/m/Y')]) }}</p>

        <dl class="nr-cards">
            @foreach ($cards as [$key, $value, $tone, $scope])
                <div class="nr-card {{ $tone ? 'nr-card--'.$tone : '' }}">
                    <dt>{{ __("tickets.reports.cards.{$key}") }}</dt>
                    <dd>{{ $value }}</dd>
                    <small>{{ $scope === 'period' ? __('tickets.reports.scope_period') : __('tickets.reports.scope_now') }}</small>
                </div>
            @endforeach
        </dl>

        <div class="nr-grid">
            <section class="nr-card" aria-labelledby="r-status"><h2 id="r-status">{{ __('tickets.reports.by_status') }}</h2>
                <table><tbody>@foreach ($m['by_status'] as $key => $count)<tr><th scope="row" style="font-weight:400">{{ __("tickets.statuses.{$key}") }}</th><td>{{ $count }}</td></tr>@endforeach</tbody></table></section>
            <section class="nr-card" aria-labelledby="r-priority"><h2 id="r-priority">{{ __('tickets.reports.by_priority') }}</h2>
                <table><tbody>@foreach ($m['by_priority'] as $key => $count)<tr><th scope="row" style="font-weight:400">{{ __("tickets.priorities.{$key}") }}</th><td>{{ $count }}</td></tr>@endforeach</tbody></table></section>
            <section class="nr-card" aria-labelledby="r-category"><h2 id="r-category">{{ __('tickets.reports.by_category') }}</h2>
                <table><tbody>@foreach ($m['by_category'] as $key => $count)<tr><th scope="row" style="font-weight:400">{{ __("tickets.categories.{$key}") }}</th><td>{{ $count }}</td></tr>@endforeach</tbody></table></section>
            <section class="nr-card" aria-labelledby="r-agent"><h2 id="r-agent">{{ __('tickets.reports.by_agent') }}</h2>
                <table>
                    <thead><tr><th scope="col">{{ __('tickets.columns.assignee') }}</th><th scope="col">{{ __('tickets.reports.cards.open') }}</th><th scope="col">{{ __('tickets.reports.total') }}</th></tr></thead>
                    <tbody>
                        @forelse ($m['by_agent'] as $row)
                            <tr><th scope="row" style="font-weight:400">{{ $row['email'] ?? __('tickets.unassigned') }}</th><td>{{ $row['open'] }}</td><td>{{ $row['total'] }}</td></tr>
                        @empty
                            <tr><td colspan="3">{{ __('tickets.reports.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table></section>
        </div>

        <section class="nr-card" aria-labelledby="r-defs">
            <h2 id="r-defs">{{ __('tickets.reports.definitions') }}</h2>
            <dl class="nr-defs">
                @foreach (['open', 'unassigned', 'waiting_on_client', 'overdue', 'at_risk', 'resolved', 'created', 'first_response', 'first_response_on_time', 'resolution', 'reopened', 'breakdowns'] as $key)
                    <dt>{{ __("tickets.reports.defs.{$key}.name") }}</dt>
                    <dd>{{ __("tickets.reports.defs.{$key}.text") }}</dd>
                @endforeach
            </dl>
        </section>
    </div>
</x-filament-panels::page>
