import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { trackNavigation } from './composables/backNav';
import { initTheme } from './composables/theme';
import { initTimezone } from './composables/timezone';
import { installUnsavedGuard } from './composables/unsaved';

const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');

// Pile des écrans visités (bouton « Retour ») et protection des modifications non enregistrées.
trackNavigation(`${window.location.pathname}${window.location.search}`);
router.on('navigate', (event) => trackNavigation(event.detail.page.url));
installUnsavedGuard();
initTheme();
initTimezone();

createInertiaApp({
    title: (title) => (title ? `${title} · Nova` : 'Nova'),
    resolve: (name) => {
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page Inertia introuvable : ${name}`);
        }

        return page();
    },
});
