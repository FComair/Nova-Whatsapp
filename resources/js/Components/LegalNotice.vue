<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';
import AlertBox from './AlertBox.vue';

/**
 * Avis discret après la connexion (docs/28) : aucune acceptation de nos documents juridiques n'est enregistrée pour ce compte. Il n'empêche rien,
 * se masque pour la session et disparaît dès l'acceptation. Une nouvelle version significative, elle, mène à la page Juridique (pas d'avis ici).
 */
const page = usePage<SharedProps>();
const { t } = useT();
const key = 'nova.legal-notice-dismissed';

function dismissed(): boolean {
    try {
        return window.sessionStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

const hidden = ref(dismissed());
const url = computed(() => (page as unknown as { url?: string }).url ?? '');
const missing = computed(() => (page.props.legal?.pending ?? []).filter((row) => !row.blocking));
const message = computed(() => {
    const titles = missing.value.map((row) => row.title).filter((title) => title !== '');

    // Dit exactement quels documents manquent ; sans titre connu, le message général.
    return titles.length > 0 ? t('legal.notice.missing_named', { documents: titles.join(', ') }) : t('legal.notice.missing');
});
const shown = computed(() => !hidden.value && missing.value.length > 0 && !url.value.startsWith(routes.legalSettings));

function dismiss(): void {
    hidden.value = true;

    try {
        window.sessionStorage.setItem(key, '1');
    } catch {
        // Stockage indisponible : l'avis réapparaîtra à la page suivante, sans conséquence.
    }
}
</script>

<template>
    <AlertBox v-if="shown" variant="info" class="mb-5" data-testid="legal-notice">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span data-testid="legal-notice-text">{{ message }}</span>
            <span class="flex items-center gap-4 font-semibold">
                <Link :href="routes.legalSettings" class="underline underline-offset-2">{{ t('legal.notice.cta') }}</Link>
                <button type="button" class="underline underline-offset-2" @click="dismiss">{{ t('legal.notice.dismiss') }}</button>
            </span>
        </div>
    </AlertBox>
</template>
