<script setup lang="ts">
import { ref } from 'vue';
import { addFiles, type AttachmentLimits, formatSize } from '../composables/supportTickets';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';

/**
 * Pièces jointes d'un formulaire de support : choix, liste avec retrait, vérification immédiate de la taille et du type.
 * Le serveur revérifie tout (type lu dans le contenu du fichier).
 */
const props = defineProps<{ limits: AttachmentLimits; error?: string }>();
const model = defineModel<File[]>({ required: true });

const { t } = useT();
const input = ref<HTMLInputElement | null>(null);
const problem = ref('');

function pick(): void {
    const result = addFiles(model.value, Array.from(input.value?.files ?? []), props.limits);

    model.value = result.files;
    problem.value = result.problem
        ? t(`support.fields.attachments_${result.problem.code}`, {
              name: result.problem.name,
              size: Math.round(props.limits.kilobytes / 1024),
              count: props.limits.files,
          })
        : '';

    if (input.value) {
        input.value.value = '';
    }
}

function remove(index: number): void {
    model.value = model.value.filter((_, position) => position !== index);
    problem.value = '';
}
</script>

<template>
    <div>
        <span class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('support.fields.attachments') }}</span>
        <p class="text-sm text-neutral-700">
            {{ t('support.fields.attachments_hint', { count: limits.files, size: Math.round(limits.kilobytes / 1024), types: limits.types.join(', ') }) }}
        </p>

        <ul v-if="model.length" class="mt-2 space-y-2">
            <li
                v-for="(file, index) in model"
                :key="`${file.name}-${index}`"
                class="flex items-center justify-between gap-3 rounded-control border border-line bg-surface px-3 py-2 text-sm"
            >
                <span class="min-w-0 truncate font-semibold text-neutral-900"
                    >{{ file.name }} <span class="font-normal text-neutral-600">· {{ formatSize(file.size) }}</span></span
                >
                <BaseButton variant="ghost" size="sm" :aria-label="t('support.fields.attachments_remove', { name: file.name })" @click="remove(index)">
                    ×
                </BaseButton>
            </li>
        </ul>

        <div v-if="model.length < limits.files" class="mt-2">
            <input
                ref="input"
                type="file"
                multiple
                accept=".png,.jpg,.jpeg,.pdf,image/png,image/jpeg,application/pdf"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
                @change="pick"
            />
            <BaseButton variant="secondary" class="!border-line-strong" size="sm" @click="input?.click()">{{ t('support.fields.attachments_add') }}</BaseButton>
        </div>

        <p v-if="problem || error" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ problem || error }}</p>
    </div>
</template>
