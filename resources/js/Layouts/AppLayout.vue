<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BackLink from '../Components/BackLink.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
import LegalNotice from '../Components/LegalNotice.vue';
import ShopAvatar from '../Components/ShopAvatar.vue';
import UnsavedChangesDialog from '../Components/UnsavedChangesDialog.vue';
import { backTarget } from '../composables/backNav';
import { activeSettings, settingsHome } from '../composables/settingsNav';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const { t } = useT();

const currentUrl = computed(() => (page as unknown as { url?: string }).url ?? '');

/** Entrée du menu : fond bleu pâle et texte bleu pour la page courante, discret au survol sinon. */
function navClass(active: boolean): string {
    return `flex items-center gap-1.5 rounded-control px-3 py-1.5 text-sm font-medium transition-colors duration-150 ${active ? 'bg-primary-100 text-primary-700' : 'text-neutral-800 hover:bg-surface-2 hover:text-primary-600'}`;
}

// Menu principal selon les permissions du membre (docs/34) : planning, clients et rapports ne s'affichent que s'ils lui sont ouverts.
const can = (...capabilities: string[]): boolean => capabilities.some((capability) => (page.props.auth.organization?.permissions ?? []).includes(capability));
const canSeeReports = computed(() => can('reports.view'));
const canSeePlanning = computed(() => can('calendar.view'));
const canSeeCustomers = computed(() => can('customers.view_own', 'customers.view_all'));
const back = computed(() => backTarget(currentUrl.value));
const inSettings = computed(() => activeSettings((page as unknown as { url?: string }).url ?? '') !== null);

function logout(): void {
    router.post(routes.logout);
}
</script>

<template>
    <Head :title="title" />

    <a href="#contenu" class="sr-only rounded bg-surface px-3 py-2 focus:not-sr-only focus:absolute focus:left-4 focus:top-4">
        {{ t('common.skip_to_content') }}
    </a>

    <div class="min-h-screen bg-canvas">
        <header class="sticky top-0 z-30 border-b border-line bg-canvas/85 backdrop-blur-[14px]">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="font-display text-xl font-bold tracking-tight text-rendivoo-900">{{ t('common.app_name') }}</span>
                    <span v-if="page.props.auth.organization" class="flex min-w-0 items-center gap-2 border-l border-line pl-3">
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
                    <Link
                        :href="routes.dashboard"
                        :class="navClass(currentUrl.startsWith(routes.dashboard))"
                        :aria-current="currentUrl.startsWith(routes.dashboard) ? 'page' : undefined"
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
                            <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
                            <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
                            <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
                            <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
                        </svg>
                        {{ t('dashboard.title') }}
                    </Link>
                    <Link
                        v-if="canSeePlanning"
                        :href="routes.planning"
                        :class="navClass(currentUrl.startsWith(routes.planning))"
                        :aria-current="currentUrl.startsWith(routes.planning) ? 'page' : undefined"
                    >
                        {{ t('planning.nav') }}
                    </Link>
                    <Link
                        v-if="canSeeCustomers"
                        :href="routes.customers"
                        :class="navClass(currentUrl.startsWith(routes.customers))"
                        :aria-current="currentUrl.startsWith(routes.customers) ? 'page' : undefined"
                    >
                        {{ t('customers.nav') }}
                    </Link>
                    <Link
                        v-if="canSeeReports"
                        :href="routes.reports"
                        :class="navClass(currentUrl.startsWith(routes.reports))"
                        :aria-current="currentUrl.startsWith(routes.reports) ? 'page' : undefined"
                    >
                        {{ t('reports.nav') }}
                    </Link>
                    <!-- Calendriers, WhatsApp, Mes données et Sécurité vivent désormais sous un seul menu « Réglages ». -->
                    <Link :href="settingsHome()" :class="navClass(inSettings)" :aria-current="inSettings ? 'page' : undefined">
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
                    <Link
                        :href="routes.help"
                        :class="navClass(currentUrl.startsWith(routes.help))"
                        :aria-current="currentUrl.startsWith(routes.help) ? 'page' : undefined"
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
                            <circle cx="12" cy="12" r="9" />
                            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.1.9-1.1 1.8M12 17v.01" />
                        </svg>
                        {{ t('help.nav') }}
                        <span
                            v-if="(page.props.support?.attention ?? 0) > 0"
                            class="rounded-full bg-primary-600 px-1.5 text-xs font-bold leading-5 text-white"
                            :title="t('support.cta.attention', { count: page.props.support?.attention ?? 0 })"
                            >{{ page.props.support?.attention
                            }}<span class="sr-only"> {{ t('support.cta.attention', { count: page.props.support?.attention ?? 0 }) }}</span></span
                        >
                    </Link>
                    <LanguageSwitcher />
                    <!-- La déconnexion reste dans la barre principale, hors du menu Réglages : toujours à un clic, sur toutes les pages. -->
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-control px-3 py-1.5 text-sm font-medium text-neutral-700 transition-colors duration-150 hover:bg-surface-2 hover:text-neutral-900"
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

        <main id="contenu" class="mx-auto min-w-0 max-w-6xl px-4 py-8 sm:px-6">
            <LegalNotice />
            <BackLink v-if="back" :href="back.href" :label="back.label" />
            <slot />
        </main>
        <UnsavedChangesDialog />
    </div>
</template>
