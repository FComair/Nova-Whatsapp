<script setup lang="ts">
import { useT } from '../composables/useT';
import { formatDate } from '../composables/dates';
import type { PlanningPractitioner, Suggestion } from '../types';

defineProps<{ suggestions: Suggestion[]; practitioners: PlanningPractitioner[] }>();
const emit = defineEmits<{ pick: [suggestion: Suggestion] }>();

const { t, locale } = useT();

function label(suggestion: Suggestion): string {
    const date = new Date(`${suggestion.date}T12:00:00Z`);
    const day = new Intl.DateTimeFormat(locale.value, { weekday: 'short', timeZone: 'UTC' }).format(date);

    return `${day} ${formatDate(suggestion.date)} ${suggestion.time}`;
}
</script>

<template>
    <div v-if="suggestions.length" class="rounded-lg border border-primary-200 bg-primary-50 p-3">
        <p class="text-sm font-semibold text-nova-900">{{ t('planning.form.suggestions') }}</p>
        <ul class="mt-2 space-y-2">
            <li v-for="suggestion in suggestions" :key="`${suggestion.date}-${suggestion.time}-${suggestion.practitioner_id}`">
                <button
                    type="button"
                    class="min-h-11 w-full rounded-lg border border-primary-300 bg-white px-3 py-2 text-left text-sm font-medium text-primary-700 hover:bg-primary-100"
                    @click="emit('pick', suggestion)"
                >
                    {{ label(suggestion) }} · {{ practitioners.find((p) => p.id === suggestion.practitioner_id)?.name }}
                    <span class="sr-only">{{ t('planning.form.use_suggestion') }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
