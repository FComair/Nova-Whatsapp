@php
    $restricted = $this->isRestricted();
    $d = $restricted ? null : $this->data();
    $space = "\u{202F}";
    $money = fn ($cents) => number_format(((float) $cents) / 100, 2, ',', $space).$space.'€';
    $moneyShort = fn ($cents) => number_format(((float) $cents) / 100, ((float) $cents) / 100 >= 100 ? 0 : 2, ',', $space);
    $int = fn ($v) => number_format((float) $v, 0, ',', $space);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', $space).$space.'%';
    $hours = fn ($h) => $h === null ? '—' : ($h < 1 ? (int) round($h * 60).$space.'min' : number_format((float) $h, 1, ',', $space).$space.'h');
    $rating = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', $space).'/5';
    $fmt = fn (string $format, $v) => match ($format) { 'money' => $money($v), 'rating' => $rating($v), 'percent' => $pct($v), 'hours' => $hours($v), default => $int($v) };
    $delta = function (?array $c, string $format) use ($fmt, $pct): ?string {
        if ($c === null || $c['direction'] === 'none') { return null; }
        $sign = $c['delta'] > 0 ? '+' : '';
        $abs = $format === 'money' ? ($sign.number_format($c['delta'] / 100, 2, ',', "\u{202F}").' €') : ($format === 'rating' ? $sign.number_format($c['delta'], 1, ',', ' ') : $sign.number_format($c['delta'], 0, ',', "\u{202F}"));
        return $abs.($c['percent'] !== null ? ' ('.$sign.number_format($c['percent'], 1, ',', "\u{202F}").' %)' : '');
    };
    $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '■', 'none' => ''];
    $u = fn (array $link) => $this->url($link);
@endphp

<x-filament-panels::page>
    @include('admin.dashboard.styles')

    <div class="nd" id="nd-dashboard">
        @if ($restricted)
            <div class="nd-banner" role="alert"><strong>{{ __('dashboard.restricted.title') }}</strong><p class="nd-muted" style="margin:.25rem 0 0">{{ __('dashboard.restricted.text') }}</p></div>
        @else
            {{-- 1. Contexte et période --}}
            <section class="nd-section nd-filter" aria-label="{{ __('dashboard.period.label') }}">
                <form wire:submit.prevent>{{ $this->form }}</form>
                @if ($d)
                    <div class="nd-context">
                        <span class="nd-chip">{{ __('dashboard.showing', ['from' => \Illuminate\Support\Carbon::parse($d['period']['from'])->format('d/m/Y'), 'until' => \Illuminate\Support\Carbon::parse($d['period']['until'])->format('d/m/Y')]) }}</span>
                        <span>{{ __('dashboard.compared', ['from' => \Illuminate\Support\Carbon::parse($d['period']['previous_from'])->format('d/m/Y'), 'until' => \Illuminate\Support\Carbon::parse($d['period']['previous_until'])->format('d/m/Y')]) }}</span>
                        <span>{{ __('dashboard.updated', ['time' => $this->formatDate($d['generated_at'])]) }}</span>
                    </div>
                @endif
                <nav class="nd-nav" aria-label="{{ __('dashboard.links') }}">
                    <a href="{{ $u(['target' => 'clients']) }}">{{ __('dashboard.nav.clients') }}</a>
                    <a href="{{ $u(['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['active', 'past_due']]]]]) }}">{{ __('dashboard.nav.subscriptions') }}</a>
                    <a href="{{ $u(['target' => 'tickets']) }}">{{ __('dashboard.nav.tickets') }}</a>
                    <a href="{{ $u(['target' => 'client_reports']) }}">{{ __('dashboard.nav.reports') }}</a>
                </nav>
            </section>

            <div class="nd-skel" wire:loading.grid wire:target="filters,refresh" aria-hidden="true"><div class="bar"></div><div class="bar"></div><div class="bar"></div></div>

            @if ($d === null)
                <div class="nd-banner nd-banner--error" role="alert">
                    <strong>{{ __('dashboard.error.title') }}</strong>
                    <p class="nd-muted" style="margin:.25rem 0 .5rem">{{ __('dashboard.error.text') }}</p>
                    <button type="button" class="nd-link" wire:click="$refresh">{{ __('dashboard.error.retry') }}</button>
                </div>
            @else
                @php $bucket = $d['period']['bucket']; $vs = __('dashboard.vs_previous'); @endphp

                {{-- 2. Chiffres clés --}}
                <section class="nd-section" aria-labelledby="nd-kpi-h">
                    <header><h2 id="nd-kpi-h">{{ __('dashboard.sections.kpis') }}</h2><p>{{ __('dashboard.sections.kpis_help') }}</p></header>
                    <dl class="nd-kpis">
                        @foreach ($d['kpis'] as $kpi)
                            @php $cmp = $kpi['comparison']; $text = $delta($cmp, $kpi['format']); @endphp
                            <div class="nd-card nd-kpi">
                                <dt>{{ __("dashboard.kpi.{$kpi['key']}.label") }}</dt>
                                <dd class="nd-value">{{ $kpi['value'] === null ? __('dashboard.unavailable') : $fmt($kpi['format'], $kpi['value']) }}</dd>
                                <dd class="nd-delta">
                                    @if ($kpi['note'] === 'snapshot')
                                        <span>{{ __('dashboard.snapshot') }}</span>
                                    @elseif ($kpi['note'] === 'no_data')
                                        <span>{{ __('dashboard.no_data_period') }}</span>
                                    @elseif ($text === null)
                                        <span>{{ __('dashboard.no_comparison') }}</span>
                                    @else
                                        <span aria-hidden="true">{{ $arrow[$cmp['direction']] }}</span>
                                        <b>{{ $text }}</b>
                                        <span class="sr-only">{{ __("dashboard.direction.{$cmp['direction']}") }}</span>
                                        <span>{{ $vs }} ({{ $fmt($kpi['format'], $cmp['previous']) }})</span>
                                    @endif
                                </dd>
                                <dd class="nd-sub">{{ __("dashboard.kpi.{$kpi['key']}.help") }}</dd>
                                <dd><a href="{{ $u($kpi['link']) }}">{{ __('dashboard.view_details') }} →</a></dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- 3. Revenus et abonnements --}}
                @php $rev = $d['revenue']; @endphp
                <section class="nd-section" aria-labelledby="nd-rev-h">
                    <header><h2 id="nd-rev-h">{{ __('dashboard.sections.revenue') }}</h2><p>{{ __('dashboard.sections.revenue_help') }}</p></header>
                    <div class="nd-grid nd-grid--wide">
                        @include('admin.dashboard.line', ['id' => 'nd-rev', 'title' => __('dashboard.charts.revenue_trend'), 'unit' => __('dashboard.units.eur_vat'), 'series' => $rev['trend'], 'bucket' => $bucket, 'fmt' => $moneyShort, 'legend' => [__('dashboard.legend.current'), __('dashboard.legend.previous')]])
                        @include('admin.dashboard.bars', ['id' => 'nd-mov', 'title' => __('dashboard.charts.movements'), 'unit' => __('dashboard.units.count'), 'series' => $rev['movements'], 'bucket' => $bucket, 'names' => ['subscribed' => __('dashboard.legend.subscribed'), 'ended' => __('dashboard.legend.ended')]])
                    </div>
                    <div class="nd-grid">
                        <section class="nd-card" aria-labelledby="nd-sub-h">
                            <header><h3 id="nd-sub-h">{{ __('dashboard.revenue.subscription_title') }}</h3><span class="nd-sub">{{ __('dashboard.revenue.nova') }}</span></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.revenue.collected') }}</dt><dd>{{ $money($rev['subscription']['collected_cents']) }}<small>{{ __('dashboard.incl_vat') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.collected_ex') }}</dt><dd>{{ $money($rev['subscription']['collected_ex_vat_cents']) }}<small>{{ __('dashboard.ex_vat') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.mrr') }}</dt><dd>{{ $money($rev['mrr_cents']) }}<small>{{ __('dashboard.snapshot') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.arpc') }}</dt><dd>{{ $rev['arpc_cents'] === null ? '—' : $money($rev['arpc_cents']) }}<small>{{ __('dashboard.revenue.arpc_help', ['count' => $rev['paying']]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.outstanding') }}</dt><dd>{{ $money($rev['subscription']['outstanding']['cents']) }}<small>{{ __('dashboard.invoices_count', ['count' => $rev['subscription']['outstanding']['count']]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.failed') }}</dt><dd>{{ $int($rev['failed_payments']['value']) }}<small>{{ $money($rev['failed_cents']) }}</small></dd></div>
                            </dl>
                            <p class="nd-sub">{{ __('dashboard.revenue.mrr_history') }}</p>
                        </section>
                        <section class="nd-card" aria-labelledby="nd-flow-h">
                            <header><h3 id="nd-flow-h">{{ __('dashboard.revenue.flow_title') }}</h3><span class="nd-sub">{{ __('dashboard.units.period') }}</span></header>
                            <dl class="nd-split">
                                @foreach (['new_subscriptions' => 'subscribed', 'upgrades' => 'upgraded', 'downgrades' => 'downgrades', 'cancel_requests' => 'cancel_requests', 'cancellations' => 'cancelled', 'trials_started' => 'trials_started', 'trials_converted' => 'trials_converted'] as $key => $label)
                                    <div><dt>{{ __("dashboard.revenue.{$label}") }}</dt><dd>{{ $int($rev[$key]['value']) }}<small>@if ($rev[$key]['direction'] !== 'none'){{ $arrow[$rev[$key]['direction']] }} {{ $delta($rev[$key], 'int') }} · {{ $int($rev[$key]['previous']) }}@endif</small></dd></div>
                                @endforeach
                                <div><dt>{{ __('dashboard.revenue.conversion') }}</dt><dd>{{ $pct($rev['conversion_percent']) }}<small>{{ __('dashboard.revenue.conversion_help') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.past_due') }}</dt><dd>{{ $int($rev['past_due']) }}<small>{{ __('dashboard.snapshot') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.renewals_7') }}</dt><dd>{{ $int($rev['renewals_7']['count']) }}<small>{{ $money($rev['renewals_7']['mrr_cents']) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.revenue.renewals_30') }}</dt><dd>{{ $int($rev['renewals_30']['count']) }}<small>{{ $money($rev['renewals_30']['mrr_cents']) }}</small></dd></div>
                            </dl>
                        </section>
                        @include('admin.dashboard.donut', ['id' => 'nd-states', 'title' => __('dashboard.charts.by_state'), 'segments' => $rev['by_state'], 'labels' => collect($rev['by_state'])->mapWithKeys(fn ($n, $k) => [$k => __("clients.states.{$k}")])->all(), 'center' => __('dashboard.clients_word')])
                        @include('admin.dashboard.hbars', ['id' => 'nd-plan-rev', 'title' => __('dashboard.charts.revenue_by_plan'), 'unit' => __('dashboard.units.eur_month'), 'rows' => collect($rev['by_plan'])->filter(fn ($r) => $r['mrr_cents'] > 0)->map(fn ($r) => ['label' => $r['label'], 'value' => $r['mrr_cents'], 'text' => $money($r['mrr_cents']).' · '.$r['paying']])->values()->all()])
                        @include('admin.dashboard.hbars', ['id' => 'nd-top', 'title' => __('dashboard.charts.top_clients'), 'unit' => __('dashboard.units.eur_vat'), 'rows' => collect($rev['top_clients'])->map(fn ($r) => ['label' => $r['name'], 'value' => $r['cents'], 'text' => $money($r['cents']), 'url' => $u(['target' => 'client', 'id' => $r['id']])])->all()])
                    </div>
                    <section class="nd-card" aria-labelledby="nd-cm-h">
                        <header><h3 id="nd-cm-h">{{ __('dashboard.revenue.client_money_title') }}</h3><span class="nd-sub">{{ __('dashboard.revenue.client_money_help') }}</span></header>
                        <dl class="nd-split">
                            <div><dt>{{ __('dashboard.revenue.appointments') }}</dt><dd>{{ $money($rev['client_money']['appointments_cents']) }}<small>{{ __('dashboard.revenue.appointments_help', ['count' => $rev['client_money']['appointments_count']]) }}</small></dd></div>
                            <div><dt>{{ __('dashboard.revenue.tips') }}</dt><dd>{{ $money($rev['client_money']['tips_cents']) }}<small>{{ __('dashboard.revenue.tips_help', ['count' => $rev['client_money']['tips_count']]) }}</small></dd></div>
                            <div><dt>{{ __('dashboard.revenue.client_total') }}</dt><dd>{{ $money($rev['client_money']['total_cents']) }}<small>{{ __('dashboard.revenue.client_total_help') }}</small></dd></div>
                        </dl>
                    </section>
                </section>

                {{-- 4. Clients --}}
                @php $cl = $d['clients']; @endphp
                <section class="nd-section" aria-labelledby="nd-cl-h">
                    <header><h2 id="nd-cl-h">{{ __('dashboard.sections.clients') }}</h2><p>{{ __('dashboard.sections.clients_help') }}</p></header>
                    <div class="nd-grid nd-grid--wide">
                        @include('admin.dashboard.line', ['id' => 'nd-new', 'title' => __('dashboard.charts.new_clients'), 'unit' => __('dashboard.units.count'), 'series' => $cl['new_series'], 'bucket' => $bucket, 'fmt' => $int, 'legend' => [__('dashboard.legend.current'), __('dashboard.legend.previous')]])
                        @include('admin.dashboard.line', ['id' => 'nd-active', 'title' => __('dashboard.charts.active_clients'), 'unit' => __('dashboard.units.count'), 'series' => $cl['active_series'], 'bucket' => $bucket, 'fmt' => $int, 'legend' => [__('dashboard.legend.current'), __('dashboard.legend.previous')]])
                    </div>
                    <div class="nd-grid">
                        <section class="nd-card" aria-labelledby="nd-rates-h">
                            <header><h3 id="nd-rates-h">{{ __('dashboard.clients.rates_title') }}</h3></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.clients.total') }}</dt><dd>{{ $int($cl['total']) }}<small>{{ __('dashboard.snapshot') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.clients.conversion') }}</dt><dd>{{ $pct($cl['conversion_percent']) }}<small>{{ __('dashboard.revenue.conversion_help') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.clients.churn') }}</dt><dd>{{ $pct($cl['churn_percent']) }}<small>{{ __('dashboard.clients.churn_help', ['count' => $cl['churned']]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.clients.repeat') }}</dt><dd>{{ $pct($cl['repeat_percent']) }}<small>{{ __('dashboard.clients.repeat_help', ['count' => $cl['repeat_base']]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.clients.dormant') }}</dt><dd>{{ $int($cl['dormant']) }}<small>{{ __('dashboard.clients.dormant_help', ['days' => \App\Modules\Admin\Dashboard\DashboardMetrics::DORMANT_DAYS]) }}</small></dd></div>
                            </dl>
                            <p class="nd-sub">{{ __('dashboard.clients.cohort_unavailable') }}</p>
                        </section>
                        @include('admin.dashboard.donut', ['id' => 'nd-cl-state', 'title' => __('dashboard.charts.by_state'), 'segments' => $cl['by_state'], 'labels' => collect($cl['by_state'])->mapWithKeys(fn ($n, $k) => [$k => __("clients.states.{$k}")])->all(), 'center' => __('dashboard.clients_word')])
                        @include('admin.dashboard.hbars', ['id' => 'nd-cl-plan', 'title' => __('dashboard.charts.by_plan'), 'rows' => collect($cl['by_plan'])->map(fn ($r) => ['label' => $r['label'], 'value' => $r['total'], 'text' => (string) $r['total']])->all()])
                        @include('admin.dashboard.hbars', ['id' => 'nd-cl-country', 'title' => __('dashboard.charts.by_country'), 'rows' => collect($cl['by_country'])->map(fn ($r) => ['label' => $r['label'], 'value' => $r['total'], 'text' => (string) $r['total']])->all()])
                        @include('admin.dashboard.hbars', ['id' => 'nd-cl-top', 'title' => __('dashboard.charts.most_active'), 'unit' => __('dashboard.units.activity'), 'rows' => collect($cl['most_active'])->map(fn ($r) => ['label' => $r['name'], 'value' => $r['score'], 'text' => $r['bookings'].' / '.$r['messages'], 'url' => $u(['target' => 'client', 'id' => $r['id']])])->all()])
                        <section class="nd-card" aria-labelledby="nd-dormant-h">
                            <header><h3 id="nd-dormant-h">{{ __('dashboard.charts.dormant') }}</h3><a class="nd-link" href="{{ $u(['target' => 'clients', 'query' => ['filters' => ['activity' => ['value' => 'inactive_30']]]]) }}">{{ __('dashboard.view_all') }} →</a></header>
                            @if ($cl['dormant_list'] === [])
                                <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
                            @else
                                <ul class="nd-hbars">
                                    @foreach ($cl['dormant_list'] as $row)
                                        <li style="grid-template-columns: 1fr auto"><a class="nd-link" href="{{ $u(['target' => 'client', 'id' => $row['id']]) }}" style="font-size:inherit">{{ $row['name'] }}</a><span class="num">{{ $row['last_activity_at'] ? \Illuminate\Support\Carbon::parse($row['last_activity_at'])->format('d/m/Y') : __('dashboard.never') }}</span></li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    </div>
                </section>

                {{-- 5. Usage du produit --}}
                @php $us = $d['usage']; @endphp
                <section class="nd-section" aria-labelledby="nd-us-h">
                    <header><h2 id="nd-us-h">{{ __('dashboard.sections.usage') }}</h2><p>{{ __('dashboard.sections.usage_help') }}</p></header>
                    <div class="nd-grid">
                        <section class="nd-card" aria-labelledby="nd-us-k">
                            <header><h3 id="nd-us-k">{{ __('dashboard.usage.appointments_title') }}</h3></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.usage.created') }}</dt><dd>{{ $int($us['created']['value']) }}<small>@if ($us['created']['direction'] !== 'none'){{ $arrow[$us['created']['direction']] }} {{ $delta($us['created'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.upcoming') }}</dt><dd>{{ $int($us['upcoming']) }}<small>{{ __('dashboard.snapshot') }}</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.completed') }}</dt><dd>{{ $int($us['completed']['value']) }}<small>@if ($us['completed']['direction'] !== 'none'){{ $arrow[$us['completed']['direction']] }} {{ $delta($us['completed'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.cancellation') }}</dt><dd>{{ $pct($us['cancellation_percent']) }}<small>{{ __('dashboard.previous_value', ['value' => $pct($us['cancellation_previous'])]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.no_show') }}</dt><dd>{{ $pct($us['no_show_percent']) }}<small>{{ __('dashboard.previous_value', ['value' => $pct($us['no_show_previous'])]) }}</small></dd></div>
                            </dl>
                        </section>
                        <section class="nd-card" aria-labelledby="nd-us-w">
                            <header><h3 id="nd-us-w">{{ __('dashboard.usage.whatsapp_title') }}</h3></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.usage.messages_sent') }}</dt><dd>{{ $int($us['messages_sent']['value']) }}<small>@if ($us['messages_sent']['direction'] !== 'none'){{ $arrow[$us['messages_sent']['direction']] }} {{ $delta($us['messages_sent'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.messages_received') }}</dt><dd>{{ $int($us['messages_received']) }}</dd></div>
                                <div><dt>{{ __('dashboard.usage.conversations') }}</dt><dd>{{ $int($us['conversations']['value']) }}<small>@if ($us['conversations']['direction'] !== 'none'){{ $arrow[$us['conversations']['direction']] }} {{ $delta($us['conversations'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.messages_failed') }}</dt><dd>{{ $int($us['messages_failed']) }}</dd></div>
                            </dl>
                        </section>
                        <section class="nd-card" aria-labelledby="nd-us-c">
                            <header><h3 id="nd-us-c">{{ __('dashboard.usage.catalog_title') }}</h3><span class="nd-sub">{{ __('dashboard.snapshot') }}</span></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.usage.practitioners') }}</dt><dd>{{ $int($us['practitioners']) }}</dd></div>
                                <div><dt>{{ __('dashboard.usage.locations') }}</dt><dd>{{ $int($us['locations']) }}</dd></div>
                                <div><dt>{{ __('dashboard.usage.services') }}</dt><dd>{{ $int($us['services']) }}</dd></div>
                                <div><dt>{{ __('dashboard.usage.calendars') }}</dt><dd>{{ $int($us['calendars']['active']) }}<small>{{ __('dashboard.usage.calendars_error', ['count' => $us['calendars']['error']]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.usage.loyalty') }}</dt><dd>{{ $int($us['loyalty']['members']) }}<small>{{ __('dashboard.usage.loyalty_programs', ['count' => $us['loyalty']['programs']]) }}</small></dd></div>
                            </dl>
                        </section>
                    </div>
                    <div class="nd-grid nd-grid--wide">
                        @include('admin.dashboard.line', ['id' => 'nd-bk', 'title' => __('dashboard.charts.appointments'), 'unit' => __('dashboard.units.count'), 'series' => $us['appointments_series'], 'bucket' => $bucket, 'fmt' => $int, 'legend' => [__('dashboard.legend.current'), __('dashboard.legend.previous')]])
                        @include('admin.dashboard.bars', ['id' => 'nd-msg', 'title' => __('dashboard.charts.messages'), 'unit' => __('dashboard.units.count'), 'series' => $us['messages_series'], 'bucket' => $bucket, 'names' => ['out' => __('dashboard.legend.out'), 'in' => __('dashboard.legend.in')]])
                        @include('admin.dashboard.donut', ['id' => 'nd-bk-status', 'title' => __('dashboard.charts.appointment_status'), 'segments' => $us['status_distribution'], 'labels' => collect($us['status_distribution'])->mapWithKeys(fn ($n, $k) => [$k => __("dashboard.booking_status.{$k}")])->all(), 'center' => __('dashboard.appointments_word')])
                        <section class="nd-card" aria-labelledby="nd-heat-h">
                            <header><h3 id="nd-heat-h">{{ __('dashboard.charts.heatmap') }}</h3><span class="nd-sub">{{ __('dashboard.units.heatmap') }}</span></header>
                            @if ($us['heatmap_max'] <= 1 && array_sum(array_map('array_sum', $us['heatmap'])) === 0)
                                <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
                            @else
                                <div class="nd-heat" role="table" aria-label="{{ __('dashboard.charts.heatmap') }}">
                                    <span></span>@for ($h = 0; $h < 24; $h++)<span class="hour" role="columnheader">{{ $h % 3 === 0 ? $h : '' }}</span>@endfor
                                    @foreach ($us['heatmap'] as $day => $hoursRow)
                                        <span class="day" role="rowheader">{{ __("dashboard.days.{$day}") }}</span>
                                        @foreach ($hoursRow as $h => $n)
                                            <span class="cell" role="cell" title="{{ __("dashboard.days.{$day}") }} {{ $h }}h : {{ $n }}" aria-label="{{ __("dashboard.days.{$day}") }} {{ $h }}h : {{ $n }}" style="opacity: {{ $n === 0 ? .06 : round(.2 + .8 * $n / $us['heatmap_max'], 2) }}"></span>
                                        @endforeach
                                    @endforeach
                                </div>
                                <p class="nd-sub">{{ __('dashboard.heatmap_help', ['max' => $us['heatmap_max']]) }}</p>
                            @endif
                        </section>
                        @include('admin.dashboard.hbars', ['id' => 'nd-adopt', 'title' => __('dashboard.charts.adoption'), 'unit' => __('dashboard.adoption_base', ['count' => $us['adoption_base']]), 'rows' => collect($us['adoption'])->map(fn ($n, $k) => ['label' => __("dashboard.adoption.{$k}"), 'value' => $n, 'text' => $n.' / '.$us['adoption_base']])->values()->all()])
                    </div>
                </section>

                {{-- 6. Support --}}
                @php $sp = $d['support']; @endphp
                <section class="nd-section" aria-labelledby="nd-sp-h">
                    <header><h2 id="nd-sp-h">{{ __('dashboard.sections.support') }}</h2><p>{{ __('dashboard.sections.support_help') }}</p></header>
                    <div class="nd-grid">
                        <section class="nd-card" aria-labelledby="nd-sp-k">
                            <header><h3 id="nd-sp-k">{{ __('dashboard.support.queue_title') }}</h3><a class="nd-link" href="{{ $u(['target' => 'tickets']) }}">{{ __('dashboard.view_all') }} →</a></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.support.open') }}</dt><dd>{{ $int($sp['open']) }}</dd></div>
                                <div><dt>{{ __('dashboard.support.unassigned') }}</dt><dd><a class="nd-link" href="{{ $u(['target' => 'tickets', 'query' => ['tab' => 'unassigned']]) }}" style="font-size:inherit">{{ $int($sp['unassigned']) }}</a></dd></div>
                                <div><dt>{{ __('dashboard.support.urgent') }}</dt><dd><a class="nd-link" href="{{ $u(['target' => 'tickets', 'query' => ['tab' => 'urgent']]) }}" style="font-size:inherit">{{ $int($sp['urgent']) }}</a></dd></div>
                                <div><dt>{{ __('dashboard.support.waiting_client') }}</dt><dd>{{ $int($sp['waiting_on_client']) }}</dd></div>
                                <div><dt>{{ __('dashboard.support.waiting_internal') }}</dt><dd>{{ $int($sp['waiting_internal']) }}</dd></div>
                                <div><dt>{{ __('dashboard.support.overdue') }}</dt><dd>{{ $int($sp['overdue']) }}<small>{{ __('dashboard.support.at_risk', ['count' => $sp['at_risk']]) }}</small></dd></div>
                            </dl>
                        </section>
                        <section class="nd-card" aria-labelledby="nd-sp-p">
                            <header><h3 id="nd-sp-p">{{ __('dashboard.support.period_title') }}</h3><a class="nd-link" href="{{ $u(['target' => 'support_reports']) }}">{{ __('dashboard.support.reports') }} →</a></header>
                            <dl class="nd-split">
                                <div><dt>{{ __('dashboard.support.created') }}</dt><dd>{{ $int($sp['created']['value']) }}<small>@if ($sp['created']['direction'] !== 'none'){{ $arrow[$sp['created']['direction']] }} {{ $delta($sp['created'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.support.resolved') }}</dt><dd>{{ $int($sp['resolved']['value']) }}<small>@if ($sp['resolved']['direction'] !== 'none'){{ $arrow[$sp['resolved']['direction']] }} {{ $delta($sp['resolved'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.support.reopened') }}</dt><dd>{{ $int($sp['reopened']['value']) }}<small>@if ($sp['reopened']['direction'] !== 'none'){{ $arrow[$sp['reopened']['direction']] }} {{ $delta($sp['reopened'], 'int') }}@endif</small></dd></div>
                                <div><dt>{{ __('dashboard.support.first_response') }}</dt><dd>{{ $hours($sp['first_response_hours']) }}<small>{{ __('dashboard.previous_value', ['value' => $hours($sp['first_response_previous'])]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.support.resolution') }}</dt><dd>{{ $hours($sp['resolution_hours']) }}<small>{{ __('dashboard.previous_value', ['value' => $hours($sp['resolution_previous'])]) }}</small></dd></div>
                                <div><dt>{{ __('dashboard.support.on_time') }}</dt><dd>{{ $pct($sp['first_response_on_time']) }}<small>{{ __('dashboard.support.on_time_help') }}</small></dd></div>
                            </dl>
                        </section>
                        @include('admin.dashboard.donut', ['id' => 'nd-sp-status', 'title' => __('dashboard.charts.ticket_status'), 'segments' => $sp['by_status'], 'labels' => collect($sp['by_status'])->mapWithKeys(fn ($n, $k) => [$k => __("tickets.statuses.{$k}")])->all(), 'center' => __('dashboard.tickets_word')])
                        @include('admin.dashboard.donut', ['id' => 'nd-sp-prio', 'title' => __('dashboard.charts.ticket_priority'), 'segments' => $sp['by_priority'], 'labels' => collect($sp['by_priority'])->mapWithKeys(fn ($n, $k) => [$k => __("tickets.priorities.{$k}")])->all(), 'center' => __('dashboard.tickets_word')])
                    </div>
                    <div class="nd-grid nd-grid--wide">
                        @include('admin.dashboard.line', ['id' => 'nd-sp-vol', 'title' => __('dashboard.charts.ticket_volume'), 'unit' => __('dashboard.units.count'), 'series' => $sp['volume_series'], 'bucket' => $bucket, 'fmt' => $int, 'legend' => [__('dashboard.legend.current'), __('dashboard.legend.previous')]])
                        @include('admin.dashboard.line', ['id' => 'nd-sp-rate', 'title' => __('dashboard.charts.rating_trend'), 'unit' => __('dashboard.units.rating'), 'series' => ['current' => $sp['rating_series'], 'previous' => null], 'bucket' => $bucket, 'fmt' => fn ($v) => number_format((float) $v, 1, ',', ' '), 'legend' => [__('dashboard.legend.rating')]])
                        @include('admin.dashboard.hbars', ['id' => 'nd-sp-agent', 'title' => __('dashboard.charts.agent_workload'), 'unit' => __('dashboard.units.agent'), 'rows' => collect($sp['by_agent'])->map(fn ($r) => ['label' => $r['email'] ?? __('tickets.unassigned'), 'value' => $r['total'], 'text' => $r['open'].' / '.$r['total']])->all()])
                    </div>
                </section>

                {{-- 7. Alertes --}}
                <section class="nd-section" aria-labelledby="nd-al-h">
                    <header><h2 id="nd-al-h">{{ __('dashboard.sections.alerts') }}</h2><p>{{ __('dashboard.sections.alerts_help') }}</p></header>
                    @if ($d['alerts'] === [])
                        <p class="nd-empty">{{ __('dashboard.alerts.none') }}</p>
                    @else
                        @php $alerts = $d['alerts']; $top = array_slice($alerts, 0, 8); $rest = array_slice($alerts, 8); @endphp
                        <ul class="nd-alerts">
                            @foreach ($top as $alert)
                                @include('admin.dashboard.alert', ['alert' => $alert, 'u' => $u, 'money' => $money])
                            @endforeach
                        </ul>
                        @if ($rest !== [])
                            <details>
                                <summary>{{ __('dashboard.alerts.more', ['count' => count($rest)]) }}</summary>
                                <ul class="nd-alerts" style="margin-top:.5rem">@foreach ($rest as $alert)@include('admin.dashboard.alert', ['alert' => $alert, 'u' => $u, 'money' => $money])@endforeach</ul>
                            </details>
                        @endif
                    @endif
                </section>

                {{-- 8. Actions rapides et activité récente --}}
                <div class="nd-grid nd-grid--wide">
                    <section class="nd-section nd-actions-section" aria-labelledby="nd-qa-h">
                        <header><h2 id="nd-qa-h">{{ __('dashboard.sections.actions') }}</h2></header>
                        <div class="nd-actions">
                            <a href="{{ $u(['target' => 'clients']) }}">{{ __('dashboard.actions.clients') }}</a>
                            <a href="{{ $u(['target' => 'clients', 'query' => ['filters' => ['subscription' => ['values' => ['active', 'past_due']]]]]) }}">{{ __('dashboard.actions.subscriptions') }}</a>
                            <a href="{{ $u(['target' => 'clients', 'query' => ['filters' => ['payment' => ['value' => 'failed']]]]) }}">{{ __('dashboard.actions.failed_payments') }}</a>
                            <a href="{{ $u(['target' => 'tickets']) }}">{{ __('dashboard.actions.tickets') }}</a>
                            <a href="{{ $u(['target' => 'client_reports']) }}">{{ __('dashboard.actions.reports') }}</a>
                            <a href="{{ $u(['target' => 'support_reports']) }}">{{ __('dashboard.actions.support_reports') }}</a>
                            <a href="{{ $u(['target' => 'team']) }}">{{ __('dashboard.actions.team') }}</a>
                            <a href="{{ $u(['target' => 'settings']) }}">{{ __('dashboard.actions.settings') }}</a>
                            <a href="{{ $u(['target' => 'feedback']) }}">{{ __('dashboard.actions.feedback') }}</a>
                            <a href="{{ $u(['target' => 'invoices']) }}">{{ __('dashboard.actions.invoices') }}</a>
                        </div>
                    </section>
                    <section class="nd-section" aria-labelledby="nd-ac-h">
                        <header><h2 id="nd-ac-h">{{ __('dashboard.sections.activity') }}</h2><p>{{ __('dashboard.sections.activity_help') }}</p></header>
                        @if ($d['activity'] === [])
                            <p class="nd-empty">{{ __('dashboard.no_data') }}</p>
                        @else
                            <ul class="nd-feed">
                                @foreach ($d['activity'] as $event)
                                    @php
                                        $link = $event['org_id'] ? ['target' => 'client', 'id' => $event['org_id']] : null;
                                        $ticketLink = $event['ticket_id'] ? ['target' => 'ticket', 'id' => $event['ticket_id']] : null;
                                    @endphp
                                    <li>
                                        <time datetime="{{ $event['at'] }}">{{ $this->formatDate($event['at']) }}</time>
                                        <span>
                                            <span class="type">{{ __("dashboard.activity.{$event['type']}") }}</span>
                                            @if ($event['client'])— @if ($link)<a class="nd-link" href="{{ $u($link) }}" style="font-size:inherit">{{ $event['client'] }}</a>@else{{ $event['client'] }}@endif @endif
                                            @if ($event['reference'])<span class="nd-muted">· @if ($ticketLink)<a class="nd-link" href="{{ $u($ticketLink) }}" style="font-size:inherit">{{ $event['reference'] }}</a>@else{{ $event['reference'] }}@endif</span>@endif
                                            @if ($event['detail'])<span class="nd-muted">· {{ $event['detail'] }}</span>@endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                <footer class="nd-sub nd-muted" style="font-size:.75rem">
                    {{ __('dashboard.footer_unavailable') }}
                </footer>
            @endif
        @endif
    </div>
</x-filament-panels::page>
