{{-- Bouton « Retour » (docs/36) et protection des modifications non enregistrées, sur tous les écrans du Super Admin. --}}
@php($back = \App\Modules\Admin\Support\AdminBack::for(request()->route()))

<style>
    .nova-back { display: inline-flex; align-items: center; gap: .4rem; min-height: 2.75rem; max-width: 100%; margin-bottom: 1rem; padding: .35rem .9rem; border: 1px solid color-mix(in srgb, currentColor 22%, transparent); border-radius: 999px; font-size: .875rem; font-weight: 600; text-decoration: none; color: inherit; transition: border-color .15s, background-color .15s; }
    .nova-back:hover { border-color: var(--primary-600, #d97706); background: color-mix(in srgb, var(--primary-600, #d97706) 8%, transparent); }
    .nova-back:active { background: color-mix(in srgb, var(--primary-600, #d97706) 16%, transparent); }
    .nova-back:focus-visible, .nova-unsaved button:focus-visible { outline: 3px solid var(--primary-600, #d97706); outline-offset: 2px; }
    .nova-back svg { width: 1rem; height: 1rem; flex: none; }
    .nova-back span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 640px) { .nova-back { display: flex; width: 100%; justify-content: center; } }
    @media print { .nova-back { display: none; } }
    .nova-unsaved { margin: auto; width: calc(100% - 2rem); max-width: 28rem; padding: 1.5rem; border: 1px solid color-mix(in srgb, currentColor 18%, transparent); border-radius: 1rem; background: #fff; color: #18181b; box-shadow: 0 20px 50px rgba(0, 0, 0, .25); }
    .dark .nova-unsaved { background: #18181b; color: #fafafa; }
    .nova-unsaved::backdrop { background: rgba(15, 23, 42, .55); }
    .nova-unsaved h2 { margin: 0; font-size: 1.05rem; font-weight: 700; }
    .nova-unsaved div { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .6rem; margin-top: 1.25rem; }
    .nova-unsaved button { min-height: 2.75rem; padding: .4rem 1rem; border-radius: 999px; border: 1px solid color-mix(in srgb, currentColor 25%, transparent); background: transparent; color: inherit; font-weight: 600; cursor: pointer; }
    .nova-unsaved button[value="save"] { background: var(--primary-600, #d97706); border-color: transparent; color: #1c1100; }
    .nova-unsaved button[value="leave"] { background: #b91c1c; border-color: transparent; color: #fff; }
    @media (max-width: 640px) { .nova-unsaved button { flex: 1 1 100%; } }
</style>

@if ($back)
    <nav aria-label="{{ __('admin.back.aria') }}" class="nova-back-nav">
        <a class="nova-back" href="{{ $back['url'] }}" data-nova-back>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6" /></svg>
            <span>{{ $back['label'] }}</span>
        </a>
    </nav>
@endif

<dialog class="nova-unsaved" id="nova-unsaved" aria-labelledby="nova-unsaved-title" data-testid="unsaved-dialog">
    <form method="dialog">
        <h2 id="nova-unsaved-title">{{ __('admin.back.unsaved.message') }}</h2>
        <div>
            <button type="submit" value="save">{{ __('admin.back.unsaved.save') }}</button>
            <button type="submit" value="leave">{{ __('admin.back.unsaved.leave') }}</button>
            <button type="submit" value="cancel" autofocus>{{ __('admin.back.unsaved.cancel') }}</button>
        </div>
    </form>
</dialog>

<script>
    (function () {
        if (window.__novaBack) { return; }
        window.__novaBack = true;

        var snapshots = new WeakMap();
        var dirty = new Set();
        var quiet = false;
        var dialog = document.getElementById('nova-unsaved');

        function watched(form) {
            return form && !form.closest('[data-unsaved-ignore], [role="search"], .fi-ta, .fi-modal, .fi-sidebar, .fi-topbar, .fi-simple-main')
                && form.id !== 'nova-unsaved-form' && !form.matches('[method="dialog"]');
        }
        function serialise(form) {
            return JSON.stringify(Array.from(new FormData(form).entries()).map(function (e) {
                return [e[0], e[1] instanceof File ? e[1].name + ':' + e[1].size : e[1]];
            }));
        }
        function formOf(event) {
            var form = event.target && event.target.closest ? event.target.closest('form') : null;
            return watched(form) ? form : null;
        }
        function remember(event) {
            var form = formOf(event);
            if (form && !snapshots.has(form)) { snapshots.set(form, serialise(form)); }
        }
        function evaluate(event) {
            var form = formOf(event);
            if (!form) { return; }
            var before = snapshots.get(form);
            if (before === undefined || before !== serialise(form)) { dirty.add(form); } else { dirty.delete(form); }
        }
        function hasChanges() {
            var found = false;
            dirty.forEach(function (form) { if (!form.isConnected) { dirty.delete(form); } else { found = true; } });
            return found;
        }
        function ask() {
            return new Promise(function (resolve) {
                dialog.returnValue = 'cancel';
                dialog.addEventListener('close', function () { resolve(dialog.returnValue || 'cancel'); }, { once: true });
                dialog.showModal();
            });
        }
        function saveFirst() {
            var form = Array.from(dirty).find(function (f) { return f.isConnected; });
            if (form) { form.requestSubmit(); }
        }

        ['focusin', 'pointerdown', 'keydown'].forEach(function (type) { document.addEventListener(type, remember, true); });
        document.addEventListener('input', evaluate, true);
        document.addEventListener('change', evaluate, true);

        // Enregistrement : on ne prévient plus tant que la sauvegarde est en cours ; après la réponse du serveur, un formulaire sans erreur redevient « propre ».
        document.addEventListener('submit', function (event) {
            if (formOf(event)) { quiet = true; setTimeout(function () { quiet = false; }, 6000); }
        }, true);
        document.addEventListener('livewire:init', function () {
            window.Livewire.hook('commit', function (ctx) {
                ctx.succeed(function () {
                    if (!quiet) { return; }
                    dirty.forEach(function (form) {
                        if (!form.querySelector('[data-validation-error]')) { dirty.delete(form); snapshots.set(form, serialise(form)); }
                    });
                });
            });
        });

        // Liens (menu, Retour, listes) : on demande avant de quitter un formulaire modifié.
        document.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('a[href]') : null;

            if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                || link.target === '_blank' || link.hasAttribute('download') || link.getAttribute('href').charAt(0) === '#') { return; }

            var url = new URL(link.href, window.location.href);
            var goBack = link.hasAttribute('data-nova-back') && document.referrer !== '' && new URL(document.referrer).pathname === url.pathname && window.history.length > 1;
            var proceed = function () { if (goBack) { window.history.back(); } else { window.location.assign(link.href); } };

            if (hasChanges() && url.origin === window.location.origin) {
                event.preventDefault();
                ask().then(function (choice) {
                    if (choice === 'leave') { dirty.clear(); proceed(); } else if (choice === 'save') { saveFirst(); }
                });
            } else if (goBack) {
                // Retour à la liste d'où l'on vient : mêmes filtres, même page, même position.
                event.preventDefault();
                window.history.back();
            }
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (!quiet && hasChanges()) { event.preventDefault(); event.returnValue = ''; }
        });
    })();
</script>
