<script setup lang="ts">
withDefaults(defineProps<{ variant?: 'success' | 'error' | 'warning' | 'info' }>(), { variant: 'info' });
</script>

<template>
    <!-- Icône + texte : la couleur n'est jamais le seul vecteur d'information. -->
    <div
        class="flex gap-3 rounded-xl border px-4 py-3 text-sm"
        :class="{
            'border-success-fg/25 bg-success-bg text-success-fg': variant === 'success',
            'border-danger-fg/25 bg-danger-bg text-danger-fg': variant === 'error',
            'border-warning-fg/25 bg-warning-bg text-warning-fg': variant === 'warning',
            'border-info-fg/25 bg-info-bg text-info-fg': variant === 'info',
        }"
        :role="variant === 'error' ? 'alert' : 'status'"
    >
        <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
                v-if="variant === 'success'"
                d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z"
            />
            <path v-else-if="variant === 'error'" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM9 6h2v5H9V6Zm0 6.5h2v2H9v-2Z" />
            <path
                v-else-if="variant === 'warning'"
                d="M9.1 3.5a1 1 0 0 1 1.8 0l6.5 11.5A1 1 0 0 1 16.5 16.5h-13a1 1 0 0 1-.9-1.5L9.1 3.5ZM9 8h2v4H9V8Zm0 5h2v2H9v-2Z"
            />
            <path v-else d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-1-9h2v5H9V9Zm0-3h2v2H9V6Z" />
        </svg>
        <div class="min-w-0 break-words"><slot /></div>
    </div>
</template>
