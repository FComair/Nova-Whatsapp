<style>
    .cp { --cp-border: #e5e7eb; --cp-muted: #6b7280; --cp-soft: #f9fafb; display: grid; gap: 1.1rem; font-size: .9rem; }
    .dark .cp { --cp-border: #374151; --cp-muted: #9ca3af; --cp-soft: #1f2937; }
    .cp h3 { font-size: .95rem; font-weight: 600; margin: 0 0 .5rem; }
    .cp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: .75rem; margin: 0; }
    .cp-card { border: 1px solid var(--cp-border); border-radius: .75rem; padding: .7rem .9rem; }
    .cp-card dt { font-size: .72rem; color: var(--cp-muted); text-transform: uppercase; letter-spacing: .03em; }
    .cp-card dd { margin: .15rem 0 0; font-weight: 600; overflow-wrap: anywhere; }
    .cp-card small { display: block; font-weight: 400; color: var(--cp-muted); margin-top: .1rem; }
    .cp-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
    .cp-flag { border: 1px solid var(--cp-border); border-inline-start-width: 4px; border-radius: .5rem; padding: .55rem .8rem; }
    .cp-flag--action { border-inline-start-color: #dc2626; }
    .cp-flag--watch { border-inline-start-color: #d97706; }
    .cp-flag--info { border-inline-start-color: #2563eb; }
    .cp-flag strong { display: block; }
    .cp-flag span { color: var(--cp-muted); }
    .cp table { width: 100%; border-collapse: collapse; }
    .cp th, .cp td { text-align: start; padding: .4rem .5rem; border-bottom: 1px solid var(--cp-border); vertical-align: top; }
    .cp th { font-size: .72rem; color: var(--cp-muted); text-transform: uppercase; letter-spacing: .03em; }
    .cp-scroll { overflow-x: auto; }
    .cp-bar { height: .55rem; border-radius: 999px; background: var(--cp-soft); border: 1px solid var(--cp-border); overflow: hidden; }
    .cp-bar > span { display: block; height: 100%; background: #16a34a; }
    .cp-bar--watch > span { background: #d97706; }
    .cp-bar--action > span { background: #dc2626; }
    .cp-chip { display: inline-flex; gap: .25rem; align-items: center; border: 1px solid var(--cp-border); border-radius: 999px; padding: .1rem .6rem; font-size: .8rem; }
    .cp-note { border: 1px solid var(--cp-border); border-radius: .6rem; padding: .6rem .8rem; }
    .cp-note header { font-size: .75rem; color: var(--cp-muted); margin-bottom: .25rem; }
    .cp-note p { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
