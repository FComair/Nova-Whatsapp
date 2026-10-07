<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import BaseButton from './BaseButton.vue';
import SelectField from './SelectField.vue';
import TextField from './TextField.vue';

const props = defineProps<{ practitionerId: string }>();

const { t } = useT();

const form = useForm({ practitioner_id: props.practitionerId, name: '', kind: 'ics', url: '', username: '', password: '' });

function submit(): void {
    form.post(routes.calendars, {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'url', 'username', 'password'),
    });
}
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <TextField v-model="form.name" :label="t('calendars.name')" :maxlength="120" required :error="form.errors.name" />
        <SelectField
            v-model="form.kind"
            :label="t('calendars.kind')"
            :options="[
                { value: 'ics', label: t('calendars.kinds.ics') },
                { value: 'caldav', label: t('calendars.kinds.caldav') },
            ]"
            :error="form.errors.kind"
        />
        <TextField
            v-model="form.url"
            inputmode="text"
            autocomplete="off"
            :label="t('calendars.url')"
            :hint="`${form.kind === 'caldav' ? t('calendars.url_hint_caldav') : t('calendars.url_hint_ics')} ${t('calendars.loop_warning')}`"
            required
            :error="form.errors.url"
        />
        <template v-if="form.kind === 'caldav'">
            <TextField v-model="form.username" autocomplete="off" :label="t('calendars.username')" required :error="form.errors.username" />
            <TextField
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :label="t('calendars.password')"
                required
                :error="form.errors.password"
            />
        </template>
        <BaseButton type="submit" :loading="form.processing">{{ t('calendars.add_submit') }}</BaseButton>
    </form>
</template>
