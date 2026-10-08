<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AlertBox from '../Components/AlertBox.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import { useT } from '../composables/useT';
import type { SharedProps } from '../types';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage<SharedProps>();
const { t } = useT();
</script>

<template>
    <Head :title="title" />

    <a href="#contenu" class="sr-only rounded bg-surface px-3 py-2 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">
        {{ t('common.skip_to_content') }}
    </a>

    <div class="flex min-h-screen flex-col px-4 py-6 sm:px-6">
        <header class="mx-auto flex w-full max-w-md items-center justify-between">
            <span class="text-2xl font-bold tracking-tight text-nova-900">{{ t('common.app_name') }}</span>
            <LanguageSwitcher />
        </header>

        <main id="contenu" class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-8">
            <div class="rounded-card border border-line bg-surface shadow-xs p-6 shadow-sm sm:p-8">
                <h1 class="text-2xl font-semibold text-nova-900">{{ title }}</h1>
                <p v-if="subtitle" class="mt-2 text-neutral-600">{{ subtitle }}</p>

                <AlertBox v-if="page.props.flash.status" variant="success" class="mt-5">{{ page.props.flash.status }}</AlertBox>

                <div class="mt-6"><slot /></div>
            </div>

            <div class="mt-5 text-center text-sm text-neutral-600"><slot name="footer" /></div>
        </main>
    </div>
</template>
