{{-- Aperçu en direct d'une formule (docs/40) : même contenu que la page tarifs publique (PlanCatalog::present), rendu à partir de la saisie. --}}
@php($card = $preview['card'])

<style>
    .np-card { position: relative; border: 1px solid color-mix(in srgb, currentColor 18%, transparent); border-radius: 1rem; padding: 1.25rem; background: color-mix(in srgb, currentColor 3%, transparent); }
    .np-card.np-recommended { border-color: #d97706; box-shadow: 0 0 0 1px #d97706; }
    .np-badge { position: absolute; top: -.7rem; right: 1rem; border-radius: 999px; background: #f59e0b; color: #1c1100; font-size: .72rem; font-weight: 700; padding: .15rem .6rem; }
    .np-name { margin: 0; font-size: 1.15rem; font-weight: 700; }
    .np-desc { margin: .35rem 0 0; font-size: .875rem; opacity: .8; }
    .np-price { margin: .9rem 0 0; font-size: 1.9rem; font-weight: 700; line-height: 1.1; }
    .np-price small { font-size: .9rem; font-weight: 500; opacity: .75; }
    .np-note { margin: .25rem 0 0; font-size: .78rem; opacity: .75; }
    .np-list { list-style: none; margin: 1rem 0 0; padding: 0; display: grid; gap: .45rem; font-size: .875rem; }
    .np-list li { display: flex; gap: .5rem; align-items: baseline; }
    .np-list .np-mark { flex: none; width: 1rem; font-weight: 700; }
    .np-list .np-included .np-mark { color: #16a34a; }
    .np-list .np-limited .np-mark { color: #d97706; }
    .np-list .np-unavailable { opacity: .55; text-decoration: line-through; }
    .np-list .np-unavailable .np-mark { color: #dc2626; text-decoration: none; }
    .np-value { opacity: .8; font-size: .8rem; }
    .np-cta { margin-top: 1.1rem; display: block; width: 100%; text-align: center; border-radius: .6rem; padding: .55rem .9rem; background: #d97706; color: #fff; font-weight: 600; font-size: .9rem; }
    .np-state { margin: .85rem 0 0; font-size: .8rem; }
    .np-warn { color: #b45309; }
    .np-ok { color: #15803d; }
</style>

<article class="np-card {{ $card['recommended'] ? 'np-recommended' : '' }}" aria-label="{{ __('plans.form.preview') }}">
    @if ($card['recommended'])
        <span class="np-badge">{{ __('plans.preview.recommended') }}</span>
    @endif

    <h3 class="np-name">{{ $card['name'] !== '' ? $card['name'] : __('plans.preview.empty_name') }}</h3>

    @if ($card['description'])
        <p class="np-desc">{{ $card['description'] }}</p>
    @endif

    <p class="np-price">
        {{ $preview['price'] }}
        @if ($preview['period'])
            <small>/ {{ $preview['period'] }}</small>
        @endif
    </p>

    @if ($preview['tax'])
        <p class="np-note">{{ $preview['tax'] }}</p>
    @endif
    @if ($preview['setup_fee'])
        <p class="np-note">{{ __('plans.preview.setup_fee', ['price' => $preview['setup_fee']]) }}</p>
    @endif
    @if ($card['trial_days'])
        <p class="np-note">{{ __('plans.preview.trial', ['days' => $card['trial_days']]) }}</p>
    @endif

    <ul class="np-list">
        @foreach (['practitioners', 'locations', 'conversations', 'appointments'] as $key)
            @php($limit = $card['limits'][$key])
            <li class="np-included">
                <span class="np-mark" aria-hidden="true">✓</span>
                <span>{{ __('plans.limit_labels.'.$key) }} : <strong>{{ $limit === null ? '∞' : $limit }}</strong></span>
            </li>
        @endforeach

        @foreach ($card['features'] as $feature)
            <li class="np-{{ $feature['state'] }}">
                <span class="np-mark" aria-hidden="true">{{ $feature['state'] === 'included' ? '✓' : ($feature['state'] === 'limited' ? '◐' : '✕') }}</span>
                <span>
                    {{ $feature['label'] }}
                    @if ($feature['value'])
                        <span class="np-value">— {{ $feature['value'] }}</span>
                    @endif
                    <span class="sr-only"> ({{ __('plans.feature_states.'.$feature['state']) }})</span>
                </span>
            </li>
        @endforeach
    </ul>

    <span class="np-cta">{{ $preview['cta_label'] }}</span>
</article>

@if ($preview['listed'])
    <p class="np-state np-warn">{{ __('plans.preview.not_listed', ['why' => __('plans.preview.'.$preview['listed'])]) }}</p>
@else
    <p class="np-state np-ok">{{ __('plans.preview.listed') }}</p>
@endif
