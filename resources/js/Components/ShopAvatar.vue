<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { logoUrl, shopColor, shopInitials } from '../composables/shopAvatar';
import { useT } from '../composables/useT';
import { routes } from '../routes';

const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        version?: number | null;
        /** « sm » : barre de navigation (32 px) ; « xl » : page Profil (88 px). */
        size?: 'sm' | 'xl';
    }>(),
    { version: null, size: 'sm' },
);

const { t } = useT();
const failed = ref(false);

// Un nouvel envoi (nouvelle version) redonne sa chance à l'image.
watch(
    () => props.version,
    () => (failed.value = false),
);

const src = computed(() => (failed.value ? null : logoUrl(routes.profileLogo, props.size === 'sm' ? 64 : 256, props.version)));
const initials = computed(() => shopInitials(props.name));
const classes = computed(() => (props.size === 'sm' ? 'size-8 text-xs' : 'size-[88px] text-3xl'));
</script>

<template>
    <span
        class="inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full font-bold text-white ring-1 ring-neutral-200"
        :class="classes"
        :style="src ? undefined : { backgroundColor: shopColor(id) }"
    >
        <img v-if="src" :src="src" :alt="t('logo.alt', { name })" class="size-full object-cover" @error="failed = true" />
        <span v-else aria-hidden="true">{{ initials }}</span>
    </span>
</template>
