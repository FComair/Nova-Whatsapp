<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useT } from '../composables/useT';

/**
 * Note de 1 à 5 étoiles, accessible : groupe de boutons radio, une seule tabulation (flèches pour changer), nom lu pour chaque étoile
 * (« 4 sur 5 : Bonne »). La couleur n'est jamais seule à porter l'information : étoile pleine ou vide.
 */
const props = withDefaults(defineProps<{ label: string; error?: string }>(), { error: undefined });

const model = defineModel<number | null>({ required: true });
const { t } = useT();
const hover = ref<number | null>(null);
const group = ref<HTMLElement | null>(null);

const stars = [1, 2, 3, 4, 5];
const shown = computed(() => hover.value ?? model.value ?? 0);
const tabbable = computed(() => model.value ?? 1);

function name(value: number): string {
    return t('feedback.star', { count: value, name: t(`feedback.names.${value}`) });
}

function choose(value: number): void {
    model.value = value;
}

async function move(event: KeyboardEvent, value: number): Promise<void> {
    const forward = ['ArrowRight', 'ArrowDown'].includes(event.key);
    const backward = ['ArrowLeft', 'ArrowUp'].includes(event.key);
    const target = event.key === 'Home' ? 1 : event.key === 'End' ? 5 : forward ? Math.min(5, value + 1) : backward ? Math.max(1, value - 1) : null;

    if (target === null) {
        return;
    }

    event.preventDefault();
    choose(target);
    await nextTick();
    group.value?.querySelector<HTMLElement>(`[data-star="${target}"]`)?.focus();
}
</script>

<template>
    <div>
        <p :id="`${$.uid}-label`" class="mb-2 text-sm font-semibold text-neutral-900">{{ props.label }}</p>
        <div
            ref="group"
            role="radiogroup"
            :aria-labelledby="`${$.uid}-label`"
            :aria-invalid="props.error ? 'true' : undefined"
            class="flex items-center gap-1"
            @mouseleave="hover = null"
        >
            <button
                v-for="value in stars"
                :key="value"
                type="button"
                role="radio"
                :data-star="value"
                :aria-checked="model === value"
                :aria-label="name(value)"
                :title="t(`feedback.names.${value}`)"
                :tabindex="value === tabbable ? 0 : -1"
                class="inline-flex size-11 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                @click="choose(value)"
                @keydown="move($event, value)"
                @mouseenter="hover = value"
            >
                <svg
                    viewBox="0 0 24 24"
                    class="size-8 transition"
                    :class="value <= shown ? 'fill-amber stroke-warning-600' : 'fill-transparent stroke-neutral-500'"
                    stroke-width="1.6"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m12 2.8 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 16.8l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />
                </svg>
            </button>
            <span v-if="model" class="ml-2 text-sm font-semibold text-neutral-900" aria-hidden="true">{{ t(`feedback.names.${model}`) }}</span>
        </div>
        <p v-if="props.error" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ props.error }}</p>
    </div>
</template>
