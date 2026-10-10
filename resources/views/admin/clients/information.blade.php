@php
    $members = $profile->members($org);
    $address = (array) $org->billing_address;
    $date = fn ($value) => $value === null ? '—' : \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('d/m/Y H:i');
@endphp

@include('admin.clients.styles')

<div class="cp">
    <section aria-labelledby="ci-business">
        <h3 id="ci-business">{{ __('clients.info.business') }}</h3>
        <dl class="cp-grid">
            <div class="cp-card"><dt>{{ __('clients.info.name') }}</dt><dd>{{ $org->name }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.legal_name') }}</dt><dd>{{ $org->billing_name ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.columns.id') }}</dt><dd>{{ $org->id }}<small>{{ $org->slug }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.sector') }}</dt><dd>{{ \App\Modules\Admin\Filament\Resources\OrganizationResource::sectorLabel($org->sector) ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.vat') }}</dt><dd>{{ $org->billing_vat_number ?? '—' }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.address') }}</dt><dd>
                @if ($address === []) — @else {{ trim(($address['street'] ?? '').' '.($address['number'] ?? '')) }}<small>{{ trim(($address['postal_code'] ?? '').' '.($address['city'] ?? '')) }} {{ $address['country'] ?? '' }}</small> @endif</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.country') }}</dt><dd>{{ $org->country }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.timezone') }}</dt><dd>{{ $org->timezone }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.locale') }}</dt><dd>{{ strtoupper((string) $org->locale) }}</dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.currency') }}</dt><dd>EUR<small>{{ __('clients.info.currency_note') }}</small></dd></div>
            <div class="cp-card"><dt>{{ __('clients.info.account_status') }}</dt><dd>{{ __('admin.statuses.'.$org->status) }}</dd></div>
        </dl>
    </section>

    <section aria-labelledby="ci-members">
        <h3 id="ci-members">{{ __('clients.users.title') }}</h3>
        <p style="color: var(--cp-muted); margin: 0 0 .5rem">{{ __('clients.users.note') }}</p>
        <div class="cp-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('clients.users.email') }}</th>
                        <th scope="col">{{ __('clients.users.phone') }}</th>
                        <th scope="col">{{ __('clients.users.role') }}</th>
                        <th scope="col">{{ __('clients.users.status') }}</th>
                        <th scope="col">{{ __('clients.users.last_login') }}</th>
                        <th scope="col">{{ __('clients.users.two_factor') }}</th>
                        <th scope="col">{{ __('clients.users.joined') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            <td>{{ $member->email }}@if ($member->role === 'owner') <span class="cp-chip">{{ __('clients.users.owner') }}</span> @endif</td>
                            <td>{{ $member->phone ?? '—' }}</td>
                            <td>{{ __('clients.users.roles.'.$member->role) }}</td>
                            <td>{{ __('clients.users.member_status.'.$member->member_status) }}</td>
                            <td>{{ $date($member->last_login_at) }}</td>
                            <td>{{ $member->two_factor ? __('clients.users.on') : __('clients.users.off') }}</td>
                            <td>{{ $date($member->joined_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">{{ __('clients.users.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
