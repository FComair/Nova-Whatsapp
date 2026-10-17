<script setup lang="ts">
import { computed } from 'vue';
import { fillTemplate } from '../composables/loyalty';
import StampRow from './StampRow.vue';

/**
 * La carte de fidélité telle que la voit le client : nom du programme, progression en tampons, visites restantes, récompense, conditions,
 * expiration et usage des données. Les textes fixes arrivent dans la langue du client (`texts`), les textes du programme aussi (`program`).
 * Même carte pour l'aperçu de la configuration : le client n'a pas d'écran à lui dans Rendivoo, il la reçoit par WhatsApp.
 */
export interface CardTexts {
    of: string;
    remaining: string;
    ready: string;
    title: string;
    terms: string;
    data_title: string;
    data: string;
    marketing: string;
    expires: string;
    reward_label: string;
}

const props = defineProps<{
    language: string;
    texts: CardTexts;
    program: { name: string; reward: string; terms: string; required: number };
    stamps: number;
    /** Date d'expiration déjà formatée (JJ/MM/AAAA), si la récompense expire. */
    expiry?: string | null;
}>();

const ready = computed(() => props.stamps >= props.program.required);
const remaining = computed(() => Math.max(0, props.program.required - props.stamps));
const progressText = computed(() => fillTemplate(props.texts.of, { stamps: props.stamps, required: props.program.required }, props.language));
</script>

<template>
    <article class="overflow-hidden rounded-card border border-line bg-surface shadow-sm" :lang="language" data-testid="loyalty-card">
        <header class="bg-deep px-5 py-4 text-white">
            <p class="text-xs font-semibold uppercase tracking-wide text-white/70">{{ texts.title }}</p>
            <h3 class="mt-0.5 text-lg font-semibold">{{ program.name }}</h3>
        </header>
        <div class="space-y-4 p-5">
            <div>
                <StampRow :stamps="stamps" :required="program.required" :label="progressText" size="lg" />
                <p class="mt-2 text-sm font-semibold text-neutral-900">{{ progressText }}</p>
                <p class="text-sm text-neutral-800">{{ ready ? texts.ready : fillTemplate(texts.remaining, { count: remaining }, language) }}</p>
            </div>
            <div class="rounded-xl bg-mint px-4 py-3">
                <p class="text-xs font-semibold text-neutral-800">{{ texts.reward_label }}</p>
                <p class="font-semibold text-rendivoo-900">{{ program.reward }}</p>
                <p v-if="expiry" class="mt-1 text-xs text-neutral-800">{{ fillTemplate(texts.expires, { date: expiry }, language) }}</p>
            </div>
            <section v-if="program.terms">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-700">{{ texts.terms }}</h4>
                <p class="mt-1 whitespace-pre-line text-sm text-neutral-800">{{ program.terms }}</p>
            </section>
            <section class="rounded-xl border border-line px-4 py-3 text-xs text-neutral-800">
                <h4 class="font-semibold text-neutral-900">{{ texts.data_title }}</h4>
                <p class="mt-1">{{ texts.data }}</p>
                <p class="mt-1">{{ texts.marketing }}</p>
            </section>
        </div>
    </article>
</template>
