<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import SettingsSearch from '../Components/SettingsSearch.vue';
import { focusAnchor, hashAnchor } from '../composables/settingsFocus';
import { activeSettings, settingsGroups, type SettingsItem } from '../composables/settingsNav';
import { useT } from '../composables/useT';
import type { SharedProps } from '../types';
import AppLayout from './AppLayout.vue';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const { t } = useT();

const groups = computed(() => settingsGroups(page.props.auth.organization?.role ?? null));
const items = computed(() => groups.value.flatMap((group) => group.items));
const active = computed(() => activeSettings((page as unknown as { url?: string }).url ?? ''));
const activeHref = computed(() => items.value.find((item) => item.key === active.value)?.href ?? '');

/** Pastille d'état affichée à côté d'une page (fournie par le serveur, sans donnée sensible). */
function status(item: SettingsItem): string | null {
    const shared = page.props.settings;

    if (item.key === 'whatsapp' && shared?.whatsapp_connected) {
        return t('settings.status.connected');
    }

    return item.key === 'security' && shared?.two_factor ? t('settings.status.two_factor') : null;
}

/** « Reste à faire » d'une catégorie de mise en route : nombre de tâches à faire, ou null si tout est fait (une coche s'affiche alors). */
function setupLeft(item: SettingsItem): number | null {
    const entry = item.setup === undefined ? undefined : page.props.settings?.setup?.find((category) => category.key === item.setup);

    return entry === undefined || entry.complete ? null : entry.left;
}

function setupComplete(item: SettingsItem): boolean {
    return item.setup !== undefined && page.props.settings?.setup?.find((category) => category.key === item.setup)?.complete === true;
}

// Arrivée par une adresse avec #section (lien partagé, rechargement) : même mise en évidence qu'après une recherche.
onMounted(() => void focusAnchor(hashAnchor()));

function go(event: Event): void {
    router.visit((event.target as HTMLSelectElement).value);
}
</script>

<template>
    <AppLayout :title="title">
        <div class="flex flex-col gap-5 lg:flex-row lg:gap-12">
            <div class="flex flex-col gap-5 lg:w-60 lg:shrink-0">
                <!-- Recherche : un seul champ au-dessus du menu, quel que soit l'écran. -->
                <SettingsSearch />

                <!-- Téléphone : une liste déroulante groupée. -->
                <div class="md:hidden">
                    <label for="settings-section" class="sr-only">{{ t('settings.menu') }}</label>
                    <select
                        id="settings-section"
                        class="block min-h-12 w-full rounded-control border border-line-strong bg-surface px-3 text-base font-medium text-neutral-900"
                        :value="activeHref"
                        @change="go"
                    >
                        <optgroup v-for="group in groups" :key="group.key" :label="t(`settings.groups.${group.key}`)">
                            <option v-for="item in group.items" :key="item.key" :value="item.href">{{ t(item.label) }}</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Tablette : onglets au-dessus du contenu. -->
                <nav class="hidden border-b border-line md:flex lg:hidden" :aria-label="t('settings.menu')">
                    <Link
                        v-for="item in items"
                        :key="item.key"
                        :href="item.href"
                        class="-mb-px flex min-h-11 items-center gap-2 border-b-[3px] px-4 text-sm font-semibold"
                        :class="item.key === active ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
                        :aria-current="item.key === active ? 'page' : undefined"
                    >
                        {{ t(item.label) }}
                    </Link>
                </nav>

                <!-- Ordinateur : menu vertical groupé, toujours visible. -->
                <nav class="hidden lg:block" :aria-label="t('settings.menu')">
                    <div v-for="(group, index) in groups" :key="group.key" :class="index > 0 ? 'mt-6' : ''">
                        <p class="mb-1.5 px-3 text-xs font-semibold uppercase tracking-wider text-neutral-600">{{ t(`settings.groups.${group.key}`) }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.key">
                                <Link
                                    :href="item.href"
                                    class="flex min-h-11 items-center gap-3 rounded-control px-3 text-sm transition-colors duration-150"
                                    :class="
                                        item.key === active ? 'bg-primary-50 font-semibold text-primary-700' : 'font-medium text-neutral-800 hover:bg-surface-2'
                                    "
                                    :aria-current="item.key === active ? 'page' : undefined"
                                >
                                    <svg
                                        class="size-5 shrink-0"
                                        :class="item.key === active ? 'text-primary-600' : 'text-neutral-500'"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path v-if="item.key === 'setup-business'" d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6" />
                                        <template v-else-if="item.key === 'setup-team'">
                                            <circle cx="9" cy="8" r="3.5" />
                                            <path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2 .7 3.2 2.4 3.5 5.2" />
                                        </template>
                                        <path v-else-if="item.key === 'setup-messages'" d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" />
                                        <template v-else-if="item.key === 'setup-launch'">
                                            <path d="M5 15c-1.5 1.3-2 4-2 6 2 0 4.7-.5 6-2M14 4c3-1.5 6-1.5 7-1 .5 1 .5 4-1 7l-6 6-5-5z" />
                                            <circle cx="15.5" cy="8.5" r="1.4" />
                                        </template>
                                        <template v-else-if="item.key === 'profile'">
                                            <circle cx="12" cy="8" r="4" />
                                            <path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6" />
                                        </template>
                                        <template v-else-if="item.key === 'calendars'">
                                            <rect x="3" y="5" width="18" height="16" rx="3" />
                                            <path d="M8 3v4M16 3v4M3 10h18" />
                                        </template>
                                        <path v-else-if="item.key === 'whatsapp'" d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" />
                                        <template v-else-if="item.key === 'whatsapp-qr'">
                                            <rect x="3" y="3" width="7" height="7" rx="1" />
                                            <rect x="14" y="3" width="7" height="7" rx="1" />
                                            <rect x="3" y="14" width="7" height="7" rx="1" />
                                            <path d="M14 14h3v3M21 14v.01M14 21h3M21 17v4" />
                                        </template>
                                        <template v-else-if="item.key === 'data'">
                                            <ellipse cx="12" cy="6" rx="8" ry="3" />
                                            <path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
                                        </template>
                                        <template v-else>
                                            <rect x="5" y="11" width="14" height="10" rx="2.5" />
                                            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                                        </template>
                                    </svg>
                                    <span class="min-w-0 flex-1 truncate">{{ t(item.label) }}</span>
                                    <span v-if="status(item)" class="shrink-0 rounded-full bg-success-bg px-2 py-0.5 text-xs font-semibold text-success-fg">
                                        {{ status(item) }}
                                    </span>
                                    <span
                                        v-if="setupLeft(item) !== null"
                                        class="shrink-0 rounded-full bg-warning-bg px-2 py-0.5 text-xs font-semibold text-warning-fg"
                                    >
                                        {{ t('settings.status.left', { count: String(setupLeft(item)) }) }}
                                    </span>
                                    <svg
                                        v-if="setupComplete(item)"
                                        class="size-5 shrink-0 text-success-600"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        role="img"
                                        :aria-label="t('setup.completed')"
                                    >
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8 12.5 2.8 2.8L16 10" />
                                    </svg>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>
            </div>

            <div class="min-w-0 flex-1"><slot /></div>
        </div>
    </AppLayout>
</template>
