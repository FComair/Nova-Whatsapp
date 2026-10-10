@php
    $s = $profile->summary($org);
    $health = $profile->health($org, $s);
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' '.$s['currency'];
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->format('d/m/Y');
    $levelIcon = ['action' => 'heroicon-m-exclamation-triangle', 'watch' => 'heroicon-m-eye', 'info' => 'heroicon-m-information-circle'];
@endphp

@include('admin.clients.styles')

<div class="cp">
    <section aria-labelledby="cp-health">
        <h3 id="cp-health">{{ __('clients.health.title') }}</h3>
        @if ($health === [])
            <p class="cp-flag cp-flag--info"><strong>{{ __('clients.health.none') }}</strong><span>{{ __('clients.health.none_text') }}</span></p>
        @else
            <ul class="cp-list">
                @foreach ($health as $flag)
                    <li class="cp-flag cp-flag--{{ $flag['level'] }}">
                        <strong><x-filament::icon :icon="$levelIcon[$flag['level']]" style="width:1rem;height:1rem;display:inline;vertical-align:-2px" /> {{ __('clients.health.'.$flag['key'].'.title') }} <span style="font-weight:400">({{ __('clients.health.levels.'.$flag['level']) }})</span></strong>
                        <span>{{ __('clients.health.'.$flag['key'].'.text', array_merge($flag['params'], isset($flag['params']['item']) ? ['item' => __('clients.usage.items.'.$flag['params']['item'])] : [])) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="cp-summary">
        <h3 id="cp-summary">{{ __('clients.overview.title') }}</h3>
        <dl class="cp-grid">
            <div class="cp-card"><dt>{{ __('clients.overview.since') }}</dt><dd>{{ $date($s['since']) }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.plan') }}</dt><dd>{{ $s['plan'] ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.subscription') }}</dt><dd>{{ __('clients.states.'.$s['state']) }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.renewal') }}</dt><dd>{{ $date($s['renewal_at']) ?? '—' }}<small>{{ $s['renewal_at'] ? __('clients.renewal_kind.'.($s['state'] === 'trial' ? 'trial' : 'renewal')) : '' }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.mrr') }}</dt><dd>{{ $s['mrr_cents'] > 0 ? $money($s['mrr_cents']) : '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.payment_method') }}</dt><dd>
                @if ($s['payment_method']['status'] === 'none') {{ __('clients.payment.none') }}
                @else {{ strtoupper((string) $s['payment_method']['brand']) }} •••• {{ $s['payment_method']['last4'] }}<small>{{ __('clients.payment.'.$s['payment_method']['status']) }} · {{ $s['payment_method']['expires'] }}</small>
                @endif</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.users') }}</dt><dd>{{ $s['members'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.locations') }}</dt><dd>{{ $s['locations'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.practitioners') }}</dt><dd>{{ $s['practitioners'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.appointments') }}</dt><dd>{{ $s['appointments_total'] }}<small>{{ __('clients.overview.upcoming', ['count' => $s['appointments_upcoming']]) }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.activity') }}</dt><dd>{{ $s['last_activity'] ? $s['last_activity']->diffForHumans() : __('clients.never') }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.tickets') }}</dt><dd>{{ $s['open_tickets'] }}<small>{{ __('clients.overview.urgent', ['count' => $s['urgent_tickets']]) }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.outstanding') }}</dt><dd>{{ $money($s['outstanding_cents']) }}</dd></div>
        </dl>
    </section>
</div>
