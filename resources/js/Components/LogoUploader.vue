<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import BaseButton from './BaseButton.vue';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps<{ hasLogo: boolean }>();

const MAX_BYTES = 2 * 1024 * 1024;
const MIN_SIDE = 200;

const { t } = useT();
const input = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);
const clientError = ref<string | null>(null);
const confirmRemove = ref(false);
const form = useForm<{ logo: File | null }>({ logo: null });
const removing = ref(false);

const error = computed(() => clientError.value ?? form.errors.logo ?? null);

function clearPreview(): void {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = null;
    form.reset();
    form.clearErrors();

    if (input.value) {
        input.value.value = '';
    }
}

onBeforeUnmount(clearPreview);

/** Mesure l'image côté navigateur : l'erreur s'affiche tout de suite, sans envoi. Le serveur revérifie tout. */
function dimensions(url: string): Promise<{ width: number; height: number } | null> {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => resolve(null);
        image.src = url;
    });
}

async function choose(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    clearPreview();
    clientError.value = null;

    if (!file) {
        return;
    }

    if (!['image/png', 'image/jpeg'].includes(file.type)) {
        clientError.value = t('logo.errors.type');

        return;
    }

    if (file.size > MAX_BYTES) {
        clientError.value = t('logo.errors.too_large');

        return;
    }

    const url = URL.createObjectURL(file);
    const size = await dimensions(url);

    if (size === null) {
        URL.revokeObjectURL(url);
        clientError.value = t('logo.errors.invalid');

        return;
    }

    if (size.width < MIN_SIDE || size.height < MIN_SIDE) {
        URL.revokeObjectURL(url);
        clientError.value = t('logo.errors.dimensions');

        return;
    }

    form.logo = file;
    preview.value = url;
}

function save(): void {
    form.post(routes.profileLogo, { forceFormData: true, preserveScroll: true, onSuccess: clearPreview });
}

function remove(): void {
    removing.value = true;
    form.delete(routes.profileLogo, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = false;
            confirmRemove.value = false;
        },
    });
}
</script>

<template>
    <div>
        <input ref="input" type="file" accept="image/png,image/jpeg" class="sr-only" tabindex="-1" aria-hidden="true" @change="choose" />

        <div v-if="preview" class="flex flex-wrap items-center gap-4">
            <span class="inline-flex size-[88px] overflow-hidden rounded-full ring-1 ring-neutral-200">
                <img :src="preview" :alt="t('logo.preview')" class="size-full object-cover" />
            </span>
            <div class="flex flex-wrap gap-2">
                <BaseButton :loading="form.processing" @click="save">
                    {{ form.processing ? t('logo.uploading') : t('logo.save') }}
                </BaseButton>
                <BaseButton variant="secondary" :disabled="form.processing" @click="clearPreview">{{ t('logo.cancel') }}</BaseButton>
            </div>
            <div
                v-if="form.progress"
                class="h-1.5 w-full overflow-hidden rounded-full bg-neutral-200"
                role="progressbar"
                :aria-valuenow="form.progress.percentage ?? 0"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="h-full bg-primary-600" :style="{ width: `${form.progress.percentage ?? 0}%` }" />
            </div>
        </div>

        <div v-else class="flex flex-wrap gap-2">
            <BaseButton variant="secondary" @click="input?.click()">{{ props.hasLogo ? t('logo.change') : t('logo.upload') }}</BaseButton>
            <BaseButton v-if="props.hasLogo" variant="secondary" @click="confirmRemove = true">{{ t('logo.remove') }}</BaseButton>
        </div>

        <p class="mt-2 text-sm text-neutral-600">{{ t('logo.hint') }}</p>
        <p v-if="error" class="mt-2 text-sm font-medium text-red-700" role="alert">{{ error }}</p>

        <ConfirmDialog
            :open="confirmRemove"
            :title="t('logo.remove_title')"
            :body="t('logo.remove_body')"
            :confirm-label="t('logo.remove_confirm')"
            :loading="removing"
            @confirm="remove"
            @cancel="confirmRemove = false"
        />
    </div>
</template>
