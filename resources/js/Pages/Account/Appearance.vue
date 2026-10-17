<script setup lang="ts">
import { THEME_PREFERENCES, useTheme, type ThemePreference } from '../../composables/theme';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';

/** Réglages › Apparence : le thème s'applique tout de suite et reste enregistré sur cet appareil. */
const { t } = useT();
const { preference, setPreference } = useTheme();

const ICONS: Record<ThemePreference, string> = {
    light: 'M12 3v2m0 14v2M5.6 5.6l1.4 1.4m10 10 1.4 1.4M3 12h2m14 0h2M5.6 18.4 7 17m10-10 1.4-1.4M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
    dark: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
    system: 'M4 5h16v11H4zM9 20h6m-3-4v4',
};
</script>

<template>
    <SettingsLayout :title="t('appearance.title')">
        <h1 class="text-2xl font-semibold text-rendivoo-900">{{ t('appearance.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-700">{{ t('appearance.intro') }}</p>

        <fieldset id="theme" class="mt-6 rounded-card border border-line bg-surface p-5 shadow-xs">
            <legend class="px-1 text-lg font-semibold text-rendivoo-900">{{ t('appearance.theme_title') }}</legend>
            <p class="text-sm text-neutral-700">{{ t('appearance.theme_hint') }}</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <label
                    v-for="code in THEME_PREFERENCES"
                    :key="code"
                    class="flex min-h-14 cursor-pointer items-center gap-3 rounded-control border border-line-strong bg-surface px-4 py-3 text-sm font-medium text-neutral-900 transition-colors hover:bg-surface-2 has-[:checked]:border-primary-600 has-[:checked]:bg-primary-50 has-[:focus-visible]:outline has-[:focus-visible]:outline-[3px] has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600"
                >
                    <input type="radio" name="theme" class="sr-only" :value="code" :checked="preference === code" @change="setPreference(code)" />
                    <svg
                        class="size-5 shrink-0"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path :d="ICONS[code]" />
                    </svg>
                    <span class="min-w-0 flex-1">{{ t(`common.themes.${code}`) }}</span>
                    <svg
                        v-if="preference === code"
                        class="size-4 shrink-0 text-primary-700"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="m5 12.5 4.5 4.5L19 7.5" />
                    </svg>
                </label>
            </div>
            <p class="mt-3 text-sm text-muted">{{ t('appearance.saved_here') }}</p>
        </fieldset>
    </SettingsLayout>
</template>
