@php
    $s = $profile->summary($org);
    $usage = $profile->usage($org, $s);
@endphp

@include('admin.clients.styles')

<div class="cp">
    <section aria-labelledby="cu-limits">
        <h3 id="cu-limits">{{ __('clients.usage.limits') }}</h3>
        <ul class="cp-list">
            @foreach ($usage as $row)
                @php
                    $level = $row['percent'] === null ? null : ($row['value'] > $row['limit'] ? 'action' : ($row['percent'] >= \App\Modules\Admin\Services\ClientProfile::NEAR_LIMIT_PERCENT ? 'watch' : null));
                @endphp
                <li class="cp-card">
                    <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap">
                        <strong>{{ __('clients.usage.items.'.$row['key']) }}</strong>
                        <span>{{ $row['value'] }} / {{ $row['limit'] ?? '∞' }}@if ($row['percent'] !== null) ({{ $row['percent'] }} %)@endif
                            @if ($level === 'action') · {{ __('clients.usage.exceeded') }} @elseif ($level === 'watch') · {{ __('clients.usage.near') }} @endif</span>
                    </div>
                    @if ($row['percent'] !== null)
                        <div class="cp-bar {{ $level ? 'cp-bar--'.$level : '' }}" role="progressbar" aria-label="{{ __('clients.usage.items.'.$row['key']) }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $row['percent']) }}">
                            <span style="width: {{ min(100, $row['percent']) }}%"></span>
                        </div>
                    @endif
                    <small style="color: var(--cp-muted)">{{ __('clients.usage.scope.'.$row['key']) }}</small>
                </li>
            @endforeach
        </ul>
        <p style="color: var(--cp-muted); margin: .6rem 0 0">{{ __('clients.usage.enforcement') }}</p>
    </section>

    <section aria-labelledby="cu-activity">
        <h3 id="cu-activity">{{ __('clients.usage.activity') }}</h3>
        <dl class="cp-grid">
            <div class="cp-card"><dt>{{ __('clients.overview.users') }}</dt><dd>{{ $s['members'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.practitioners') }}</dt><dd>{{ $s['practitioners'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.overview.locations') }}</dt><dd>{{ $s['locations'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.services') }}</dt><dd>{{ $s['services'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.appointments_total') }}</dt><dd>{{ $s['appointments_total'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.appointments_upcoming') }}</dt><dd>{{ $s['appointments_upcoming'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.appointments_completed') }}</dt><dd>{{ $s['appointments_completed'] }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.messages_sent') }}</dt><dd>{{ $s['messages_sent'] }}<small>{{ __('clients.usage.this_month', ['count' => $s['messages_month']]) }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.conversations') }}</dt><dd>{{ $s['conversations_month'] }}<small>{{ __('clients.usage.month') }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.usage.whatsapp') }}</dt><dd>{{ $s['whatsapp_connected'] ? __('clients.usage.connected') : __('clients.usage.not_connected') }}</dd></div>
        </dl>
        <p style="color: var(--cp-muted); margin: .6rem 0 0">{{ __('clients.usage.not_tracked') }}</p>
    </section>
</div>
