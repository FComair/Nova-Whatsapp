{{-- Barres horizontales : $id, $title, $rows list<[label, value, text?, url?]>, $unit (facultatif) --}}
@php $max = max(1, ...array_map(fn ($r) => (float) $r['value'], $rows ?: [['value' => 0]])); @endphp
<section class="nd-card" aria-labelledby="{{ $id }}-t">
    <header><h3 id="{{ $id }}-t">{{ $title }}</h3>@isset($unit)<span class="nd-sub">{{ $unit }}</span>@endisset</header>
    @if ($rows === [])
        <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
    @else
        <ul class="nd-hbars">
            @foreach ($rows as $row)
                <li>
                    <span class="label" title="{{ $row['label'] }}">@if (! empty($row['url']))<a class="nd-link" href="{{ $row['url'] }}" style="font-size:inherit">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif</span>
                    <span class="track" role="img" aria-label="{{ $row['label'] }} : {{ $row['text'] ?? $row['value'] }}"><span class="fill" style="width: {{ round($row['value'] / $max * 100, 1) }}%"></span></span>
                    <span class="num">{{ $row['text'] ?? $row['value'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
