@php
    $cards = [
        ['total', $metrics['total'], null],
        ['active', $metrics['active'], null],
        ['trial', $metrics['trial'], null],
        ['past_due', $metrics['past_due'], $metrics['past_due'] > 0 ? 'danger' : null],
        ['cancelled', $metrics['cancelled'], null],
        ['expired', $metrics['expired'], $metrics['expired'] > 0 ? 'warning' : null],
        ['new_this_month', $metrics['new_this_month'], null],
        ['renewals_soon', $metrics['renewals_soon'], null],
        ['mrr', number_format($metrics['mrr_cents'] / 100, 2, ',', ' ').' €', null],
    ];
@endphp

<style>
    .cm { --cm-border: #e5e7eb; --cm-muted: #6b7280; }
    .dark .cm { --cm-border: #374151; --cm-muted: #9ca3af; }
    .cm-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: .75rem; margin: 0 0 .5rem; }
    .cm-card { border: 1px solid var(--cm-border); border-radius: .75rem; padding: .65rem .9rem; }
    .cm-card--danger { border-color: #dc2626; }
    .cm-card--warning { border-color: #d97706; }
    .cm-card dt { font-size: .72rem; color: var(--cm-muted); }
    .cm-card dd { margin: .15rem 0 0; font-size: 1.35rem; font-weight: 700; }
</style>

<dl class="cm cm-cards" aria-label="{{ __('clients.metrics.label') }}">
    @foreach ($cards as [$key, $value, $tone])
        <div class="cm-card {{ $tone ? 'cm-card--'.$tone : '' }}">
            <dt>{{ __("clients.metrics.{$key}") }}</dt>
            <dd>{{ $value }}</dd>
        </div>
    @endforeach
</dl>
