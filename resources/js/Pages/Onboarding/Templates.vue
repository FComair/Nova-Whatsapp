<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import WhatsappPanel from '../../Components/WhatsappPanel.vue';
import type { WhatsappTemplateRow } from '../../Components/WhatsappPanel.vue';
import type { MetaSignupConfig } from '../../composables/metaSignup';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { SharedProps, Wizard } from '../../types';

defineProps<{
    wizard: Wizard;
    account: { phone_number_id: string; waba_id: string | null; display_phone: string | null } | null;
    templates: WhatsappTemplateRow[];
    allApproved: boolean;
    embedded: MetaSignupConfig | null;
}>();

const { t } = useT();
const page = usePage<SharedProps>();

function next(): void {
    router.post(`${routes.onboarding}/messages-types/continuer`);
}
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('whatsapp.templates_title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('whatsapp.templates_title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('whatsapp.step8_body') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>

        <div class="mt-6">
            <WhatsappPanel :account="account" :templates="templates" :all-approved="allApproved" :embedded="embedded" section="templates" />
        </div>

        <div class="mt-6">
            <BaseButton @click="next">{{ allApproved ? t('whatsapp.continue') : t('whatsapp.later') }}</BaseButton>
        </div>
    </OnboardingLayout>
</template>
