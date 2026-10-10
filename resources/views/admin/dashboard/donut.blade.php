{{-- Anneau : $id, $title, $segments [clé => valeur], $labels [clé => libellé], $center (texte au centre) --}}
@php
    $arcs = \App\Modules\Admin\Dashboard\ChartSvg::donut($segments);
    $total = array_sum($segments);
    $colors = ['var(--nd-c1)', 'var(--nd-c2)', 'var(--nd-c3)', 'var(--nd-c4)', 'var(--nd-c5)', 'var(--nd-c7)', 'var(--nd-c8)', 'var(--nd-c6)'];
@endphp
<figure class="nd-card" aria-labelledby="{{ $id }}-t" style="margin:0">
    <header><h3 id="{{ $id }}-t">{{ $title }}</h3><span class="nd-sub">{{ $total }}</span></header>
    @if ($total === 0)
        <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
    @else
        <div class="nd-donut-wrap">
            <svg class="nd-donut" viewBox="0 0 42 42" role="img" aria-labelledby="{{ $id }}-t" focusable="false">
                <circle class="track" cx="21" cy="21" r="15.9155"/>
                @foreach ($arcs as $i => $arc)
                    <circle cx="21" cy="21" r="15.9155" stroke="{{ $colors[$i % count($colors)] }}" stroke-dasharray="{{ $arc['dash'] }}" stroke-dashoffset="{{ $arc['offset'] }}"><title>{{ $labels[$arc['key']] ?? $arc['key'] }} : {{ $arc['value'] }} ({{ number_format($arc['percent'], 1, ',', ' ') }} %)</title></circle>
                @endforeach
                <text x="21" y="22.5">{{ $total }}</text>
                <text class="small" x="21" y="26.5">{{ $center ?? '' }}</text>
            </svg>
            <ul class="nd-legend" style="display:grid; gap:.35rem">
                @foreach ($arcs as $i => $arc)
                    <li><span class="nd-swatch" style="background: {{ $colors[$i % count($colors)] }}"></span>{{ $labels[$arc['key']] ?? $arc['key'] }} <strong style="color: var(--nd-text)">{{ $arc['value'] }}</strong> ({{ number_format($arc['percent'], 1, ',', ' ') }} %)</li>
                @endforeach
            </ul>
        </div>
    @endif
</figure>
