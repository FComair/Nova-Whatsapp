<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

const props = defineProps<{
    q: string;
    customers: { id: string; name: string | null; phone: string; is_blocked: boolean; last_visit: string | null }[];
    pagination: { page: number; last_page: number; total: number };
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const search = ref(props.q);

function go(query: Record<string, string | number>): void {
    router.get(routes.customers, { q: search.value || undefined, ...query }, { preserveState: true });
}
</script>

<template>
    <AppLayout :title="t('customers.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('customers.title') }}</h1>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>

        <form class="mt-5 flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end" role="search" @submit.prevent="go({ page: 1 })">
            <div class="flex-1"><TextField v-model="search" :label="t('customers.search')" /></div>
            <BaseButton type="submit">{{ t('customers.search_submit') }}</BaseButton>
        </form>

        <AlertBox v-if="!customers.length" variant="info" class="mt-6 max-w-2xl">
            {{ q ? t('customers.no_result') : t('customers.empty') }}
        </AlertBox>

        <ul v-else class="mt-6 divide-y divide-neutral-200 rounded-card border border-neutral-200 bg-white">
            <li v-for="customer in customers" :key="customer.id">
                <Link
                    :href="`${routes.customers}/${customer.id}`"
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 hover:bg-neutral-50"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-neutral-900">{{ customer.name ?? t('customers.unnamed') }}</span>
                        <span class="block text-sm text-neutral-600">{{ customer.phone }}</span>
                    </span>
                    <span class="flex items-center gap-3 text-sm text-neutral-600">
                        <span v-if="customer.is_blocked" class="rounded-full bg-danger-50 px-2.5 py-0.5 text-xs font-semibold text-danger-700">{{
                            t('customers.blocked_badge')
                        }}</span>
                        {{ t('customers.last_visit') }} : {{ customer.last_visit ? formatDate(customer.last_visit) : t('customers.never') }}
                    </span>
                </Link>
            </li>
        </ul>

        <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center gap-3" :aria-label="t('customers.title')">
            <BaseButton variant="secondary" :disabled="pagination.page <= 1" @click="go({ page: pagination.page - 1 })">
                {{ t('customers.previous') }}
            </BaseButton>
            <span class="text-sm text-neutral-600">{{ t('customers.page', { page: pagination.page, total: pagination.last_page }) }}</span>
            <BaseButton variant="secondary" :disabled="pagination.page >= pagination.last_page" @click="go({ page: pagination.page + 1 })">
                {{ t('customers.next') }}
            </BaseButton>
        </nav>
    </AppLayout>
</template>
