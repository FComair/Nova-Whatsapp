<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const { t } = useT();

function logout(): void {
    router.post(routes.logout);
}
</script>

<template>
    <Head :title="title" />

    <a href="#contenu" class="sr-only rounded bg-white px-3 py-2 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">
        {{ t('common.skip_to_content') }}
    </a>

    <div class="min-h-screen bg-neutral-50">
        <header class="border-b border-neutral-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="text-xl font-bold tracking-tight text-nova-900">{{ t('common.app_name') }}</span>
                    <span v-if="page.props.auth.organization" class="max-w-[10rem] truncate text-sm text-neutral-600 sm:max-w-xs">
                        {{ page.props.auth.organization.name }}
                    </span>
                </div>
                <div class="flex min-w-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:gap-x-3">
                    <Link :href="routes.planning" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
                        {{ t('planning.nav') }}
                    </Link>
                    <Link :href="routes.customers" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
                        {{ t('customers.nav') }}
                    </Link>
                    <Link
                        v-if="page.props.auth.organization?.role !== 'receptionist'"
                        :href="routes.calendars"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                    >
                        {{ t('calendars.nav') }}
                    </Link>
                    <Link
                        v-if="page.props.auth.organization?.role === 'owner'"
                        :href="routes.whatsapp"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                    >
                        {{ t('whatsapp.nav') }}
                    </Link>
                    <Link
                        v-if="page.props.auth.organization?.role === 'owner'"
                        :href="routes.accountData"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                    >
                        {{ t('account_data.nav') }}
                    </Link>
                    <Link :href="routes.security.show" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
                        {{ t('security.nav') }}
                    </Link>
                    <LanguageSwitcher />
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100" @click="logout">
                        {{ t('common.logout') }}
                    </button>
                </div>
            </div>
        </header>

        <main id="contenu" class="mx-auto min-w-0 max-w-6xl px-4 py-8 sm:px-6"><slot /></main>
    </div>
</template>
