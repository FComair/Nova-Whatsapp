<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { LOGO_RULES, loadImage } from '../composables/logoCrop';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';
import BaseButton from './BaseButton.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import LogoCropper from './LogoCropper.vue';

/**
 * Logo de l'établissement : on choisit ou dépose une image, on la cadre (zoom, position, aperçus), puis on enregistre. Le logo
 * actuel reste en place tant qu'on n'a pas enregistré, et en cas d'échec. Le fichier envoyé est l'image recadrée (carrée) ; le
 * serveur revérifie type, poids et dimensions.
 */
const props = defineProps<{ hasLogo: boolean }>();

const { t } = useT();
const page = usePage<SharedProps>();
const input = ref<HTMLInputElement | null>(null);
const source = ref<{ url: string; width: number; height: number; opaque: boolean } | null>(null);
const clientError = ref<string | null>(null);
const confirmRemove = ref(false);
const dragging = ref(false);
const form = useForm<{ logo: File | null }>({ logo: null });
const removing = ref(false);

const error = computed(() => clientError.value ?? form.errors.logo ?? null);
const shopName = computed(() => page.props.auth.organization?.name ?? '');

function discard(): void {
    if (source.value) {
        URL.revokeObjectURL(source.value.url);
    }

    source.value = null;
    form.reset();
    form.clearErrors();

    if (input.value) {
        input.value.value = '';
    }
}

onBeforeUnmount(discard);

/** Contrôle le fichier choisi ou déposé (mêmes règles que le serveur) puis ouvre le recadrage. L'erreur s'affiche tout de suite. */
async function accept(file: File | undefined): Promise<void> {
    discard();
    clientError.value = null;

    if (!file) {
        return;
    }

    if (!['image/png', 'image/jpeg'].includes(file.type)) {
        clientError.value = t('logo.errors.type');

        return;
    }

    if (file.size > LOGO_RULES.maxBytes) {
        clientError.value = t('logo.errors.too_large');

        return;
    }

    const url = URL.createObjectURL(file);
    const size = await loadImage(url);

    if (size === null) {
        URL.revokeObjectURL(url);
        clientError.value = t('logo.errors.invalid');

        return;
    }

    if (size.width < LOGO_RULES.minSide || size.height < LOGO_RULES.minSide) {
        URL.revokeObjectURL(url);
        clientError.value = t('logo.errors.dimensions');

        return;
    }

    if (size.width > LOGO_RULES.maxSide || size.height > LOGO_RULES.maxSide) {
        URL.revokeObjectURL(url);
        clientError.value = t('logo.errors.dimensions_max');

        return;
    }

    source.value = { url, width: size.width, height: size.height, opaque: file.type === 'image/jpeg' };
}

const choose = (event: Event): Promise<void> => accept((event.target as HTMLInputElement).files?.[0]);

function drop(event: DragEvent): void {
    dragging.value = false;
    void accept(event.dataTransfer?.files?.[0]);
}

/** Envoie l'image recadrée. En cas d'échec le recadrage reste ouvert (on peut réessayer) et le logo enregistré est inchangé. */
function save(file: File): void {
    clientError.value = null;
    form.logo = file;
    form.post(routes.profileLogo, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: discard,
        onHttpException: () => {
            clientError.value = t('logo.errors.failed');

            return false;
        },
        onNetworkError: () => {
            clientError.value = t('logo.errors.failed');

            return false;
        },
    });
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
    <div
        class="rounded-control"
        :class="dragging ? 'ring-2 ring-primary-600 ring-offset-4' : ''"
        data-testid="logo-uploader"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="drop"
    >
        <input
            ref="input"
            type="file"
            accept="image/png,image/jpeg"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            data-testid="logo-input"
            @change="choose"
        />

        <LogoCropper
            v-if="source"
            :src="source.url"
            :width="source.width"
            :height="source.height"
            :opaque="source.opaque"
            :name="shopName"
            :processing="form.processing"
            :progress="form.progress ? (form.progress.percentage ?? 0) : null"
            @save="save"
            @cancel="discard"
            @fail="clientError = t('logo.errors.export')"
        />

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
