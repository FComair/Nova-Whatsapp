<script setup lang="ts">
/**
 * Bouton Nova (même forme que le site : pilule). `md` (44 px) par défaut ; `sm` (36 px) pour les tableaux et barres d'outils :
 * en `sm` pas de soulèvement ni d'ombre au survol (une colonne de boutons qui montent fait du bruit), seule la couleur change.
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
        class="inline-flex w-full items-center justify-center gap-2 rounded-full border-[1.5px] border-transparent font-semibold transition duration-[180ms] ease-nova disabled:cursor-not-allowed disabled:border-transparent disabled:bg-neutral-300 disabled:text-neutral-500 disabled:shadow-none disabled:hover:translate-y-0 sm:w-auto"
        :class="[
            size === 'md' ? 'min-h-11 px-5 py-2 text-[0.9rem]' : 'min-h-9 px-3.5 py-1.5 text-sm',
            {
                'bg-primary-600 text-white hover:bg-primary-700': variant === 'primary',
                'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600 hover:text-primary-600': variant === 'secondary',
                'bg-danger-700 text-white hover:bg-danger-800': variant === 'danger',
                'text-primary-700 hover:bg-primary-50': variant === 'ghost',
                'hover:-translate-y-px hover:shadow-teal': size === 'md' && variant === 'primary',
                'hover:-translate-y-px hover:shadow-md': size === 'md' && (variant === 'secondary' || variant === 'danger'),
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
