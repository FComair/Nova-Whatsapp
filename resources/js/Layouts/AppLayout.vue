<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import ShopAvatar from '../Components/ShopAvatar.vue';
import { activeSettings, settingsHome } from '../composables/settingsNav';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const { t } = useT();

const inSettings = computed(() => activeSettings((page as unknown as { url?: string }).url ?? '') !== null);

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
                    <span v-if="page.props.auth.organization" class="flex min-w-0 items-center gap-2 border-l border-neutral-200 pl-3">
                        <ShopAvatar
                            :id="page.props.auth.organization.id"
                            :name="page.props.auth.organization.name"
                            :version="page.props.auth.organization.logo_version"
                        />
                        <span class="max-w-[8rem] truncate text-sm font-medium text-neutral-700 sm:max-w-xs">
                            {{ page.props.auth.organization.name }}
                        </span>
                    </span>
                </div>
                <div class="flex min-w-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:gap-x-3">
                    <Link :href="routes.planning" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
                        {{ t('planning.nav') }}
                    </Link>
                    <Link :href="routes.customers" class="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
                        {{ t('customers.nav') }}
                    </Link>
                    <!-- Calendriers, WhatsApp, Mes données et Sécurité vivent désormais sous un seul menu « Réglages ». -->
                    <Link
                        :href="settingsHome()"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium"
                        :class="inSettings ? 'bg-primary-50 text-primary-700' : 'text-neutral-700 hover:bg-neutral-100'"
                        :aria-current="inSettings ? 'page' : undefined"
                    >
                        <svg
                            class="size-4"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.9"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="3" />
                            <path
                                d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"
                            />
                        </svg>
                        {{ t('settings.nav') }}
                    </Link>
                    <LanguageSwitcher />
                    <!-- La déconnexion reste dans la barre principale, hors du menu Réglages : toujours à un clic, sur toutes les pages. -->
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                        @click="logout"
                    >
                        <svg
                            class="size-4"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.9"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10M15 8l4 4-4 4M19 12H9" />
                        </svg>
                        {{ t('common.logout') }}
                    </button>
                </div>
            </div>
        </header>

        <main id="contenu" class="mx-auto min-w-0 max-w-6xl px-4 py-8 sm:px-6"><slot /></main>
    </div>
</template>
