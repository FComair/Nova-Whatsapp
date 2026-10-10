<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import { useT } from '../composables/useT';

/** Mise en page des documents juridiques : publique (sans connexion), lisible, imprimable, avec le choix de langue. */
defineProps<{ title: string }>();
const { t } = useT();
</script>

<template>
    <Head :title="title" />

    <a href="#document" class="sr-only rounded bg-surface px-3 py-2 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">{{ t('legal.page.skip') }}</a>

    <div class="min-h-screen bg-canvas px-4 py-6 sm:px-6">
        <header class="mx-auto flex max-w-5xl items-center justify-between gap-3 print:hidden">
            <Link href="/" class="text-2xl font-bold tracking-tight text-nova-900">{{ t('common.app_name') }}</Link>
            <LanguageSwitcher />
        </header>
        <main class="mx-auto mt-6 max-w-5xl"><slot /></main>
    </div>
</template>
