<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { extraError } from '../composables/planning';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { PlanningPractitioner, PlanningService, SharedProps, Suggestion } from '../types';
import AlertBox from './AlertBox.vue';
import BaseButton from './BaseButton.vue';
import CheckboxField from './CheckboxField.vue';
import SelectField from './SelectField.vue';
import SlotSuggestions from './SlotSuggestions.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';

const props = defineProps<{
    locationId: string;
    date: string;
    practitioners: PlanningPractitioner[];
    services: PlanningService[];
    seesNotes: boolean;
}>();
const emit = defineEmits<{ done: [] }>();

const { t } = useT();
const page = usePage<SharedProps>();

const form = useForm({
    location_id: props.locationId,
    service_id: '',
    practitioner_id: props.practitioners.length === 1 ? props.practitioners[0].id : '',
    date: props.date,
    time: '',
    phone: '',
    name: '',
    notes: '',
    notify: true,
});

const offering = computed(() => {
    const service = props.services.find((item) => item.id === form.service_id);

    return props.practitioners.filter((practitioner) => service === undefined || service.practitioner_ids.includes(practitioner.id));
});

// Un praticien qui ne réalise pas le service choisi est désélectionné ; s'il n'en reste qu'un, il est choisi d'office.
watch(
    () => form.service_id,
    () => {
        if (!offering.value.some((practitioner) => practitioner.id === form.practitioner_id)) {
            form.practitioner_id = offering.value.length === 1 ? offering.value[0].id : '';
        }
    },
);

const suggestions = computed(() => (extraError(form.errors, 'slot') ? page.props.flash.suggestions : []));

const failure = computed(() => extraError(form.errors, 'slot') ?? extraError(form.errors, 'booking'));

function pick(suggestion: Suggestion): void {
    form.date = suggestion.date;
    form.time = suggestion.time;
    form.practitioner_id = suggestion.practitioner_id;
}

function submit(): void {
    form.post(`${routes.planning}/rendez-vous`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('service_id', 'time', 'phone', 'name', 'notes');
            emit('done');
        },
    });
}
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <AlertBox v-if="failure" variant="error">{{ failure }}</AlertBox>
        <SlotSuggestions :suggestions="suggestions" :practitioners="practitioners" @pick="pick" />

        <SelectField
            v-model="form.service_id"
            :label="t('planning.form.service')"
            :placeholder="t('planning.form.choose')"
            :options="services.map((service) => ({ value: service.id, label: `${service.name} (${service.duration_minutes} min)` }))"
            required
            :error="form.errors.service_id"
        />
        <SelectField
            v-model="form.practitioner_id"
            :label="t('planning.form.practitioner')"
            :placeholder="t('planning.form.choose')"
            :options="offering.map((practitioner) => ({ value: practitioner.id, label: practitioner.name }))"
            required
            :error="form.errors.practitioner_id"
        />
        <div class="grid gap-4 sm:grid-cols-2">
            <TextField v-model="form.date" type="date" :label="t('planning.form.date')" required :error="form.errors.date" />
            <TextField v-model="form.time" type="time" :label="t('planning.form.time')" required :error="form.errors.time" />
        </div>
        <TextField
            v-model="form.phone"
            type="tel"
            inputmode="tel"
            autocomplete="off"
            :label="t('planning.form.phone')"
            :hint="t('planning.form.phone_hint')"
            required
            :error="form.errors.phone"
        />
        <TextField v-model="form.name" :label="t('planning.form.name')" :error="form.errors.name" />
        <TextareaField v-if="seesNotes" v-model="form.notes" :label="t('planning.form.notes')" :maxlength="1000" :error="form.errors.notes" />

        <CheckboxField v-model="form.notify" :label="t('planning.notify')" />

        <BaseButton type="submit" :loading="form.processing">{{ t('planning.form.submit') }}</BaseButton>
    </form>
</template>
