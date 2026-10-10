@php
    $params = $alert['params'];
    if (isset($params['amount_cents'])) {
        $params['amount'] = $money($params['amount_cents']);
    }
@endphp
<li class="nd-alert nd-alert--{{ $alert['severity'] }}">
    <span class="count" aria-hidden="true">{{ $alert['count'] }}</span>
    <strong>{{ __("dashboard.alerts.{$alert['key']}.title") }}</strong>
    <span class="sev">{{ __("dashboard.severity.{$alert['severity']}") }}</span>
    <p>{{ __("dashboard.alerts.{$alert['key']}.text", ['count' => $alert['count']] + $params) }} <a class="nd-link" href="{{ $u($alert['link']) }}">{{ __('dashboard.view_details') }} →</a></p>
</li>
