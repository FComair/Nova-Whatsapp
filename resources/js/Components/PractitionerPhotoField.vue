<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { PhotoError, prepareImage } from '../composables/practitionerPhoto';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import PractitionerAvatar from './PractitionerAvatar.vue';

/**
 * Champ « Photo de profil » du praticien. Deux usages :
 * - praticien existant (adresses d'envoi et de retrait fournies) : aperçu, puis « Enregistrer la photo » / « Annuler », et
 *   « Supprimer la photo » avec confirmation. Un envoi qui échoue laisse la photo actuelle en place ;
 * - praticien en cours de création (pas d'adresse) : la photo choisie, avec son aperçu, part avec le formulaire.
 * Le fichier est contrôlé et préparé tout de suite (type, poids, dimensions, WebP converti, orientation corrigée) ; le serveur
 * revérifie tout.
 */
const props = defineProps<{
    practitioner: { id?: string; name: string; photo_version: number | null };
    uploadUrl?: string;
    removeUrl?: string;
}>();
const file = defineModel<File | null>({ default: null });

const { t } = useT();
const input = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);
const pending = ref<File | null>(null);
const clientError = ref<string | null>(null);
const preparing = ref(false);
const saved = ref(false);
const confirmRemove = ref(false);
const removing = ref(false);
const form = useForm<{ photo: File | null }>({ photo: null });

const immediate = computed(() => props.uploadUrl !== undefined);
const hasPhoto = computed(() => props.practitioner.photo_version !== null);
const error = computed(() => clientError.value ?? (form.errors as Record<string, string | undefined>).photo ?? null);
const name = computed(() => props.practitioner.name || t('practitioner_photo.label'));

function discard(): void {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = null;
    pending.value = null;
    file.value = null;
    form.reset();
    form.clearErrors();

    if (input.value) {
        input.value.value = '';
    }
}

onBeforeUnmount(discard);

async function choose(event: Event): Promise<void> {
    const chosen = (event.target as HTMLInputElement).files?.[0];
    discard();
    clientError.value = null;
    saved.value = false;

    if (!chosen) {
        return;
    }

    preparing.value = true;

    try {
        const prepared = await prepareImage(chosen);

        pending.value = prepared;
        preview.value = URL.createObjectURL(prepared);
        file.value = immediate.value ? null : prepared;
    } catch (caught) {
        const code = caught instanceof PhotoError ? caught.code : 'invalid';
        clientError.value = t(`practitioner_photo.errors.${code}`);
    } finally {
        preparing.value = false;
    }
}

function save(): void {
    if (!props.uploadUrl || !pending.value) {
        return;
    }

    form.photo = pending.value;
    form.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            discard();
            saved.value = true;
        },
        onError: () => {
            // La photo actuelle est intacte : on garde l'aperçu pour réessayer ou annuler.
            saved.value = false;
        },
    });
}

function remove(): void {
    if (!props.removeUrl) {
        return;
    }

    removing.value = true;
    router.delete(props.removeUrl, {
        preserveScroll: true,
        onSuccess: () => (saved.value = false),
        onFinish: () => {
            removing.value = false;
            confirmRemove.value = false;
        },
    });
}
</script>

<template>
    <div>
        <span class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('practitioner_photo.label') }}</span>

        <input
            ref="input"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            data-testid="photo-input"
            @change="choose"
        />

        <div class="flex flex-wrap items-center gap-4">
            <PractitionerAvatar :id="practitioner.id ?? 'new'" :name="name" :version="practitioner.photo_version" :preview="preview" size="xl" />

            <div class="min-w-0 flex-1">
                <div v-if="preview" class="flex flex-wrap gap-2">
                    <BaseButton v-if="immediate" :loading="form.processing" @click="save">
                        {{ form.processing ? t('practitioner_photo.uploading') : t('practitioner_photo.save') }}
                    </BaseButton>
                    <BaseButton variant="secondary" class="!border-line-strong" :disabled="form.processing" @click="discard">
                        {{ t('practitioner_photo.cancel') }}
                    </BaseButton>
                </div>
                <div v-else class="flex flex-wrap gap-2">
                    <BaseButton variant="secondary" class="!border-line-strong" :loading="preparing" @click="input?.click()">
                        {{ preparing ? t('practitioner_photo.processing') : hasPhoto ? t('practitioner_photo.change') : t('practitioner_photo.add') }}
                    </BaseButton>
                    <BaseButton v-if="hasPhoto && removeUrl" variant="secondary" class="!border-line-strong" @click="confirmRemove = true">
                        {{ t('practitioner_photo.remove') }}
                    </BaseButton>
                </div>

                <p v-if="preview" class="mt-2 text-sm text-neutral-700" role="status">
                    {{ immediate ? t('practitioner_photo.pending_edit') : t('practitioner_photo.pending') }}
                </p>
                <div
                    v-if="form.progress"
                    class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-neutral-200"
                    role="progressbar"
                    :aria-valuenow="form.progress.percentage ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="h-full bg-primary-600" :style="{ width: `${form.progress.percentage ?? 0}%` }" />
                </div>
            </div>
        </div>

        <p class="mt-2 text-sm text-neutral-700">{{ t('practitioner_photo.hint') }}</p>
        <p
            v-if="saved && !error"
            class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-success-bg px-3 py-1 text-sm font-semibold text-success-fg"
            role="status"
        >
            {{ t('practitioner_photo.saved') }}
        </p>
        <p v-if="error" class="mt-2 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>

        <ConfirmDialog
            :open="confirmRemove"
            :title="t('practitioner_photo.remove_title')"
            :body="t('practitioner_photo.remove_body', { name })"
            :confirm-label="t('practitioner_photo.remove_confirm')"
            :loading="removing"
            @confirm="remove"
            @cancel="confirmRemove = false"
        />
    </div>
</template>
