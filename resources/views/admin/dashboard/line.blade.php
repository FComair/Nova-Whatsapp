{{-- Courbe : $id, $title, $unit, $series ['current' => [...], 'previous' => [...]|null], $bucket, $fmt (callable), $legend [courante, précédente] --}}
@php
    $chart = \App\Modules\Admin\Dashboard\ChartSvg::line($series['current'], $series['previous'] ?? null);
    $label = fn (string $key) => $bucket === 'day' ? \Illuminate\Support\Carbon::parse($key)->format('d/m') : \Illuminate\Support\Carbon::parse($key)->format('m/Y');
    $prevValues = array_values($series['previous'] ?? []);
@endphp
<figure class="nd-card" aria-labelledby="{{ $id }}-t" style="margin:0">
    <header><h3 id="{{ $id }}-t">{{ $title }}</h3><span class="nd-sub">{{ $unit }}</span></header>
    @if ($chart['empty'])
        <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
    @else
        <svg class="nd-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-labelledby="{{ $id }}-t {{ $id }}-d" focusable="false">
            <desc id="{{ $id }}-d">{{ __('dashboard.chart_desc', ['title' => $title, 'max' => $fmt($chart['max'])]) }}</desc>
            @foreach ($chart['ticks'] as $tick)
                <line class="grid" x1="44" x2="{{ $chart['width'] - 12 }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"/>
                <text x="40" y="{{ $tick['y'] + 3 }}" text-anchor="end">{{ $fmt($tick['value']) }}</text>
            @endforeach
            @foreach ($chart['labels'] as $l)
                <text x="{{ $l['x'] }}" y="{{ $chart['height'] - 8 }}" text-anchor="middle">{{ $label($l['key']) }}</text>
            @endforeach
            @if ($chart['previous'])<path class="line prev" d="{{ $chart['previous'] }}"/>@endif
            <path class="area" d="{{ $chart['current']['area'] }}"/>
            <path class="line" d="{{ $chart['current']['line'] }}"/>
            @if (count($chart['current']['points']) <= 40)
                @foreach ($chart['current']['points'] as $pt)
                    <circle class="pt" cx="{{ $pt['x'] }}" cy="{{ $pt['y'] }}" r="3" tabindex="0"><title>{{ $label($pt['key']) }} : {{ $fmt($pt['value'] ?? 0) }}</title></circle>
                @endforeach
            @endif
        </svg>
        <ul class="nd-legend">
            <li><span class="nd-swatch" style="background: var(--nd-primary)"></span>{{ $legend[0] }}</li>
            @if ($chart['previous'])<li><span class="nd-swatch" style="background: var(--nd-prev)"></span>{{ $legend[1] ?? '' }}</li>@endif
        </ul>
        <details>
            <summary>{{ __('dashboard.data_table') }}</summary>
            <div class="nd-table-wrap"><table>
                <caption class="nd-muted" style="text-align:start">{{ $title }} ({{ $unit }})</caption>
                <thead><tr><th scope="col">{{ __('dashboard.col.date') }}</th><th scope="col" class="num">{{ $legend[0] }}</th>@if ($series['previous'] ?? null)<th scope="col" class="num">{{ $legend[1] ?? '' }}</th>@endif</tr></thead>
                <tbody>@foreach ($series['current'] as $key => $value)<tr><th scope="row">{{ $label($key) }}</th><td class="num">{{ $fmt($value ?? 0) }}</td>@if ($series['previous'] ?? null)<td class="num">{{ isset($prevValues[$loop->index]) ? $fmt($prevValues[$loop->index] ?? 0) : '—' }}</td>@endif</tr>@endforeach</tbody>
            </table></div>
        </details>
    @endif
</figure>
