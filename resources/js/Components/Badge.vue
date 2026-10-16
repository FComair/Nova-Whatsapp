<script setup lang="ts">
/**
 * Pastille d'état : icône + libellé, la couleur ne porte jamais seule l'information.
 * `subtle` (défaut) : fond teinté, pour les tableaux ; `solid` : fond plein, pour la seule chose à regarder sur une page.
 */
withDefaults(defineProps<{ variant?: 'success' | 'warning' | 'danger' | 'info' | 'neutral'; solid?: boolean }>(), { variant: 'neutral', solid: false });
</script>

<template>
    <span
        class="inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md px-2 text-xs font-medium ring-1 ring-inset ring-current/15 leading-none"
        :class="{
            'bg-success-bg text-success-fg': variant === 'success' && !solid,
            'bg-warning-bg text-warning-fg': variant === 'warning' && !solid,
            'bg-danger-bg text-danger-fg': variant === 'danger' && !solid,
            'bg-info-bg text-info-fg': variant === 'info' && !solid,
            'bg-neutral-bg text-neutral-fg': variant === 'neutral' && !solid,
            'bg-success-fg text-white': variant === 'success' && solid,
            'bg-warning-fg text-white': variant === 'warning' && solid,
            'bg-danger-fg text-white': variant === 'danger' && solid,
            'bg-info-fg text-white': variant === 'info' && solid,
            'bg-neutral-fg text-white': variant === 'neutral' && solid,
        }"
    >
        <svg
            class="size-3.5 shrink-0"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <template v-if="variant === 'success'">
                <circle cx="8" cy="8" r="6.25" />
                <path d="m5.3 8.2 1.8 1.8 3.6-3.8" />
            </template>
            <template v-else-if="variant === 'warning'">
                <circle cx="8" cy="8" r="6.25" />
                <path d="M8 4.6V8l2.2 1.4" />
            </template>
            <template v-else-if="variant === 'danger'">
                <circle cx="8" cy="8" r="6.25" />
                <path d="M8 4.8v3.6M8 10.9v.01" />
            </template>
            <template v-else-if="variant === 'info'">
                <circle cx="8" cy="8" r="6.25" />
                <path d="M8 7.2v3.6M8 5v.01" />
            </template>
            <template v-else>
                <circle cx="8" cy="8" r="6.25" />
                <path d="M5.5 8h5" />
            </template>
        </svg>
        <slot />
    </span>
</template>
