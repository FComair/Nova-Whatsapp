<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import PractitionerAvatar from '../../Components/PractitionerAvatar.vue';
import PractitionerPhotoField from '../../Components/PractitionerPhotoField.vue';
import WeekEditor from '../../Components/WeekEditor.vue';
import { toDays, toPayload, type DayState, type TimeRange } from '../../composables/hours';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps } from '../../types';

const props = defineProps<{
    practitioner: { id: string; name: string; color: string; photo_version: number | null; status: string; bookable: boolean; service_ids: string[] };
    services: { id: string; name: string }[];
    locations: { id: string; name: string }[];
    schedules: Record<string, Record<string, TimeRange[]>>;
}>();

const { t } = useT();
const id = useId();
const page = usePage<SharedProps>();

const servicesForm = useForm({ service_ids: [...props.practitioner.service_ids] });

const locationId = ref(props.locations[0]?.id ?? '');
const days = ref<DayState[]>(toDays(props.schedules[locationId.value] ?? {}));
const scheduleForm = useForm({ hours: toPayload(days.value) });

watch(locationId, (id) => {
    days.value = toDays(props.schedules[id] ?? {});
    scheduleForm.clearErrors();
});

const scheduleErrors = computed(() =>
    Array.from({ length: 7 }, (_unused, index) =>
        Object.entries(scheduleForm.errors)
            .filter(([key]) => key.startsWith(`hours.${index}.`))
            .map(([, message]) => message),
    ),
);
const globalError = computed(() => (scheduleForm.errors as Record<string, string>).hours);

function saveSchedule(): void {
    scheduleForm.hours = toPayload(days.value);
    scheduleForm.put(`/mon-profil/horaires/${locationId.value}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('profile.title')">
        <div class="mx-auto max-w-3xl">
            <h1 class="flex items-center gap-2 text-2xl font-semibold text-rendivoo-900">
                <span class="size-4 shrink-0 rounded-full" :style="{ backgroundColor: practitioner.color }" aria-hidden="true" />
                {{ t('profile.title') }}
            </h1>
            <p class="mt-2 flex items-center gap-2 text-neutral-600">
                <PractitionerAvatar :id="practitioner.id" :name="practitioner.name" :version="practitioner.photo_version" size="sm" />
                <span>{{ practitioner.name }} · {{ t('profile.body') }}</span>
            </p>

            <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
            <AlertBox v-if="practitioner.status === 'draft'" variant="warning" class="mt-4">{{ t('profile.draft') }}</AlertBox>
            <AlertBox v-else :variant="practitioner.bookable ? 'success' : 'info'" class="mt-4">
                {{ practitioner.bookable ? t('profile.ready') : t('profile.incomplete') }}
            </AlertBox>

            <section class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5 shadow-sm sm:p-8">
                <PractitionerPhotoField :practitioner="practitioner" upload-url="/mon-profil/photo" remove-url="/mon-profil/photo" />
            </section>

            <section class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-rendivoo-900">{{ t('profile.services') }}</h2>
                <form class="mt-4" novalidate @submit.prevent="servicesForm.put('/mon-profil/services', { preserveScroll: true })">
                    <p v-if="services.length === 0" class="text-sm text-warning-600">{{ t('practitioners.fields.no_services') }}</p>
                    <ul class="space-y-2">
                        <li v-for="service in services" :key="service.id" class="flex items-center gap-3">
                            <input
                                :id="`${id}-${service.id}`"
                                v-model="servicesForm.service_ids"
                                type="checkbox"
                                :value="service.id"
                                class="size-5 rounded border-line-strong text-primary-600"
                            />
                            <label :for="`${id}-${service.id}`" class="text-sm text-neutral-800">{{ service.name }}</label>
                        </li>
                    </ul>
                    <BaseButton type="submit" class="mt-4" :loading="servicesForm.processing">{{ t('profile.services_save') }}</BaseButton>
                </form>
            </section>

            <section class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-rendivoo-900">{{ t('profile.hours') }}</h2>

                <ul v-if="locations.length > 1" class="mt-3 flex flex-wrap gap-2">
                    <li v-for="site in locations" :key="site.id">
                        <button
                            type="button"
                            class="rounded-control border px-3 py-1.5 text-sm font-medium"
                            :class="
                                site.id === locationId
                                    ? 'border-primary-600 bg-primary-50 text-rendivoo-900'
                                    : 'border-line-strong text-neutral-700 hover:bg-surface-2'
                            "
                            :aria-pressed="site.id === locationId"
                            @click="locationId = site.id"
                        >
                            {{ site.name }}
                        </button>
                    </li>
                </ul>

                <AlertBox v-if="globalError" variant="error" class="mt-4">{{ globalError }}</AlertBox>

                <form class="mt-4" novalidate @submit.prevent="saveSchedule">
                    <WeekEditor v-model="days" :errors-by-day="scheduleErrors" />
                    <BaseButton type="submit" class="mt-4" :loading="scheduleForm.processing">{{ t('practitioners.hours_save') }}</BaseButton>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
