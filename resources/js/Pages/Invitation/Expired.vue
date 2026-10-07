<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const { t } = useT();
</script>

<template>
    <!-- Erreur E13 : même écran pour un lien expiré, déjà utilisé, remplacé ou inconnu. -->
    <AuthLayout :title="t('invitation.expired_title')" :subtitle="t('invitation.expired_body')">
        <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('invitation.to_login') }}</Link>
    </AuthLayout>
</template>
