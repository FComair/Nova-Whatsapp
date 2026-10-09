<script setup lang="ts">
/** Icônes des catégories du centre d'aide (même tracé fin que le reste de l'interface). */
defineProps<{ name: string }>();
</script>

<template>
    <svg
        class="size-5"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <template v-if="name === 'rocket'">
            <path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.1-.1-2.9a2.2 2.2 0 0 0-2.9-.1Z" />
            <path d="m12 15-3-3a22 22 0 0 1 2-3.9A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22 22 0 0 1-4 2Z" />
            <path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5" />
        </template>
        <template v-else-if="name === 'calendar'">
            <rect x="3" y="5" width="18" height="16" rx="3" />
            <path d="M8 3v4M16 3v4M3 10h18" />
        </template>
        <template v-else-if="name === 'chat'">
            <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.6A8 8 0 1 1 21 12Z" />
            <path d="M9 11h6M9 14h4" />
        </template>
        <template v-else-if="name === 'users'">
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c2 .7 3.2 2.5 3.5 5.2" />
        </template>
        <template v-else-if="name === 'clock'">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </template>
        <template v-else-if="name === 'card'">
            <rect x="3" y="5" width="18" height="14" rx="3" />
            <path d="M3 10h18M7 15h4" />
        </template>
        <template v-else-if="name === 'shield'">
            <path d="M12 3 4.5 6v5.5c0 4.6 3.1 8 7.5 9.5 4.4-1.5 7.5-4.9 7.5-9.5V6L12 3Z" />
            <path d="m9 12 2.2 2.2L15.5 10" />
        </template>
        <template v-else-if="name === 'wrench'">
            <path d="M14.7 6.3a4 4 0 0 0 5 5L21 10.8A6 6 0 0 1 12.6 4.7L9 8.3 15.7 15 19.3 11.4M4.5 19.5l6-6" />
        </template>
        <template v-else>
            <circle cx="12" cy="12" r="9" />
            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.1.9-1.1 1.8M12 17v.01" />
        </template>
    </svg>
</template>
