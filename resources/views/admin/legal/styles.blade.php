<style>
    .nl { display: grid; gap: 1.1rem; font-size: .9rem; --nl-border: #e5e7eb; --nl-muted: #5b6573; --nl-soft: #f6f7f9; --nl-primary: #b45309; --nl-ok: #15803d; --nl-warn: #b45309; }
    .dark .nl { --nl-border: #333338; --nl-muted: #a1a1aa; --nl-soft: #1f1f23; --nl-primary: #fbbf24; --nl-ok: #4ade80; --nl-warn: #fbbf24; }
    .nl-back { display: inline-flex; align-items: center; gap: .4rem; min-height: 2.75rem; padding: .35rem .8rem; border: 1px solid var(--nl-border); border-radius: 999px; font-weight: 600; text-decoration: none; color: inherit; width: fit-content; }
    .nl-back:hover { border-color: var(--nl-primary); }
    .nl a:focus-visible, .nl button:focus-visible, .nl summary:focus-visible { outline: 3px solid var(--nl-primary); outline-offset: 2px; }
    .nl-grid { display: grid; gap: .9rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); }
    .nl-card { border: 1px solid var(--nl-border); border-radius: .85rem; padding: 1rem 1.1rem; display: grid; gap: .6rem; align-content: start; }
    .nl-card h2, .nl-card h3 { margin: 0; font-size: 1rem; font-weight: 700; }
    .nl-meta { display: grid; gap: .4rem 1.2rem; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); margin: 0; }
    .nl-meta dt { font-size: .72rem; color: var(--nl-muted); }
    .nl-meta dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
    .nl-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .nl-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.5rem; padding: .3rem .95rem; border-radius: 999px; border: 1px solid var(--nl-border); font-weight: 600; text-decoration: none; color: inherit; background: transparent; cursor: pointer; }
    .nl-btn:hover { border-color: var(--nl-primary); }
    .nl-btn--primary { background: var(--nl-primary); color: #1c1100; border-color: transparent; }
    .dark .nl-btn--primary { color: #1c1100; }
    .nl-badge { display: inline-flex; align-items: center; gap: .3rem; border-radius: 999px; padding: .1rem .6rem; font-size: .75rem; font-weight: 700; border: 1px solid var(--nl-border); width: fit-content; }
    .nl-badge--current { color: var(--nl-ok); border-color: currentColor; }
    .nl-badge--scheduled { color: var(--nl-warn); border-color: currentColor; }
    .nl-badge--archived { color: var(--nl-muted); }
    .nl-note { color: var(--nl-muted); margin: 0; font-size: .8rem; }
    .nl-empty { border: 1px dashed var(--nl-border); border-radius: .75rem; padding: 1rem; color: var(--nl-muted); text-align: center; }
    .nl-table-wrap { overflow-x: auto; }
    .nl table { width: 100%; border-collapse: collapse; }
    .nl th, .nl td { text-align: start; padding: .5rem .6rem; border-bottom: 1px solid var(--nl-border); vertical-align: top; }
    .nl th { font-size: .72rem; color: var(--nl-muted); font-weight: 600; }
    @media (max-width: 640px) { .nl-back { width: 100%; justify-content: center; } .nl-actions .nl-btn { flex: 1 1 100%; } }
</style>
