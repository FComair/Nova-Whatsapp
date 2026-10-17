<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import { renderMessage, unknownVariables } from '../../composables/loyalty';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';
import type { ProgramSummary } from '../../types/loyalty';

type Settings = Record<string, { enabled: boolean; texts: Record<string, string> }>;

/**
 * Messages WhatsApp de fidélité (propriétaire) : cinq messages facultatifs, tous désactivés au départ, avec texte par langue, variables et aperçu.
 * Ils ne partent que pour un client qui a donné son accord marketing ; la participation au programme n'est pas un accord.
 */
const props = defineProps<{
    program: ProgramSummary | null;
    settings: Settings;
    kinds: string[];
    variables: string[];
    max_length: number;
    defaults: Record<string, Record<string, string>>;
    samples: Record<string, Record<string, string>>;
    locales: string[];
    has_expiry: boolean;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const can = { view: true, configure: true, redeem: true, adjust: true };
const form = useForm({ settings: JSON.parse(JSON.stringify(props.settings)) as Settings });
const language = ref(props.locales.includes(locale.value) ? locale.value : (props.locales[0] ?? 'fr'));
const error = computed(() => (form.errors as Record<string, string | undefined>).settings);

const text = (kind: string): string => form.settings[kind]?.texts[language.value] ?? '';
const shown = (kind: string): string => (text(kind).trim() !== '' ? text(kind) : (props.defaults[kind]?.[language.value] ?? ''));
const preview = (kind: string): string => renderMessage(shown(kind), props.samples[language.value] ?? {});
const unknown = (kind: string): string[] => unknownVariables(text(kind), props.variables);
const token = (name: string): string => '{' + name + '}';
const unknownMessage = (kind: string): string =>
    t('loyalty.messages.unknown_variable', { name: token(unknown(kind)[0] ?? ''), message: t('loyalty.messages.kinds.' + kind + '.title') });

async function insert(kind: string, name: string): Promise<void> {
    const id = `message-${kind}`;
    const element = document.getElementById(id) as HTMLTextAreaElement | null;
    const current = text(kind);
    const at = element?.selectionStart ?? current.length;
    const end = element?.selectionEnd ?? at;
    const token = `{${name}}`;
    const entry = form.settings[kind];

    if (entry !== undefined) {
        entry.texts[language.value] = `${current.slice(0, at)}${token}${current.slice(end)}`;
    }

    await nextTick();
    element?.focus();
    element?.setSelectionRange(at + token.length, at + token.length);
}

function save(): void {
    form.put(`${routes.loyalty}/messages`, { preserveScroll: true, preserveState: true, onSuccess: () => form.defaults() });
}
</script>

<template>
    <AppLayout :title="t('loyalty.messages.title')">
        <LoyaltyTabs current="messages" :can="can" />

        <h1 class="text-xl font-semibold text-rendivoo-900">{{ t('loyalty.messages.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-700">{{ t('loyalty.messages.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="error" variant="error" class="mt-4">{{ error }}</AlertBox>

        <div v-if="program === null" class="mt-6 rounded-card border border-dashed border-line-strong p-6 text-sm text-neutral-800" data-testid="no-program">
            {{ t('loyalty.messages.no_program') }}
        </div>

        <form v-else class="mt-5 space-y-5" novalidate @submit.prevent="save">
            <AlertBox variant="info">{{ t('loyalty.messages.consent') }}</AlertBox>
            <p class="text-sm text-neutral-700">{{ t('loyalty.messages.window') }}</p>

            <div role="group" :aria-label="t('loyalty.messages.preview_language')" class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-neutral-900">{{ t('loyalty.messages.preview_language') }}</span>
                <button
                    v-for="code in locales"
                    :key="code"
                    type="button"
                    class="min-h-9 rounded-full border-[1.5px] px-3 text-sm font-semibold"
                    :class="language === code ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-line-strong text-neutral-900'"
                    :aria-pressed="language === code"
                    @click="language = code"
                >
                    {{ t(`common.languages.${code}`) }}
                </button>
            </div>

            <section
                v-for="kind in kinds"
                :key="kind"
                class="rounded-card border border-line bg-surface p-5 shadow-xs"
                :aria-labelledby="`title-${kind}`"
                :data-testid="`message-${kind}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 :id="`title-${kind}`" class="font-semibold text-rendivoo-900">{{ t(`loyalty.messages.kinds.${kind}.title`) }}</h2>
                        <p class="text-sm text-neutral-700">{{ t(`loyalty.messages.kinds.${kind}.when`) }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
                        <input
                            v-if="form.settings[kind]"
                            v-model="form.settings[kind].enabled"
                            type="checkbox"
                            class="rendivoo-check"
                            :data-testid="`enable-${kind}`"
                        />
                        {{ t('loyalty.messages.enabled') }}
                    </label>
                </div>
                <p v-if="kind === 'expiry_reminder' && !has_expiry" class="mt-2 text-sm text-warning-fg">{{ t('loyalty.messages.needs_expiry') }}</p>

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <label :for="`message-${kind}`" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{
                            t('loyalty.messages.text', { language: t(`common.languages.${language}`) })
                        }}</label>
                        <textarea
                            v-if="form.settings[kind]"
                            :id="`message-${kind}`"
                            v-model="form.settings[kind].texts[language]"
                            rows="4"
                            :maxlength="max_length"
                            :placeholder="defaults[kind]?.[language]"
                            :aria-describedby="`hint-${kind}`"
                            class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3.5 py-2.5 text-sm text-neutral-900 focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-600/20"
                        />
                        <div :id="`hint-${kind}`" class="mt-1 flex justify-between gap-3 text-xs text-neutral-700">
                            <span>{{ t('loyalty.messages.text_hint') }}</span>
                            <span>{{ t('loyalty.messages.length', { count: text(kind).length, max: max_length }) }}</span>
                        </div>
                        <p v-if="unknown(kind).length > 0" class="mt-1 text-sm font-medium text-danger-fg" role="alert">
                            {{ unknownMessage(kind) }}
                        </p>
                        <div class="mt-3">
                            <p class="text-xs font-semibold text-neutral-800">{{ t('loyalty.messages.variables') }}</p>
                            <p class="text-xs text-neutral-700">{{ t('loyalty.messages.variables_hint') }}</p>
                            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                <li v-for="name in variables" :key="name">
                                    <button
                                        type="button"
                                        class="rounded-full border border-line-strong px-2.5 py-1 text-xs font-medium text-neutral-900 hover:border-primary-600"
                                        :title="t(`loyalty.messages.variable.${name}`)"
                                        @click="insert(kind, name)"
                                    >
                                        {{ token(name) }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.messages.preview') }}</p>
                        <div class="rounded-2xl rounded-tl-sm bg-mint px-4 py-3 text-sm text-neutral-900" :lang="language" data-testid="preview">
                            {{ preview(kind) }}
                        </div>
                        <p class="mt-1.5 text-xs text-neutral-700">{{ t('loyalty.messages.preview_hint') }}</p>
                    </div>
                </div>
            </section>

            <BaseButton type="submit" :loading="form.processing" :disabled="kinds.some((kind) => unknown(kind).length > 0)">
                {{ t('loyalty.messages.save') }}
            </BaseButton>
        </form>
    </AppLayout>
</template>
