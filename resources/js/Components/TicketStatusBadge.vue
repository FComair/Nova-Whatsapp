<script setup lang="ts">
import { statusVariant } from '../composables/supportTickets';
import { useT } from '../composables/useT';
import Badge from './Badge.vue';

/** État d'un ticket : icône, libellé, et sa définition en infobulle (title). */
defineProps<{ status: string }>();

const { t } = useT();
</script>

<template>
    <Badge :variant="statusVariant(status)" :title="t(`support.status_help.${status}`)">{{ t(`support.statuses.${status}`) }}</Badge>
</template>
