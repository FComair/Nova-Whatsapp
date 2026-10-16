<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { useT } from '../composables/useT';

/**
 * Heure en deux listes fermées : 24 heures (00 à 23) et 60 minutes (00 à 59). Remplace le champ « heure » du navigateur, qui sur
 * téléphone ouvre une roue qui tourne sans fin. La valeur échangée reste « HH:MM » (vide tant que l'heure n'est pas choisie).
 */
const props = defineProps<{
    label: string;
    required?: boolean;
    error?: string;
    /** Première minute encore choisissable (minutes depuis minuit) : les heures d'avant sont grisées. Null : aucune limite. */
    minMinutes?: number | null;
}>();

const model = defineModel<string>({ required: true });
const { t } = useT();
const id = useId();
const errorId = `${id}-error`;

const pad = (value: number): string => String(value).padStart(2, '0');
const HOURS = Array.from({ length: 24 }, (_unused, index) => pad(index));
const MINUTES = Array.from({ length: 60 }, (_unused, index) => pad(index));

const hour = ref(model.value.slice(0, 2));
const minute = ref(model.value.slice(3, 5));
const describedBy = computed(() => (props.error ? errorId : undefined));

// Valeur remise à zéro ou changée par le parent (formulaire réinitialisé après l'envoi).
watch(model, (value) => {
    hour.value = value.slice(0, 2);
    minute.value = value.slice(3, 5);
});

// Une heure est grisée quand toutes ses minutes sont passées ; dans l'heure en cours, seules les minutes déjà passées le sont.
const hourDisabled = (value: string): boolean => props.minMinutes != null && Number(value) * 60 + 59 < props.minMinutes;
const minuteDisabled = (value: string): boolean => props.minMinutes != null && hour.value !== '' && Number(hour.value) * 60 + Number(value) < props.minMinutes;

function change(): void {
    // Choisir l'heure suffit : les minutes sont à 00 tant qu'on n'en choisit pas d'autres.
    if (hour.value !== '' && minute.value === '') {
        minute.value = MINUTES.find((value) => !minuteDisabled(value)) ?? '00';
    } else if (hour.value !== '' && minuteDisabled(minute.value)) {
        // L'heure choisie est l'heure en cours : on passe à la première minute encore possible plutôt que de garder une minute passée.
        minute.value = MINUTES.find((value) => !minuteDisabled(value)) ?? minute.value;
    }

    model.value = hour.value === '' ? '' : `${hour.value}:${minute.value}`;
}
</script>

<template>
    <fieldset :aria-describedby="describedBy">
        <legend class="mb-1.5 block text-sm font-semibold text-neutral-900">
            {{ label }}<span v-if="required" class="text-danger-600" aria-hidden="true"> *</span>
        </legend>
        <div class="flex items-center gap-2">
            <select
                v-model="hour"
                :aria-label="t('planning.form.hour')"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                class="min-w-0 flex-1 rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-2"
                :class="
                    error
                        ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                        : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
                "
                @change="change"
            >
                <option value="" disabled>--</option>
                <option v-for="value in HOURS" :key="value" :value="value" :disabled="hourDisabled(value)">{{ value }}</option>
            </select>
            <span class="font-semibold text-neutral-700" aria-hidden="true">:</span>
            <select
                v-model="minute"
                :aria-label="t('planning.form.minute')"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                class="min-w-0 flex-1 rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-2"
                :class="
                    error
                        ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                        : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
                "
                @change="change"
            >
                <option value="" disabled>--</option>
                <option v-for="value in MINUTES" :key="value" :value="value" :disabled="minuteDisabled(value)">{{ value }}</option>
            </select>
        </div>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </fieldset>
</template>
