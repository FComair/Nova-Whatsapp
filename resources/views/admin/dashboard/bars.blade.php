{{-- Barres groupées : $id, $title, $unit, $series [nom => [clé => valeur]], $bucket, $names [nom => libellé] --}}
@php
    $chart = \App\Modules\Admin\Dashboard\ChartSvg::bars($series);
    $label = fn (string $key) => $bucket === 'day' ? \Illuminate\Support\Carbon::parse($key)->format('d/m') : \Illuminate\Support\Carbon::parse($key)->format('m/Y');
    $int = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $keys = array_keys((array) reset($series));
    $seriesNames = array_keys($series);
@endphp
<figure class="nd-card" aria-labelledby="{{ $id }}-t" style="margin:0">
    <header><h3 id="{{ $id }}-t">{{ $title }}</h3><span class="nd-sub">{{ $unit }}</span></header>
    @if ($chart['empty'])
        <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
    @else
        <svg class="nd-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-labelledby="{{ $id }}-t" focusable="false">
            @foreach ($chart['ticks'] as $tick)
                <line class="grid" x1="44" x2="{{ $chart['width'] - 12 }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"/>
                <text x="40" y="{{ $tick['y'] + 3 }}" text-anchor="end">{{ $int($tick['value']) }}</text>
            @endforeach
            @foreach ($chart['labels'] as $l)<text x="{{ $l['x'] }}" y="{{ $chart['height'] - 8 }}" text-anchor="middle">{{ $label($l['key']) }}</text>@endforeach
            @foreach ($chart['bars'] as $bar)
                @if ($bar['value'] > 0)<rect class="bar-{{ array_search($bar['series'], $seriesNames, true) }}" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}" rx="2" tabindex="0"><title>{{ $names[$bar['series']] ?? $bar['series'] }}, {{ $label($bar['key']) }} : {{ $int($bar['value']) }}</title></rect>@endif
            @endforeach
        </svg>
        <ul class="nd-legend">@foreach ($seriesNames as $i => $name)<li><span class="nd-swatch" style="background: var(--nd-c{{ [2, 4, 3][$i] ?? 1 }})"></span>{{ $names[$name] ?? $name }}</li>@endforeach</ul>
        <details>
            <summary>{{ __('dashboard.data_table') }}</summary>
            <div class="nd-table-wrap"><table>
                <caption class="nd-muted" style="text-align:start">{{ $title }}</caption>
                <thead><tr><th scope="col">{{ __('dashboard.col.date') }}</th>@foreach ($seriesNames as $name)<th scope="col" class="num">{{ $names[$name] ?? $name }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($keys as $key)<tr><th scope="row">{{ $label($key) }}</th>@foreach ($series as $values)<td class="num">{{ $int($values[$key] ?? 0) }}</td>@endforeach</tr>@endforeach</tbody>
            </table></div>
        </details>
    @endif
</figure>
