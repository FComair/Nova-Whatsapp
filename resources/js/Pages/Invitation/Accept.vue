<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';

/**
 * Création du compte d'une personne invitée. Elle choisit son mot de passe et accepte les conditions et la politique de confidentialité
 * (le DPA lie l'entreprise à Nova, pas l'invité). L'acceptation est enregistrée par le serveur avec la version affichée ici, dans la même
 * opération que le compte : si elle échoue, le compte n'est pas créé et la personne peut réessayer.
 */
interface LegalDoc {
    code: 'terms' | 'privacy';
    slug: string;
    title: string;
    version: string | null;
    href: string;
}

const props = defineProps<{ token: string; organization: string; name: string; email: string; legal: { documents: LegalDoc[] } }>();
const { t } = useT();

const form = useForm({
    password: '',
    password_confirmation: '',
    accept_terms: false,
    accept_privacy: false,
    legal_versions: Object.fromEntries(props.legal.documents.map((doc) => [doc.code, doc.version ?? ''])) as Record<string, string>,
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const docs = computed(() => Object.fromEntries(props.legal.documents.map((doc) => [doc.code, doc])) as Record<'terms' | 'privacy', LegalDoc>);
const legalReady = computed(() => props.legal.documents.every((doc) => doc.version !== null));

/** « J'accepte les [Conditions d'utilisation]. » : le texte du modèle est coupé autour de :link, qui devient le lien du document. */
function parts(key: 'terms' | 'privacy'): [string, string] {
    const [before = '', after = ''] = t(`legal.signup.${key}`).split(':link');

    return [before, after];
}

function submit(): void {
    form.post(`/invitation/${props.token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout :title="t('invitation.title', { organization })" :subtitle="t('invitation.body', { name })">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <!-- L'adresse est celle de l'invitation : elle n'est pas modifiable. -->
            <div>
                <p class="mb-1.5 text-sm font-medium text-neutral-800">{{ t('invitation.email') }}</p>
                <p class="rounded-control bg-neutral-100 px-3 py-2.5 text-base text-neutral-800">{{ email }}</p>
            </div>

            <TextField
                v-model="form.password"
                type="password"
                :label="t('invitation.password')"
                :hint="t('register.password_hint')"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />
            <TextField v-model="form.password_confirmation" type="password" :label="t('invitation.password_confirm')" autocomplete="new-password" required />

            <fieldset class="space-y-3 rounded-card border border-line bg-surface p-4" data-testid="legal-acceptance">
                <legend class="px-1 text-sm font-semibold text-nova-900">{{ t('legal.signup.title') }}</legend>
                <AlertBox v-if="!legalReady" variant="error">{{ t('legal.invitation.unavailable') }}</AlertBox>
                <AlertBox v-if="errors.legal" variant="error" data-testid="legal-error">{{ errors.legal }}</AlertBox>
                <CheckboxField
                    v-for="code in ['terms', 'privacy'] as const"
                    :key="code"
                    v-model="form[`accept_${code}`]"
                    :label="docs[code].title"
                    :error="form.errors[`accept_${code}`]"
                >
                    <template #label>
                        {{ parts(code)[0]
                        }}<a :href="docs[code].href" target="_blank" rel="noopener" class="font-semibold text-primary-700 underline underline-offset-2"
                            >{{ docs[code].title }}<span class="sr-only"> {{ t('legal.signup.new_tab') }}</span></a
                        >{{ parts(code)[1] }}
                        <span class="block text-xs text-neutral-600"
                            >{{ t('legal.signup.version', { version: docs[code].version ?? '' }) }} · {{ t('legal.invitation.required') }}</span
                        >
                    </template>
                </CheckboxField>
            </fieldset>

            <BaseButton type="submit" :loading="form.processing" :disabled="!legalReady" class="w-full">{{ t('invitation.submit') }}</BaseButton>
        </form>
    </AuthLayout>
</template>
