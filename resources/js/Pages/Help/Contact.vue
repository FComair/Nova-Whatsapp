<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import FileAttachments from '../../Components/FileAttachments.vue';
import HelpTabs from '../../Components/HelpTabs.vue';
import SelectField from '../../Components/SelectField.vue';
import TextareaField from '../../Components/TextareaField.vue';
import TextField from '../../Components/TextField.vue';
import { type HelpEntry, searchHelp } from '../../composables/helpSearch';
import type { AttachmentLimits } from '../../composables/supportTickets';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{
    email: string;
    token: string;
    subject: string | null;
    options: { categories: string[]; related: string[]; urgencies: string[] };
    limits: AttachmentLimits;
    index: HelpEntry[];
}>();

const { t } = useT();
const MAX = 5000;

const form = useForm({
    subject: props.subject ?? '',
    category: '',
    related_to: '',
    urgency: 'medium',
    description: '',
    email: props.email,
    token: props.token,
    attachments: [] as File[],
});
const editEmail = ref(false);

/** Quand l'objet compte au moins 3 mots : jusqu'à 3 articles qui répondent peut-être déjà à la question. */
const suggestions = computed(() => (form.subject.trim().split(/\s+/).length >= 3 ? searchHelp(props.index, form.subject, 3) : []));

const attachmentError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('attachments'))?.[1]);
const rateError = computed(() => (form.errors as Record<string, string | undefined>).rate);

function submit(): void {
    form.post(`${routes.help}/contact`, { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('support.title')">
        <HelpTabs />

        <div class="mx-auto max-w-2xl">
            <h1 class="font-display text-2xl font-bold text-rendivoo-900">{{ t('support.title') }}</h1>
            <p class="mt-1 text-neutral-700">{{ t('support.intro') }}</p>

            <AlertBox variant="info" class="mt-4">
                <span class="block">{{ t('support.response_time') }}</span>
                <span class="mt-1 block text-sm">{{ t('support.hours') }}</span>
            </AlertBox>
            <AlertBox v-if="rateError" variant="error" class="mt-4">{{ rateError }}</AlertBox>

            <form class="mt-6 space-y-5 rounded-card border border-line bg-surface p-5 shadow-xs sm:p-7" novalidate @submit.prevent="submit">
                <TextField
                    v-model="form.subject"
                    :label="t('support.fields.subject')"
                    :hint="t('support.fields.subject_placeholder')"
                    :maxlength="120"
                    required
                    :error="form.errors.subject"
                />

                <section v-if="suggestions.length" class="rounded-card bg-primary-50 p-4" aria-live="polite">
                    <p class="text-sm font-semibold text-rendivoo-900">{{ t('support.suggestions') }}</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="entry in suggestions" :key="`${entry.category}/${entry.slug}`">
                            <a
                                :href="`${routes.help}/${entry.category}/${entry.slug}`"
                                target="_blank"
                                rel="noopener"
                                class="font-semibold text-primary-700 underline underline-offset-4"
                                >{{ entry.title }}</a
                            >
                        </li>
                    </ul>
                </section>

                <div class="grid gap-5 sm:grid-cols-2">
                    <SelectField
                        v-model="form.category"
                        :label="t('support.fields.category')"
                        :placeholder="t('support.fields.category_placeholder')"
                        :options="options.categories.map((key) => ({ value: key, label: t(`support.categories.${key}`) }))"
                        required
                        :error="form.errors.category"
                    />
                    <SelectField
                        v-model="form.related_to"
                        :label="t('support.fields.related_to')"
                        :options="[
                            { value: '', label: t('support.fields.related_none') },
                            ...options.related.map((key) => ({ value: key, label: t(`support.related.${key}`) })),
                        ]"
                        :error="form.errors.related_to"
                    />
                </div>

                <TextareaField
                    v-model="form.description"
                    :label="t('support.fields.description')"
                    :hint="t('support.fields.description_placeholder')"
                    :rows="7"
                    :maxlength="MAX"
                    :error="form.errors.description"
                />
                <p class="-mt-3 text-right text-xs text-neutral-600" aria-hidden="true">
                    {{ t('support.counter', { count: form.description.length, max: MAX }) }}
                </p>

                <FileAttachments v-model="form.attachments" :limits="limits" :error="attachmentError" />

                <fieldset>
                    <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('support.fields.urgency') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="level in options.urgencies"
                            :key="level"
                            class="flex cursor-pointer items-start gap-3 rounded-control border-[1.5px] p-3 text-sm transition"
                            :class="form.urgency === level ? 'border-primary-600 bg-primary-50' : 'border-line-strong bg-surface hover:border-primary-600'"
                        >
                            <input v-model="form.urgency" type="radio" name="urgency" :value="level" class="mt-1 accent-[var(--color-primary-600)]" />
                            <span>
                                <span class="block font-semibold text-neutral-900">{{ t(`support.urgencies.${level}.label`) }}</span>
                                <span class="block text-neutral-700">{{ t(`support.urgencies.${level}.help`) }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.urgency" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ form.errors.urgency }}</p>
                </fieldset>

                <div>
                    <TextField
                        v-model="form.email"
                        type="email"
                        :label="t('support.fields.email')"
                        :hint="t('support.fields.email_hint')"
                        :disabled="!editEmail"
                        required
                        :error="form.errors.email"
                    />
                    <BaseButton v-if="!editEmail" variant="ghost" size="sm" class="mt-1" @click="editEmail = true">
                        {{ t('support.fields.email_change') }}
                    </BaseButton>
                </div>

                <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                    <BaseButton type="submit" :loading="form.processing">{{ form.processing ? t('support.sending') : t('support.submit') }}</BaseButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
