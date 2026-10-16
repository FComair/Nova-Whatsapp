<script setup lang="ts">
/**
 * Bouton Nova, forme et interactions alignées sur le Super Admin (Filament) : coins 8 px, ombre légère, aucun soulèvement au survol, seule la couleur change. `md` (40 px) par défaut ; `sm` (32 px) pour les tableaux et barres d'outils.
 */
withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'md' | 'sm';
        loading?: boolean;
        disabled?: boolean;
    }>(),
    { type: 'button', variant: 'primary', size: 'md', loading: false, disabled: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : undefined"
        class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-transparent font-semibold transition duration-150 ease-nova disabled:cursor-not-allowed disabled:border-transparent disabled:bg-neutral-300 disabled:text-neutral-500 disabled:shadow-none sm:w-auto"
        :class="[
            size === 'md' ? 'min-h-10 px-4 py-2 text-sm' : 'min-h-8 px-3 py-1 text-sm',
            {
                'bg-primary-600 text-white shadow-xs hover:bg-primary-700': variant === 'primary',
                'border-line-strong bg-surface text-neutral-900 shadow-xs hover:bg-surface-2': variant === 'secondary',
                'bg-danger-700 text-white shadow-xs hover:bg-danger-800': variant === 'danger',
                'text-primary-700 hover:bg-primary-50': variant === 'ghost',
            },
        ]"
    >
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
            <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
