<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../Components/AlertBox.vue';
import { useT } from '../composables/useT';
import { onboardingStep, routes } from '../routes';
import type { SharedProps, Wizard } from '../types';
import AppLayout from './AppLayout.vue';

const props = defineProps<{ wizard: Wizard; title: string }>();

const page = usePage<SharedProps>();
const { t } = useT();

const position = computed(() => props.wizard.steps.find((step) => step.key === props.wizard.step)?.number ?? 1);
const percent = computed(() => Math.round((props.wizard.steps.filter((step) => step.status === 'done').length / props.wizard.total) * 100));
</script>

<template>
    <AppLayout :title="title">
        <div class="mx-auto max-w-3xl">
            <p class="text-sm font-medium text-primary-700">{{ t('onboarding.title') }}</p>
            <p class="mt-1 text-sm text-neutral-600">{{ t('onboarding.subtitle') }}</p>

            <!-- Mobile : progression compacte. Bureau : liste complète des étapes. -->
            <div class="mt-5 md:hidden">
                <p class="text-sm font-semibold text-nova-900">
                    {{ t('onboarding.progress', { current: position, total: wizard.total }) }} · {{ t(`onboarding.steps.${wizard.step}`) }}
                </p>
                <div
                    class="mt-2 h-2 overflow-hidden rounded-full bg-neutral-200"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="h-full bg-primary-600 transition-all" :style="{ width: `${percent}%` }" />
                </div>
            </div>

            <nav class="mt-5 hidden md:block" :aria-label="t('onboarding.steps_label')">
                <ol class="flex flex-wrap gap-x-4 gap-y-2">
                    <li v-for="step in wizard.steps" :key="step.key" class="flex items-center gap-2 text-sm">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="{
                                'bg-success-600 text-white': step.status === 'done',
                                'bg-primary-600 text-white': step.status === 'current',
                                'bg-neutral-200 text-neutral-700': step.status === 'todo',
                            }"
                            aria-hidden="true"
                        >
                            <template v-if="step.status === 'done'">✓</template>
                            <template v-else>{{ step.number }}</template>
                        </span>

                        <Link
                            v-if="step.available && step.status !== 'todo'"
                            :href="onboardingStep(step.slug)"
                            class="font-medium hover:underline"
                            :class="step.key === wizard.step ? 'text-nova-900' : 'text-primary-700'"
                            :aria-current="step.key === wizard.step ? 'step' : undefined"
                        >
                            {{ t(`onboarding.steps.${step.key}`) }}
                            <span class="sr-only"> — {{ t(`onboarding.status.${step.status}`) }}</span>
                        </Link>
                        <span v-else class="text-neutral-500">
                            {{ t(`onboarding.steps.${step.key}`) }}
                            <span class="sr-only"> — {{ t(`onboarding.status.${step.available ? step.status : 'soon'}`) }}</span>
                        </span>
                    </li>
                </ol>
            </nav>

            <AlertBox v-if="page.props.flash.status" variant="success" class="mt-5">{{ page.props.flash.status }}</AlertBox>
            <AlertBox v-if="page.props.flash.warning" variant="warning" class="mt-5">{{ page.props.flash.warning }}</AlertBox>

            <section class="mt-6 rounded-card border border-neutral-200 bg-white p-5 shadow-sm sm:p-8">
                <slot />
            </section>

            <p class="mt-4 text-center text-sm">
                <Link :href="routes.dashboard" class="font-medium text-primary-700 hover:underline">{{ t('onboarding.later') }}</Link>
            </p>
        </div>
    </AppLayout>
</template>
