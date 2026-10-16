<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { allTimezones, setViewerTimezone, viewerTimezone, zoneLabel } from '../composables/timezone';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';
import ModalDialog from './ModalDialog.vue';

const { t } = useT();
const id = useId();

// Sans fuseau détectable ni choix enregistré, on demande avant d'afficher des heures converties.
const dismissed = ref(false);
const manual = ref(false);
const query = ref('');
const chosen = ref('');

const open = computed(() => manual.value || (viewerTimezone.value === null && !dismissed.value));
const zones = computed(() => {
    const needle = query.value.trim().toLowerCase().replaceAll(' ', '_');

    return allTimezones().filter((zone) => zone.toLowerCase().includes(needle));
});

function save(): void {
    if (chosen.value !== '') {
        setViewerTimezone(chosen.value);
    }

    close();
}

function close(): void {
    manual.value = false;
    dismissed.value = true;
}
</script>

<template>
    <button
        v-if="viewerTimezone"
        type="button"
        class="rounded-control border border-line-strong bg-surface px-2.5 py-1.5 text-sm text-neutral-800"
        :title="t('timezone.change')"
        @click="manual = true"
    >
        <span class="sr-only">{{ t('timezone.label') }}: </span>{{ zoneLabel(viewerTimezone) }}
    </button>

    <ModalDialog :open="open" :title="t('timezone.prompt_title')" @close="close">
        <p class="text-sm text-neutral-700">{{ t('timezone.prompt_body') }}</p>
        <label :for="`${id}-q`" class="mt-4 block text-sm font-medium text-neutral-800">{{ t('timezone.search') }}</label>
        <input
            :id="`${id}-q`"
            v-model="query"
            type="search"
            class="mt-1 w-full rounded-control border border-line-strong bg-surface px-3 py-2 text-sm text-neutral-900"
            :placeholder="t('timezone.search_placeholder')"
        />
        <label :for="`${id}-z`" class="mt-3 block text-sm font-medium text-neutral-800">{{ t('timezone.label') }}</label>
        <select
            :id="`${id}-z`"
            v-model="chosen"
            size="8"
            class="mt-1 w-full rounded-control border border-line-strong bg-surface px-2 py-1 text-sm text-neutral-900"
        >
            <option v-for="zone in zones" :key="zone" :value="zone">{{ zone }} ({{ zoneLabel(zone) }})</option>
        </select>
        <div class="mt-4 flex justify-end gap-2">
            <BaseButton variant="secondary" @click="close">{{ t('timezone.later') }}</BaseButton>
            <BaseButton :disabled="chosen === ''" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
    </ModalDialog>
</template>
