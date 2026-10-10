@php
    $sub = $profile->subscription($org);
    $history = $profile->history($org);
    $money = fn (?int $cents) => $cents === null ? '—' : number_format($cents / 100, 2, ',', ' ').' '.$sub['currency'];
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->format('d/m/Y');
    $dateTime = fn ($value) => $value?->timezone(config('app.timezone'))->format('d/m/Y H:i');
    $label = function (string $type): string {
        $key = 'clients.events.'.str_replace('.', '_', $type);
        $text = __($key);

        return $text === $key ? $type : $text;
    };
@endphp

@include('admin.clients.styles')

<div class="cp">
    <section aria-labelledby="cs-current">
        <h3 id="cs-current">{{ __('clients.subscription.current') }}</h3>
        <dl class="cp-grid">
            <div class="cp-card"><dt>{{ __('clients.columns.plan') }}</dt><dd>{{ $sub['plan'] ?? '—' }}<small>{{ $sub['pending_plan'] ? __('clients.subscription.pending_plan', ['plan' => $sub['pending_plan']]) : '' }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.subscription') }}</dt><dd>{{ __('clients.states.'.$sub['state']) }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.interval') }}</dt><dd>{{ __('clients.subscription.monthly') }}<small>{{ __('clients.subscription.interval_note') }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.price') }}</dt><dd>{{ $money($sub['price_monthly_cents']) }}<small>{{ $sub['vat_rate'] !== null ? __('clients.subscription.vat', ['rate' => $sub['vat_rate']]) : __('clients.subscription.vat_unknown') }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.started') }}</dt><dd>{{ $date($sub['started_at']) ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.period') }}</dt><dd>{{ $sub['period_start'] ? $date($sub['period_start']).' → '.$date($sub['period_end']) : '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.next_billing') }}</dt><dd>{{ $date($sub['next_billing']) ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.trial') }}</dt><dd>{{ $date($sub['trial_start']) }} → {{ $date($sub['trial_end']) ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.auto_renewal') }}</dt><dd>{{ $sub['auto_renewal'] ? __('clients.subscription.on') : ($sub['cancel_at'] ? __('clients.subscription.ends_on', ['date' => $date($sub['cancel_at'])]) : __('clients.subscription.off')) }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.payment_method') }}</dt><dd>
                @if ($sub['payment_method']['status'] === 'none') {{ __('clients.payment.none') }}
                @else {{ strtoupper((string) $sub['payment_method']['brand']) }} •••• {{ $sub['payment_method']['last4'] }}<small>{{ __('clients.payment.'.$sub['payment_method']['status']) }} · {{ $sub['payment_method']['expires'] }}</small>
                @endif</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.amount_due') }}</dt><dd>{{ $money($sub['amount_due_cents']) }}<small>{{ $sub['failed_attempts'] > 0 ? __('clients.subscription.failed_attempts', ['count' => $sub['failed_attempts']]) : '' }}{{ $sub['next_attempt_at'] ? ' · '.__('clients.subscription.next_attempt', ['date' => $dateTime($sub['next_attempt_at'])]) : '' }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.discount') }}</dt><dd>{{ __('clients.subscription.no_discount') }}<small>{{ __('clients.subscription.discount_note') }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.limits') }}</dt><dd>
                @foreach ($sub['limits'] as $key => $value) {{ __('clients.usage.items.'.$key) }}: {{ $value ?? '∞' }}@if (! $loop->last) · @endif @endforeach</dd></div>
            <div class="cp-card"><dt>{{ __('clients.subscription.provider') }}</dt><dd>{{ $sub['provider'] ?? '—' }}<small>{{ $sub['subscription_id'] }}</small></dd></div>
        </dl>
        <p style="color: var(--cp-muted); margin: .6rem 0 0">{{ __('clients.subscription.actions_note') }}</p>
    </section>

    <section aria-labelledby="cs-history">
        <h3 id="cs-history">{{ __('clients.subscription.history') }}</h3>
        <div class="cp-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('clients.activity.when') }}</th>
                        <th scope="col">{{ __('clients.activity.event') }}</th>
                        <th scope="col">{{ __('clients.activity.detail') }}</th>
                        <th scope="col">{{ __('clients.activity.actor') }}</th>
                        <th scope="col">{{ __('clients.subscription.reference') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $event)
                        <tr>
                            <td>{{ $dateTime($event['at']) }}</td>
                            <td>{{ $label($event['type']) }}</td>
                            <td>{{ $event['detail'] }}</td>
                            <td>{{ __('clients.activity.actors.'.$event['actor']) === 'clients.activity.actors.'.$event['actor'] ? $event['actor'] : __('clients.activity.actors.'.$event['actor']) }}</td>
                            <td>{{ $event['reference'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('clients.subscription.history_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
