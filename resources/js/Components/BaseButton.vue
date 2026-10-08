<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
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
        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control px-4 py-2.5 text-[0.9rem] font-semibold transition duration-150 ease-nova active:translate-y-px active:shadow-none disabled:cursor-not-allowed disabled:opacity-45 sm:w-auto"
        :class="{
            'bg-primary-600 text-white shadow-xs hover:bg-primary-700 hover:shadow-sm': variant === 'primary',
            'border border-line-strong bg-surface text-neutral-900 shadow-xs hover:border-neutral-500 hover:bg-surface-2': variant === 'secondary',
            'bg-danger-700 text-white shadow-xs hover:bg-danger-800 hover:shadow-sm': variant === 'danger',
            'text-primary-700 hover:bg-primary-50': variant === 'ghost',
        }"
    >
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
            <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
