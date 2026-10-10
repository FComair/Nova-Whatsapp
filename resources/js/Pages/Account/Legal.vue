<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import { formatDate, formatDateTime } from '../../composables/dates';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface Row {
    code: string;
    slug: string;
    title: string;
    version: string | null;
    effective_on: string | null;
    state: 'accepted' | 'missing' | 'reacceptance' | 'update' | 'not_applicable' | 'unavailable';
    blocking: boolean;
    accepted: { version: string; at: string; context: string } | null;
    history: { version: string; at: string; context: string }[];
    changes: string | null;
}

/**
 * Réglages › Juridique : les trois documents en vigueur, ce que la personne connectée a accepté (version, date, contexte) et l'historique.
 * Visiter la page n'engage à rien ; l'acceptation est un acte explicite, proposé seulement quand elle manque ou qu'une version significative l'exige.
 */
const props = defineProps<{ documents: Row[] }>();

const { t } = useT();
const page = usePage<SharedProps>();
const busy = ref<string | null>(null);
const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const blocking = computed(() => props.documents.some((row) => row.blocking));
const VARIANTS: Record<Row['state'], 'success' | 'warning' | 'info' | 'neutral'> = {
    accepted: 'success',
    missing: 'warning',
    reacceptance: 'warning',
    update: 'info',
    not_applicable: 'neutral',
    unavailable: 'neutral',
};
const variant = (state: Row['state']): 'success' | 'warning' | 'info' | 'neutral' => VARIANTS[state];
const context = (value: string): string => t(`legal.settings.contexts.${value}`);
const canAccept = (row: Row): boolean => row.state === 'missing' || row.state === 'reacceptance';

function accept(slugs: string[]): void {
    busy.value = slugs.join(',');
    router.post(routes.legalAccept, { documents: slugs }, { preserveScroll: true, onFinish: () => (busy.value = null) });
}
</script>

<template>
    <SettingsLayout :title="t('legal.settings.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('legal.settings.title') }}</h1>
        <p class="mt-1 max-w-2xl text-sm text-neutral-700">{{ t('legal.settings.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.documents" variant="error" class="mt-4">{{ errors.documents }}</AlertBox>
        <AlertBox v-if="blocking" variant="warning" class="mt-4" data-testid="legal-blocked">
            <p class="font-semibold">{{ t('legal.settings.blocked_title') }}</p>
            <p>{{ t('legal.settings.blocked_body') }}</p>
        </AlertBox>

        <ul class="mt-6 space-y-5">
            <li v-for="row in documents" :key="row.code" class="rounded-card border border-line bg-surface p-5 shadow-xs" :data-testid="`legal-${row.slug}`">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-nova-900">{{ row.title || t(`legal.documents.${row.code}`) }}</h2>
                        <p v-if="row.version" class="text-sm text-neutral-700">
                            {{ t('legal.settings.current') }} : {{ t('legal.page.version', { version: row.version }) }} ·
                            {{ t('legal.page.effective', { date: formatDate(row.effective_on ?? '') }) }}
                        </p>
                        <p v-else class="text-sm text-neutral-700">{{ t('legal.settings.unavailable') }}</p>
                    </div>
                    <Badge :variant="variant(row.state)">{{ t(`legal.settings.state.${row.state}`) }}</Badge>
                </div>

                <p v-if="row.state === 'not_applicable'" class="mt-3 text-sm text-neutral-800">{{ t('legal.settings.dpa_owner') }}</p>
                <template v-else>
                    <p v-if="row.accepted" class="mt-3 text-sm text-neutral-900" data-testid="accepted-line">
                        {{ t('legal.settings.accepted', { version: row.accepted.version, date: formatDateTime(row.accepted.at) }) }}
                        {{ t('legal.settings.accepted_context', { context: context(row.accepted.context) }) }}
                    </p>
                    <p v-else class="mt-3 text-sm text-neutral-800">{{ t('legal.settings.not_recorded') }}</p>
                    <p v-if="row.state === 'update'" class="mt-2 text-sm text-info-fg">{{ t('legal.settings.update_note') }}</p>
                    <p v-if="row.changes && row.state !== 'accepted'" class="mt-2 text-sm text-neutral-800">
                        {{ t('legal.settings.changes', { text: row.changes }) }}
                    </p>
                </template>

                <div class="mt-4 flex flex-wrap items-center gap-3 text-sm font-semibold">
                    <a v-if="row.version" :href="`/legal/${row.slug}`" target="_blank" rel="noopener" class="text-primary-700 underline underline-offset-2">
                        {{ t('legal.settings.view') }}<span class="sr-only"> {{ t('legal.signup.new_tab') }}</span>
                    </a>
                    <a
                        v-if="row.version"
                        :href="`/legal/${row.slug}?print=1`"
                        target="_blank"
                        rel="noopener"
                        class="text-primary-700 underline underline-offset-2"
                    >
                        {{ t('legal.settings.print') }}<span class="sr-only"> {{ t('legal.signup.new_tab') }}</span>
                    </a>
                    <a v-if="row.version" :href="`/legal/${row.slug}/download`" class="text-primary-700 underline underline-offset-2" download>{{
                        t('legal.settings.download')
                    }}</a>
                    <BaseButton v-if="canAccept(row)" size="sm" :loading="busy === row.slug" @click="accept([row.slug])">
                        {{ t('legal.settings.accept_one', { version: row.version ?? '' }) }}
                    </BaseButton>
                </div>

                <details v-if="row.state !== 'not_applicable'" class="mt-4 text-sm">
                    <summary class="cursor-pointer font-semibold text-neutral-900">{{ t('legal.settings.history') }}</summary>
                    <p v-if="row.history.length === 0" class="mt-2 text-neutral-700">{{ t('legal.settings.no_history') }}</p>
                    <ul v-else class="mt-2 space-y-1 text-neutral-800">
                        <li v-for="entry in row.history" :key="`${entry.version}-${entry.at}`">
                            {{ t('legal.settings.history_line', { version: entry.version, date: formatDateTime(entry.at), context: context(entry.context) }) }}
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </SettingsLayout>
</template>
