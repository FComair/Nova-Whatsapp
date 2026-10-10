<script setup lang="ts">
import { computed, useId } from 'vue';
import { groupCount, sameCapabilities, toggleCapability, type TeamCatalog } from '../composables/team';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';

const props = defineProps<{
    catalog: TeamCatalog;
    /** Capacités de départ du rôle choisi (préréglage). */
    preset: string[];
    /** Libellé du préréglage (« Praticien », « Assistant »). */
    presetLabel: string;
    /** Capacités que la personne connectée peut accorder. */
    grantable: string[];
    isOwner: boolean;
}>();

const model = defineModel<string[]>({ required: true });
const { t } = useT();
const baseId = useId();

const customised = computed(() => !sameCapabilities(model.value, props.preset));
const changes = computed(
    () => model.value.filter((key) => !props.preset.includes(key)).length + props.preset.filter((key) => !model.value.includes(key)).length,
);

function granted(key: string): boolean {
    return model.value.includes(key);
}

/** Une capacité sensible ne se règle que par le propriétaire ; une capacité que l'on n'a pas soi-même ne s'accorde pas. */
function locked(key: string): string | null {
    if (props.catalog.protected.includes(key) && !props.isOwner) {
        return t('team.picker.owner_only');
    }

    return !granted(key) && !props.grantable.includes(key) ? t('team.picker.not_yours') : null;
}

function toggle(key: string, event: Event): void {
    model.value = toggleCapability(model.value, key, (event.target as HTMLInputElement).checked);
}

function setGroup(keys: string[], on: boolean): void {
    let next = model.value;

    for (const key of keys) {
        if (locked(key) === null) {
            next = toggleCapability(next, key, on);
        }
    }

    model.value = next;
}

function reset(): void {
    model.value = [...props.preset];
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-neutral-50 p-3 text-sm" role="status">
            <p class="text-neutral-800">
                <strong>{{ customised ? t('team.picker.custom') : presetLabel }}</strong>
                <span class="text-neutral-600">
                    · {{ customised ? t('team.picker.changes', { count: changes, preset: presetLabel }) : t('team.picker.preset_hint') }}</span
                >
            </p>
            <BaseButton v-if="customised" variant="secondary" size="sm" @click="reset">{{ t('team.picker.reset', { preset: presetLabel }) }}</BaseButton>
        </div>

        <fieldset v-for="(keys, group) in catalog.groups" :key="group" class="rounded-card border border-line bg-surface p-4">
            <legend class="px-1 text-sm font-semibold text-nova-900">
                {{ t(`team.groups.${group}`) }}
                <span class="font-normal text-neutral-600"
                    >· {{ t('team.picker.group_count', { on: groupCount(keys, model).on, total: groupCount(keys, model).total }) }}</span
                >
            </legend>
            <div v-if="keys.length > 1" class="mb-2 flex gap-3 text-xs">
                <button type="button" class="font-medium text-primary-700 underline-offset-2 hover:underline" @click="setGroup(keys, true)">
                    {{ t('team.picker.all') }}
                </button>
                <button type="button" class="font-medium text-primary-700 underline-offset-2 hover:underline" @click="setGroup(keys, false)">
                    {{ t('team.picker.none') }}
                </button>
            </div>
            <ul class="space-y-2.5">
                <li v-for="key in keys" :key="key" class="flex items-start gap-3">
                    <input
                        :id="`${baseId}-${key}`"
                        type="checkbox"
                        class="nova-check mt-0.5"
                        :checked="granted(key)"
                        :disabled="locked(key) !== null"
                        :aria-describedby="`${baseId}-${key}-hint`"
                        @change="toggle(key, $event)"
                    />
                    <div class="min-w-0">
                        <label :for="`${baseId}-${key}`" class="text-sm font-medium text-neutral-900">{{ t(`team.caps.${key}.label`) }}</label>
                        <p :id="`${baseId}-${key}-hint`" class="text-xs text-neutral-600">
                            {{ t(`team.caps.${key}.hint`) }}
                            <span v-if="locked(key)" class="font-medium text-warning-fg"> {{ locked(key) }}</span>
                        </p>
                    </div>
                </li>
            </ul>
        </fieldset>
    </div>
</template>
