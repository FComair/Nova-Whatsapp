<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { settingsHome } from '../../composables/settingsNav';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import type { SetupCategoryData, SetupStepData } from '../../types';

const props = defineProps<{ category: SetupCategoryData }>();

const { t } = useT();

const title = computed(() => t(`setup.categories.${props.category.key}.title`));
const percent = computed(() => (props.category.total === 0 ? 0 : Math.round((props.category.done / props.category.total) * 100)));

/** Texte du bouton : consulter ou modifier ce qui est fait, reprendre l'étape en cours, sinon y aller. */
function action(step: SetupStepData): string {
    if (step.state === 'done') {
        return t(step.wizard ? 'setup.cta.edit' : 'setup.cta.view');
    }

    return t(step.state === 'current' ? 'setup.cta.resume' : 'setup.cta.go');
}

function primary(step: SetupStepData): boolean {
    return step.state === 'current';
}
</script>

<template>
    <SettingsLayout :title="title">
        <nav class="mb-3 flex flex-wrap items-center gap-2 text-sm text-neutral-600" :aria-label="t('setup.breadcrumb')">
            <Link :href="settingsHome()" class="hover:text-primary-700">{{ t('settings.nav') }}</Link>
            <span aria-hidden="true">›</span>
            <span>{{ t('settings.groups.setup') }}</span>
            <span aria-hidden="true">›</span>
            <span class="font-semibold text-neutral-900" aria-current="page">{{ title }}</span>
        </nav>

        <h1 class="text-2xl font-semibold text-nova-900">{{ title }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t(`setup.categories.${category.key}.intro`) }}</p>

        <section class="mt-6 max-w-2xl rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="progress">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="progress" class="text-base font-semibold text-nova-900">{{ t('setup.progress_title') }}</h2>
                <span class="text-sm font-semibold text-neutral-600">{{
                    t('setup.progress', { done: String(category.done), total: String(category.total) })
                }}</span>
            </div>
            <div
                class="mt-3 h-2 overflow-hidden rounded-full bg-primary-50"
                role="progressbar"
                aria-valuemin="0"
                :aria-valuemax="category.total"
                :aria-valuenow="category.done"
                :aria-label="t('setup.progress_title')"
            >
                <div class="h-full rounded-full bg-primary-600" :style="{ width: `${percent}%` }" />
            </div>
        </section>

        <ul class="mt-5 max-w-2xl divide-y divide-neutral-200 overflow-hidden rounded-card border border-neutral-200 bg-white" :aria-label="title">
            <li v-for="step in category.steps" :key="step.key" class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
                <span class="flex size-7 shrink-0" aria-hidden="true">
                    <svg v-if="step.state === 'done'" class="size-7 text-success-600" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="currentColor" />
                        <path d="m7.5 12.5 3 3 6-6.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg v-else-if="step.state === 'current'" class="size-7 text-primary-600" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                        <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" />
                    </svg>
                    <svg
                        v-else-if="step.state === 'soon'"
                        class="size-7 text-neutral-500"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    <svg v-else class="size-7 text-neutral-500" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                    </svg>
                </span>

                <div class="min-w-0 flex-1 basis-56">
                    <p class="text-base" :class="step.state === 'done' ? 'font-medium text-neutral-600' : 'font-semibold text-neutral-900'">
                        {{ t(`setup.steps.${step.key}.title`) }}
                        <span
                            v-if="step.optional"
                            class="ml-2 rounded border border-neutral-200 px-1.5 py-px align-middle text-xs font-semibold text-neutral-600"
                            >{{ t('setup.optional') }}</span
                        >
                    </p>
                    <p class="mt-0.5 text-sm text-neutral-600">{{ t(`setup.steps.${step.key}.body`) }}</p>
                </div>

                <span
                    class="min-w-24 text-sm font-semibold sm:text-right"
                    :class="{
                        'text-success-600': step.state === 'done',
                        'text-primary-700': step.state === 'current',
                        'text-neutral-600': step.state === 'todo' || step.state === 'soon',
                    }"
                >
                    {{ t(`setup.states.${step.state}`) }}
                </span>

                <Link
                    v-if="step.href"
                    :href="step.href"
                    class="inline-flex min-h-11 min-w-28 items-center justify-center rounded-lg px-4 text-sm font-semibold"
                    :class="
                        primary(step)
                            ? 'bg-primary-600 text-white hover:bg-primary-700'
                            : step.state === 'done'
                              ? 'text-primary-700 hover:bg-primary-50'
                              : 'border border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100'
                    "
                    :aria-label="`${action(step)} : ${t(`setup.steps.${step.key}.title`)}`"
                >
                    {{ action(step) }}
                </Link>
                <span
                    v-else
                    class="inline-flex min-h-11 min-w-28 cursor-not-allowed items-center justify-center rounded-lg border border-neutral-200 px-4 text-sm font-semibold text-neutral-500"
                    aria-disabled="true"
                >
                    {{ t('setup.cta.go') }}
                </span>
            </li>
        </ul>

        <p class="mt-4 max-w-2xl text-sm text-neutral-600">{{ t('setup.footer') }}</p>
    </SettingsLayout>
</template>
