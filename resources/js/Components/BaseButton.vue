<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'danger';
        loading?: boolean;
        disabled?: boolean;
    }>(),
    { type: 'button', variant: 'primary', loading: false, disabled: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : undefined"
        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-base font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
        :class="{
            'bg-primary-600 text-white hover:bg-primary-700': variant === 'primary',
            'border border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100': variant === 'secondary',
            'bg-danger-600 text-white hover:bg-danger-700': variant === 'danger',
        }"
    >
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
            <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
