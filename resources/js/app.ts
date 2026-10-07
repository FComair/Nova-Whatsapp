import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';

const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');

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
