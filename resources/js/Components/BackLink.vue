<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { canReturnInHistory } from '../composables/backNav';
import { useT } from '../composables/useT';

/**
 * Bouton « Retour » en haut à gauche d'un écran profond. Il ramène à l'écran parent : s'il vient d'y passer, il revient en arrière dans l'historique
 * (liste, filtres, page et défilement retrouvés tels quels), sinon il va à l'adresse du parent. Le lien reste un vrai lien (clic droit, nouvel onglet).
 */
const props = defineProps<{ href: string; label: string }>();

const { t } = useT();

function back(event: MouseEvent): void {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    if (canReturnInHistory(props.href)) {
        event.preventDefault();
        window.history.back();
    }
}
</script>

<template>
    <nav class="mb-4 print:hidden" :aria-label="t('back_nav.aria')">
        <Link
            :href="href"
            data-testid="back-link"
            class="-ml-2 inline-flex min-h-11 max-w-full items-center gap-1.5 rounded-control px-2 text-sm font-semibold text-primary-700 transition-colors duration-150 hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:bg-primary-100 max-sm:w-full"
            @click="back"
        >
            <svg
                class="size-4 shrink-0"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M19 12H5M11 18l-6-6 6-6" />
            </svg>
            <span class="truncate">{{ t(label) }}</span>
        </Link>
    </nav>
</template>
