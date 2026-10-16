<script setup lang="ts">
import { useId } from 'vue';
import { THEME_PREFERENCES, useTheme, type ThemePreference } from '../composables/theme';
import { useT } from '../composables/useT';

const { t } = useT();
const { preference, setPreference } = useTheme();
const id = useId();

function change(event: Event): void {
    setPreference((event.target as HTMLSelectElement).value as ThemePreference);
}
</script>

<template>
    <div class="flex items-center gap-2">
        <label :for="id" class="sr-only">{{ t('common.theme') }}</label>
        <select
            :id="id"
            :value="preference"
            class="rounded-control border border-line-strong bg-surface px-2.5 py-1.5 text-sm text-neutral-800"
            @change="change"
        >
            <option v-for="code in THEME_PREFERENCES" :key="code" :value="code">{{ t(`common.themes.${code}`) }}</option>
        </select>
    </div>
</template>
