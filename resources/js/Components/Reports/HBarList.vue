<script setup lang="ts">
import { computed } from 'vue';

/**
 * Classement en barres horizontales (services, origines, praticiens…) : le libellé et la valeur sont toujours écrits en toutes
 * lettres, la barre n'est qu'un repère. Un éventuel second chiffre (encaissé, part) s'affiche sous le libellé.
 */
const props = defineProps<{ items: { key: string; label: string; value: number; text: string; sub?: string }[] }>();

const max = computed(() => Math.max(1, ...props.items.map((item) => item.value)));
</script>

<template>
    <ul class="space-y-3" data-testid="hbar-list">
        <li v-for="item in items" :key="item.key" class="print:break-inside-avoid">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate font-medium text-neutral-900">{{ item.label }}</span>
                <span class="shrink-0 font-semibold text-rendivoo-900">{{ item.text }}</span>
            </div>
            <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-neutral-100" aria-hidden="true">
                <div class="h-full rounded-full bg-primary-600" :style="{ width: `${(item.value / max) * 100}%` }" />
            </div>
            <p v-if="item.sub" class="mt-0.5 text-xs text-neutral-700">{{ item.sub }}</p>
        </li>
    </ul>
</template>
