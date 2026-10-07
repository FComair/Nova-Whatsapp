<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AlertBox from '../../Components/AlertBox.vue';
import WhatsappPanel from '../../Components/WhatsappPanel.vue';
import type { WhatsappTemplateRow } from '../../Components/WhatsappPanel.vue';
import type { MetaSignupConfig } from '../../composables/metaSignup';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { SharedProps } from '../../types';

defineProps<{
    account: { phone_number_id: string; waba_id: string | null; display_phone: string | null } | null;
    templates: WhatsappTemplateRow[];
    allApproved: boolean;
    embedded: MetaSignupConfig | null;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
</script>

<template>
    <AppLayout :title="t('whatsapp.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('whatsapp.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('whatsapp.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-2xl">{{ page.props.flash.status }}</AlertBox>

        <div class="mt-6 max-w-3xl">
            <WhatsappPanel :account="account" :templates="templates" :all-approved="allApproved" :embedded="embedded" />
        </div>
    </AppLayout>
</template>
