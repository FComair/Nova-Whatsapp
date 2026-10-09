<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { usePastTimeGuard } from '../composables/pastTime';
import { extraError } from '../composables/planning';
import { useT } from '../composables/useT';
import { onboardingStep, routes } from '../routes';
import type { PlanningPractitioner, PlanningService, SharedProps, Suggestion } from '../types';
import AlertBox from './AlertBox.vue';
import BaseButton from './BaseButton.vue';
import CheckboxField from './CheckboxField.vue';
import PractitionerSelect from './PractitionerSelect.vue';
import SelectField from './SelectField.vue';
import SlotSuggestions from './SlotSuggestions.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';
import TimeFields from './TimeFields.vue';

const props = defineProps<{
    locationId: string;
    date: string;
    practitioners: PlanningPractitioner[];
    services: PlanningService[];
    seesNotes: boolean;
    /** Fuseau du site : l'heure « maintenant » du formulaire. */
    timezone: string;
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

// Heures passées grisées et refusées, d'après l'heure du site ; recalculé tant que le formulaire reste ouvert.
const past = usePastTimeGuard(
    props.timezone,
    () => form.date,
    () => form.time,
);
// Une erreur du serveur sur la date ou l'heure disparaît dès qu'on la change.
watch(
    () => [form.date, form.time],
    () => form.clearErrors('date', 'time'),
);

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

const selectedService = computed(() => props.services.find((item) => item.id === form.service_id));
// Deux causes à une liste vide : aucun praticien actif, ou le service choisi n'est proposé par personne (le serveur exige ce lien).
const noPractitioners = computed(() => props.practitioners.length === 0);
const nobodyOffers = computed(() => !noPractitioners.value && selectedService.value !== undefined && offering.value.length === 0);
const canSetUp = computed(() => page.props.auth.organization?.role === 'owner');

const suggestions = computed(() => (extraError(form.errors, 'slot') ? page.props.flash.suggestions : []));

const failure = computed(() => extraError(form.errors, 'slot') ?? extraError(form.errors, 'booking'));

function pick(suggestion: Suggestion): void {
    form.date = suggestion.date;
    form.time = suggestion.time;
    form.practitioner_id = suggestion.practitioner_id;
}

function submit(): void {
    // Relit l'heure à l'envoi : une heure qui vient de passer ne part pas.
    if (!past.validNow()) {
        form.setError('time', past.message.value);

        return;
    }

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
        <div class="grid gap-4 sm:grid-cols-2">
            <TextField v-model="form.date" type="date" :label="t('planning.form.date')" required :error="past.dateError.value ?? form.errors.date" />
            <TimeFields
                v-model="form.time"
                :label="t('planning.form.time')"
                required
                :min-minutes="past.minMinutes.value"
                :error="past.timeError.value ?? form.errors.time"
            />
        </div>
        <PractitionerSelect
            v-model="form.practitioner_id"
            :label="t('planning.form.practitioner')"
            :placeholder="t('planning.form.choose')"
            :options="offering.map((practitioner) => ({ value: practitioner.id, label: practitioner.name, photo_version: practitioner.photo_version }))"
            :empty-label="t('planning.form.practitioner_empty')"
            required
            :error="form.errors.practitioner_id"
        />
        <AlertBox v-if="noPractitioners" variant="warning" data-testid="no-practitioners">
            <span>{{ t('planning.form.practitioner_none') }}</span>
            <Link v-if="canSetUp" :href="onboardingStep('praticiens')" class="ml-1 font-semibold underline">{{ t('planning.form.practitioner_assign') }}</Link>
        </AlertBox>
        <AlertBox v-else-if="nobodyOffers" variant="warning" data-testid="nobody-offers">
            <span>{{ t('planning.form.practitioner_none_for_service', { service: selectedService?.name ?? '' }) }}</span>
            <Link v-if="canSetUp" :href="onboardingStep('praticiens')" class="ml-1 font-semibold underline">{{ t('planning.form.practitioner_assign') }}</Link>
        </AlertBox>
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
