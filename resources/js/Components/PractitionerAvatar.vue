<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { initials } from '../composables/customerList';
import { AVATAR_PIXELS, type AvatarSize, photoUrl, sourceSize } from '../composables/practitionerPhoto';
import { useT } from '../composables/useT';

/**
 * Avatar d'un praticien, le même partout : photo recadrée au carré (jamais étirée) en cercle, sinon ses initiales. L'image est
 * chargée à la taille utile (64 ou 256 px), avec ses dimensions fixées d'avance : aucun décalage de la page. Une photo absente
 * ou qui ne se charge pas retombe sur les initiales. Le nom reste toujours affiché à côté : la photo n'identifie jamais seule.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        /** Version de la photo (null : pas de photo). */
        version?: number | null;
        size?: AvatarSize;
        /** Aperçu local (avant enregistrement) : remplace la photo enregistrée. */
        preview?: string | null;
    }>(),
    { version: null, size: 'sm', preview: null },
);

const { t } = useT();
const failed = ref(false);

// Une nouvelle photo (nouvelle version) redonne sa chance à l'image.
watch(
    () => [props.version, props.preview],
    () => (failed.value = false),
);

const src = computed(() => {
    if (props.preview) {
        return props.preview;
    }

    return props.version === null || failed.value ? null : photoUrl(props.id, sourceSize(props.size), props.version);
});
const pixels = computed(() => AVATAR_PIXELS[props.size]);
const classes = computed(
    () =>
        ({
            xs: 'size-5 text-[0.55rem]',
            sm: 'size-7 text-[0.65rem]',
            md: 'size-9 text-xs',
            lg: 'size-14 text-lg',
            xl: 'size-24 text-3xl',
        })[props.size],
);
</script>

<template>
    <span
        class="inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full bg-mint font-bold text-primary-700 ring-1 ring-line"
        :class="classes"
        data-testid="practitioner-avatar"
    >
        <img
            v-if="src"
            :src="src"
            :alt="t('practitioner_photo.alt', { name })"
            :width="pixels"
            :height="pixels"
            loading="lazy"
            decoding="async"
            draggable="false"
            class="size-full object-cover"
            style="object-position: 50% 25%"
            @error="failed = true"
        />
        <span v-else aria-hidden="true" data-testid="practitioner-initials">{{ initials(name, '') }}</span>
    </span>
</template>
