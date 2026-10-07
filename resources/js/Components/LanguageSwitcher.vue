<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useId } from 'vue';
import { routes } from '../routes';
import { useT } from '../composables/useT';
import type { Locale, SharedProps } from '../types';

const page = usePage<SharedProps>();
const { t } = useT();
const id = useId();

function change(event: Event): void {
    const locale = (event.target as HTMLSelectElement).value as Locale;

    router.post(routes.locale, { locale }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="flex items-center gap-2">
        <label :for="id" class="sr-only">{{ t('common.language') }}</label>
        <select
            :id="id"
            :value="page.props.locale"
            class="rounded-lg border border-neutral-300 bg-white px-2.5 py-1.5 text-sm text-neutral-800"
            @change="change"
        >
            <option v-for="code in page.props.locales" :key="code" :value="code">{{ t(`common.languages.${code}`) }}</option>
        </select>
    </div>
</template>
