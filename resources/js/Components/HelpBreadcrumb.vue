<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useT } from '../composables/useT';
import { routes } from '../routes';

/** Fil d'Ariane du centre d'aide : Aide › Catégorie › Article (le dernier élément n'est pas un lien). */
defineProps<{ category?: { slug: string; name: string }; current?: string }>();

const { t } = useT();
</script>

<template>
    <nav :aria-label="t('help.breadcrumb')" class="text-sm text-neutral-700">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <li>
                <Link v-if="category || current" :href="routes.help" class="font-semibold text-primary-700 underline underline-offset-4">
                    {{ t('help.title') }}
                </Link>
                <span v-else aria-current="page">{{ t('help.title') }}</span>
            </li>
            <li v-if="category" class="flex items-center gap-2">
                <span aria-hidden="true">›</span>
                <Link v-if="current" :href="`${routes.help}/${category.slug}`" class="font-semibold text-primary-700 underline underline-offset-4">
                    {{ category.name }}
                </Link>
                <span v-else aria-current="page">{{ category.name }}</span>
            </li>
            <li v-if="current" class="flex min-w-0 items-center gap-2">
                <span aria-hidden="true">›</span>
                <span class="truncate" aria-current="page">{{ current }}</span>
            </li>
        </ol>
    </nav>
</template>
