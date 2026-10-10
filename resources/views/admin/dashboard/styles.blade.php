<style>
    .nd { --nd-bg: #fff; --nd-soft: #f6f7f9; --nd-border: #e5e7eb; --nd-text: #111827; --nd-muted: #5b6573; --nd-primary: #b45309; --nd-primary-soft: #fef3c7; --nd-prev: #9ca3af;
          --nd-ok: #15803d; --nd-warn: #b45309; --nd-danger: #b91c1c; --nd-info: #1d4ed8;
          --nd-c1: #b45309; --nd-c2: #0f766e; --nd-c3: #4338ca; --nd-c4: #be185d; --nd-c5: #4d7c0f; --nd-c6: #6b7280; --nd-c7: #0369a1; --nd-c8: #a16207;
          display: grid; gap: 1.5rem; color: var(--nd-text); font-size: .9rem; }
    .dark .nd { --nd-bg: #18181b; --nd-soft: #1f1f23; --nd-border: #333338; --nd-text: #f4f4f5; --nd-muted: #a1a1aa; --nd-primary: #fbbf24; --nd-primary-soft: #3a2a08; --nd-prev: #71717a;
          --nd-ok: #4ade80; --nd-warn: #fbbf24; --nd-danger: #f87171; --nd-info: #60a5fa;
          --nd-c1: #fbbf24; --nd-c2: #2dd4bf; --nd-c3: #a5b4fc; --nd-c4: #f472b6; --nd-c5: #a3e635; --nd-c6: #a1a1aa; --nd-c7: #38bdf8; --nd-c8: #facc15; }
    .nd .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .nd h2 { font-size: 1.05rem; font-weight: 700; margin: 0; }
    .nd h3 { font-size: .9rem; font-weight: 600; margin: 0; }
    .nd-section { display: grid; gap: .75rem; }
    .nd-section > header p { margin: .15rem 0 0; color: var(--nd-muted); font-size: .8rem; }
    .nd-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); }
    .nd-grid--wide { grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr)); }
    .nd-kpis { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12.5rem), 1fr)); margin: 0; }
    .nd-card { background: var(--nd-bg); border: 1px solid var(--nd-border); border-radius: .85rem; padding: .85rem 1rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); display: grid; gap: .5rem; align-content: start; min-width: 0; }
    .nd-card > header { display: flex; justify-content: space-between; gap: .5rem; align-items: baseline; }
    .nd-card .nd-sub { color: var(--nd-muted); font-size: .75rem; margin: 0; }
    .nd-kpi dt { font-size: .75rem; color: var(--nd-muted); }
    .nd-kpi dd { margin: 0; }
    .nd-kpi .nd-value { font-size: 1.55rem; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; }
    .nd-kpi .nd-delta { font-size: .75rem; color: var(--nd-muted); display: flex; gap: .3rem; align-items: center; flex-wrap: wrap; }
    .nd-kpi .nd-delta b { color: var(--nd-text); font-weight: 600; }
    .nd-kpi a, .nd-link { color: var(--nd-primary); font-size: .75rem; text-decoration: none; }
    .nd-kpi a:hover, .nd-link:hover { text-decoration: underline; }
    .nd a:focus-visible, .nd button:focus-visible, .nd summary:focus-visible, .nd [tabindex]:focus-visible { outline: 3px solid var(--nd-primary); outline-offset: 2px; border-radius: .25rem; }
    .nd-chip { display: inline-flex; align-items: center; gap: .3rem; border: 1px solid var(--nd-border); border-radius: 999px; padding: .1rem .6rem; font-size: .75rem; color: var(--nd-muted); background: var(--nd-soft); }
    .nd-split { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); margin: 0; }
    .nd-split div { border: 1px solid var(--nd-border); border-radius: .6rem; padding: .5rem .7rem; background: var(--nd-soft); }
    .nd-split dt { font-size: .7rem; color: var(--nd-muted); }
    .nd-split dd { margin: .1rem 0 0; font-weight: 700; font-size: 1.05rem; font-variant-numeric: tabular-nums; }
    .nd-split small { display: block; color: var(--nd-muted); font-weight: 400; font-size: .7rem; }
    .nd-chart { width: 100%; height: auto; display: block; }
    .nd-chart text { fill: var(--nd-muted); font-size: 10px; }
    .nd-chart .grid { stroke: var(--nd-border); stroke-width: 1; }
    .nd-chart .area { fill: var(--nd-primary); opacity: .12; }
    .nd-chart .line { fill: none; stroke: var(--nd-primary); stroke-width: 2.2; stroke-linejoin: round; }
    .nd-chart .line.prev { stroke: var(--nd-prev); stroke-width: 1.6; stroke-dasharray: 4 3; }
    .nd-chart circle.pt { fill: var(--nd-bg); stroke: var(--nd-primary); stroke-width: 1.5; }
    .nd-chart rect.bar-0 { fill: var(--nd-c2); } .nd-chart rect.bar-1 { fill: var(--nd-c4); } .nd-chart rect.bar-2 { fill: var(--nd-c3); }
    .nd-legend { display: flex; flex-wrap: wrap; gap: .3rem 1rem; list-style: none; margin: 0; padding: 0; font-size: .75rem; color: var(--nd-muted); }
    .nd-legend li { display: inline-flex; align-items: center; gap: .35rem; }
    .nd-swatch { width: .8rem; height: .8rem; border-radius: .2rem; display: inline-block; flex: none; }
    .nd-donut-wrap { display: grid; grid-template-columns: 8.5rem 1fr; gap: 1rem; align-items: center; }
    .nd-donut { width: 100%; height: auto; transform: rotate(0deg); }
    .nd-donut circle { fill: none; stroke-width: 6; }
    .nd-donut .track { stroke: var(--nd-soft); }
    .nd-donut text { fill: var(--nd-text); font-size: 6px; font-weight: 700; text-anchor: middle; }
    .nd-donut text.small { fill: var(--nd-muted); font-size: 2.6px; font-weight: 400; }
    .nd-hbars { list-style: none; margin: 0; padding: 0; display: grid; gap: .45rem; }
    .nd-hbars li { display: grid; grid-template-columns: minmax(5rem, 9rem) 1fr auto; gap: .5rem; align-items: center; font-size: .8rem; }
    .nd-hbars .track { height: .6rem; border-radius: 999px; background: var(--nd-soft); border: 1px solid var(--nd-border); overflow: hidden; }
    .nd-hbars .fill { display: block; height: 100%; background: var(--nd-primary); }
    .nd-hbars .label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nd-hbars .num { font-variant-numeric: tabular-nums; color: var(--nd-muted); }
    .nd-heat { display: grid; grid-template-columns: 2.2rem repeat(24, minmax(0, 1fr)); gap: 2px; font-size: .62rem; color: var(--nd-muted); }
    .nd-heat .cell { aspect-ratio: 1 / 1; border-radius: 2px; background: var(--nd-primary); min-height: .6rem; }
    .nd-heat .hour { text-align: center; }
    .nd-heat .day { align-self: center; }
    .nd-table-wrap { overflow-x: auto; }
    .nd table { width: 100%; border-collapse: collapse; font-size: .8rem; }
    .nd th, .nd td { text-align: start; padding: .3rem .45rem; border-bottom: 1px solid var(--nd-border); }
    .nd th { color: var(--nd-muted); font-weight: 600; font-size: .72rem; }
    .nd td.num, .nd th.num { text-align: end; font-variant-numeric: tabular-nums; }
    .nd details { font-size: .78rem; }
    .nd details summary { cursor: pointer; color: var(--nd-muted); }
    .nd-alerts { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr)); }
    .nd-alert { display: grid; grid-template-columns: auto 1fr auto; gap: .2rem .7rem; align-items: start; border: 1px solid var(--nd-border); border-inline-start-width: 4px; border-radius: .6rem; padding: .6rem .8rem; background: var(--nd-bg); }
    .nd-alert--danger { border-inline-start-color: var(--nd-danger); }
    .nd-alert--warning { border-inline-start-color: var(--nd-warn); }
    .nd-alert--info { border-inline-start-color: var(--nd-info); }
    .nd-alert .count { font-size: 1.25rem; font-weight: 700; font-variant-numeric: tabular-nums; grid-row: span 2; }
    .nd-alert strong { font-weight: 600; }
    .nd-alert p { margin: 0; color: var(--nd-muted); font-size: .78rem; grid-column: 2; }
    .nd-alert .sev { font-size: .68rem; color: var(--nd-muted); border: 1px solid var(--nd-border); border-radius: 999px; padding: 0 .45rem; white-space: nowrap; }
    .nd-actions { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); }
    .nd-actions a { display: block; border: 1px solid var(--nd-border); border-radius: .6rem; padding: .55rem .8rem; color: var(--nd-text); text-decoration: none; background: var(--nd-bg); font-weight: 500; }
    .nd-actions a:hover { border-color: var(--nd-primary); }
    .nd-feed { list-style: none; margin: 0; padding: 0; display: grid; gap: .1rem; }
    .nd-feed li { display: grid; grid-template-columns: 8.5rem 1fr; gap: .1rem .7rem; padding: .4rem 0; border-bottom: 1px solid var(--nd-border); }
    .nd-feed time { color: var(--nd-muted); font-size: .75rem; font-variant-numeric: tabular-nums; }
    .nd-feed .type { font-weight: 600; }
    .nd-muted { color: var(--nd-muted); }
    .nd-empty { color: var(--nd-muted); border: 1px dashed var(--nd-border); border-radius: .6rem; padding: .8rem; text-align: center; font-size: .8rem; }
    .nd-banner { border: 1px solid var(--nd-border); border-radius: .75rem; padding: .8rem 1rem; background: var(--nd-soft); }
    .nd-banner--error { border-color: var(--nd-danger); }
    .nd-context { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; color: var(--nd-muted); font-size: .8rem; }
    .nd-nav { display: flex; flex-wrap: wrap; gap: .4rem; }
    .nd-nav a { border: 1px solid var(--nd-border); border-radius: 999px; padding: .15rem .7rem; font-size: .78rem; color: var(--nd-text); text-decoration: none; background: var(--nd-bg); }
    .nd-nav a:hover { border-color: var(--nd-primary); }
    .nd-skel { display: none; gap: .75rem; }
    [wire\:loading].nd-skel, .nd-skel[wire\:loading] { display: none; }
    .nd-skel .bar { height: 5rem; border-radius: .85rem; background: linear-gradient(90deg, var(--nd-soft), var(--nd-border), var(--nd-soft)); background-size: 200% 100%; animation: nd-shimmer 1.2s linear infinite; }
    @keyframes nd-shimmer { to { background-position: -200% 0; } }
    @media (prefers-reduced-motion: reduce) { .nd-skel .bar { animation: none; } }
    @media (max-width: 640px) {
        .nd-donut-wrap { grid-template-columns: 1fr; }
        .nd-feed li { grid-template-columns: 1fr; }
        .nd-hbars li { grid-template-columns: minmax(4rem, 7rem) 1fr auto; }
        .nd-heat { font-size: .5rem; }
    }
    @media print {
        .fi-sidebar, .fi-topbar, .fi-header-actions-ctn, .nd-actions-section, .nd-filter { display: none !important; }
        .nd-card, .nd-alert { break-inside: avoid; box-shadow: none; }
    }
</style>
