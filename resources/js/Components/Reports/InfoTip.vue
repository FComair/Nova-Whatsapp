<script setup lang="ts">
import { ref, useId } from 'vue';
import { useT } from '../../composables/useT';

/**
 * Définition d'un indicateur : un bouton « i » dont l'explication s'affiche au survol, au clavier (focus) et au toucher.
 * Echap la referme. Masqué à l'impression : la définition y est reprise dans la section « Définitions ».
 */
defineProps<{ text: string }>();

const { t } = useT();
const open = ref(false);
const id = useId();
</script>

<template>
    <span class="relative inline-flex print:hidden">
        <button
            type="button"
            class="inline-flex size-5 items-center justify-center rounded-full border border-line-strong text-[0.65rem] font-bold leading-none text-neutral-700 transition hover:border-primary-600 hover:text-primary-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-600/20"
            :aria-label="t('reports.chart.legend')"
            :aria-describedby="open ? id : undefined"
            :aria-expanded="open"
            data-testid="info-tip"
            @click.stop="open = !open"
            @keydown.esc="open = false"
            @blur="open = false"
            @mouseenter="open = true"
            @mouseleave="open = false"
            @focus="open = true"
        >
            i
        </button>
        <span
            v-show="open"
            :id="id"
            role="tooltip"
            class="absolute right-0 top-6 z-30 w-64 rounded-control border border-line bg-surface p-3 text-left text-xs font-normal normal-case leading-relaxed text-neutral-800 shadow-md"
        >
            {{ text }}
        </span>
    </span>
</template>
