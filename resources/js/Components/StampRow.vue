<script setup lang="ts">
import { computed } from 'vue';
import { MAX_STAMPS_SHOWN } from '../composables/loyalty';
import { useT } from '../composables/useT';

/**
 * Progression de fidélité d'un coup d'œil : une rangée de tampons (jusqu'à 12), ou une barre au-delà. Le tampon obtenu est plein avec une
 * coche, le tampon à venir est vide : la couleur ne porte jamais seule l'information, et l'ensemble se lit comme une seule image
 * (« Progression : 6 sur 8 »). `labels` permet de fournir les textes dans la langue d'un client (aperçu du programme).
 */
const props = withDefaults(defineProps<{ stamps: number; required: number; label?: string; size?: 'md' | 'lg' }>(), { label: undefined, size: 'md' });
const { t } = useT();

const done = computed(() => Math.max(0, Math.min(props.stamps, props.required)));
const asBar = computed(() => props.required > MAX_STAMPS_SHOWN);
const percent = computed(() => (props.required === 0 ? 0 : Math.round((done.value / props.required) * 100)));
const text = computed(() => props.label ?? t('loyalty.progress.label', { stamps: done.value, required: props.required }));
</script>

<template>
    <div role="img" :aria-label="text" data-testid="stamp-row">
        <div v-if="asBar" class="h-3 w-full overflow-hidden rounded-full bg-neutral-200">
            <div class="h-full rounded-full bg-primary-600 transition-all" :style="{ width: `${percent}%` }" data-testid="stamp-bar" />
        </div>
        <ol v-else class="flex flex-wrap gap-2" aria-hidden="true">
            <li
                v-for="n in required"
                :key="n"
                class="grid place-items-center rounded-full border-2"
                :class="[
                    size === 'lg' ? 'size-11' : 'size-8',
                    n <= done ? 'border-primary-600 bg-primary-600 text-white' : 'border-dashed border-neutral-500 bg-surface text-neutral-500',
                ]"
                :data-done="n <= done ? 'true' : 'false'"
            >
                <svg
                    v-if="n <= done"
                    viewBox="0 0 16 16"
                    class="size-4/6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="m3.5 8.4 3 3 6-6.4" />
                </svg>
                <span v-else class="text-xs font-semibold">{{ n }}</span>
            </li>
        </ol>
    </div>
</template>
