<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import ModalDialog from '../../Components/ModalDialog.vue';
import TextareaField from '../../Components/TextareaField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface QrCode {
    id: string;
    name: string;
    message: string;
    color: string;
    show_logo: boolean;
    is_default: boolean;
    link: string | null;
    version: string;
}

const props = defineProps<{
    state: 'not_connected' | 'paused' | 'no_number' | 'ready';
    phone: string | null;
    hasLogo: boolean;
    colors: string[];
    maxCodes: number;
    selected: string | null;
    codes: QrCode[];
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const current = computed(() => props.codes.find((code) => code.id === props.selected) ?? props.codes[0] ?? null);
const form = useForm({ name: '', message: '', color: '', show_logo: false });
const creating = ref(false);
const created = useForm({ name: '', message: '', color: props.colors[0] ?? '', show_logo: false });
const confirmDelete = ref(false);
const removing = ref(false);
const copied = ref(false);

// Le formulaire suit le code affiché : changer de code (ou enregistrer) le remet à jour.
watch(
    current,
    (code) => {
        if (code !== null) {
            form.defaults({ name: code.name, message: code.message, color: code.color, show_logo: code.show_logo }).reset();
        }
    },
    { immediate: true },
);

function image(code: QrCode, format: 'svg' | 'png' | 'pdf', extra = ''): string {
    return `${routes.whatsappQr}/${code.id}/${format}?v=${code.version}${extra}`;
}

function save(): void {
    if (current.value !== null) {
        form.put(`${routes.whatsappQr}/${current.value.id}`, { preserveScroll: true });
    }
}

function create(): void {
    created.post(routes.whatsappQr, {
        onSuccess: () => {
            creating.value = false;
            created.reset();
        },
    });
}

function remove(): void {
    if (current.value === null) {
        return;
    }

    removing.value = true;
    router.delete(`${routes.whatsappQr}/${current.value.id}`, {
        onFinish: () => {
            removing.value = false;
            confirmDelete.value = false;
        },
    });
}

async function copy(): Promise<void> {
    if (current.value?.link == null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(current.value.link);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Presse-papiers refusé : le lien reste sélectionnable dans le champ.
    }
}
</script>

<template>
    <SettingsLayout :title="t('whatsapp_qr.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('whatsapp_qr.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('whatsapp_qr.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-3xl">{{ page.props.flash.status }}</AlertBox>

        <!-- Pas prêt : on explique pourquoi et on renvoie vers la page WhatsApp. Aucun code n'est montré : personne n'imprime un code qui ne mène nulle part. -->
        <section
            v-if="state !== 'ready'"
            class="mt-6 max-w-3xl rounded-card border border-dashed border-line-strong bg-surface p-8"
            :class="state === 'not_connected' ? '' : 'bg-warning-50'"
        >
            <h2 class="text-lg font-semibold text-nova-900">{{ t(`whatsapp_qr.states.${state}.title`) }}</h2>
            <p class="mt-2 max-w-xl text-sm text-neutral-700">{{ t(`whatsapp_qr.states.${state}.body`) }}</p>
            <Link
                :href="routes.whatsapp"
                class="mt-5 inline-flex min-h-11 items-center rounded-control bg-primary-600 px-4 text-base font-semibold text-white hover:bg-primary-700"
            >
                {{ t(`whatsapp_qr.states.${state}.cta`) }}
            </Link>
        </section>

        <template v-else-if="current !== null">
            <!-- Liste des codes -->
            <section class="mt-6 max-w-3xl" aria-labelledby="codes">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="codes" class="text-lg font-semibold text-nova-900">{{ t('whatsapp_qr.codes_title') }}</h2>
                        <p class="mt-1 text-sm text-neutral-600">{{ t('whatsapp_qr.codes_intro') }}</p>
                    </div>
                    <BaseButton v-if="codes.length < maxCodes" variant="secondary" @click="creating = true">+ {{ t('whatsapp_qr.new') }}</BaseButton>
                </div>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    <li v-for="code in codes" :key="code.id">
                        <Link
                            :href="`${routes.whatsappQr}?code=${code.id}`"
                            preserve-scroll
                            class="flex items-center gap-3 rounded-card border bg-surface p-3 hover:bg-surface-2"
                            :class="code.id === current.id ? 'border-primary-600 ring-2 ring-primary-600/20' : 'border-line'"
                            :aria-current="code.id === current.id ? 'true' : undefined"
                        >
                            <img :src="image(code, 'svg')" alt="" class="size-14 shrink-0 rounded border border-line bg-white" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-neutral-900">
                                    {{ code.name }}
                                    <span v-if="code.is_default" class="ml-1 rounded-full bg-success-50 px-2 py-0.5 text-xs font-semibold text-success-600">{{
                                        t('whatsapp_qr.main')
                                    }}</span>
                                </span>
                                <span class="block truncate text-xs text-neutral-600">{{ code.message || '—' }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <div class="mt-6 grid max-w-5xl gap-6 lg:grid-cols-[22rem_1fr]">
                <!-- Aperçu et téléchargements -->
                <section id="download" class="rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="preview">
                    <h2 id="preview" class="text-lg font-semibold text-nova-900">{{ t('whatsapp_qr.your_code') }}</h2>
                    <p v-if="phone" class="mt-1 text-sm text-neutral-600">{{ phone }}</p>
                    <div class="mx-auto mt-4 aspect-square w-full max-w-72 rounded-xl border border-line bg-white p-1.5">
                        <img :src="image(current, 'svg')" :alt="t('whatsapp_qr.preview_alt')" class="size-full" />
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2.5">
                        <a
                            :href="image(current, 'png', '&download=1')"
                            class="flex min-h-11 flex-col items-center justify-center rounded-control bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-700"
                        >
                            {{ t('whatsapp_qr.download_png') }}
                            <span class="text-xs font-normal opacity-80">{{ t('whatsapp_qr.png_size') }}</span>
                        </a>
                        <a
                            :href="image(current, 'svg', '&download=1')"
                            class="flex min-h-11 flex-col items-center justify-center rounded-control border border-line-strong bg-surface px-3 py-1.5 text-sm font-semibold text-neutral-800 hover:bg-surface-2"
                        >
                            {{ t('whatsapp_qr.download_svg') }}
                            <span class="text-xs font-normal text-neutral-600">{{ t('whatsapp_qr.vector') }}</span>
                        </a>
                        <a
                            :href="image(current, 'pdf', '&size=a4&download=1')"
                            class="flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold text-neutral-800 hover:bg-surface-2"
                        >
                            {{ t('whatsapp_qr.download_pdf_a4') }}
                        </a>
                        <a
                            :href="image(current, 'pdf', '&size=a6&download=1')"
                            class="flex min-h-11 items-center justify-center rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold text-neutral-800 hover:bg-surface-2"
                        >
                            {{ t('whatsapp_qr.download_pdf_a6') }}
                        </a>
                    </div>
                </section>

                <div class="space-y-6">
                    <!-- Lien -->
                    <section id="link" class="rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="link-title">
                        <h2 id="link-title" class="text-lg font-semibold text-nova-900">{{ t('whatsapp_qr.link_title') }}</h2>
                        <p class="mt-1 text-sm text-neutral-600">{{ t('whatsapp_qr.link_help') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2.5">
                            <input
                                :value="current.link ?? ''"
                                readonly
                                :aria-label="t('whatsapp_qr.link_label')"
                                class="min-h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-neutral-50 px-3 font-mono text-sm text-neutral-700"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                            <BaseButton variant="secondary" @click="copy">{{ copied ? t('whatsapp_qr.copied') : t('whatsapp_qr.copy') }}</BaseButton>
                        </div>
                    </section>

                    <!-- Réglages du code -->
                    <section id="message" class="rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="message-title">
                        <h2 id="message-title" class="sr-only">{{ t('whatsapp_qr.message_label') }}</h2>
                        <form class="space-y-5" novalidate @submit.prevent="save">
                            <TextField v-model="form.name" :label="t('whatsapp_qr.name_label')" :maxlength="60" :error="form.errors.name" />
                            <TextareaField
                                v-model="form.message"
                                :label="t('whatsapp_qr.message_label')"
                                :hint="t('whatsapp_qr.message_hint')"
                                :maxlength="200"
                                :error="form.errors.message"
                            />
                            <fieldset>
                                <legend class="mb-1.5 block text-sm font-medium text-neutral-800">{{ t('whatsapp_qr.style') }}</legend>
                                <div class="flex items-center gap-3">
                                    <label v-for="color in colors" :key="color" class="cursor-pointer">
                                        <input v-model="form.color" type="radio" name="qr-color" :value="color" class="peer sr-only" />
                                        <span
                                            class="block size-9 rounded-full border-2 border-white shadow ring-1 ring-neutral-300 peer-checked:ring-2 peer-checked:ring-primary-600 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-600"
                                            :style="{ backgroundColor: color }"
                                        />
                                        <span class="sr-only">{{ t(`whatsapp_qr.colors.${color}`) }}</span>
                                    </label>
                                </div>
                                <p class="mt-2 text-sm text-neutral-600">{{ t('whatsapp_qr.dark_only') }}</p>
                                <p v-if="form.errors.color" class="mt-1 text-sm font-medium text-danger-700" role="alert">{{ form.errors.color }}</p>
                            </fieldset>
                            <div>
                                <CheckboxField v-model="form.show_logo" :label="t('whatsapp_qr.logo_toggle')" :error="form.errors.show_logo" />
                                <p v-if="!hasLogo" class="mt-1 pl-8 text-sm text-neutral-600">
                                    <Link :href="routes.profile" class="text-primary-700 underline">{{ t('whatsapp_qr.logo_none') }}</Link>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <BaseButton type="submit" :loading="form.processing" :disabled="!form.isDirty">{{ t('whatsapp_qr.save') }}</BaseButton>
                                <BaseButton v-if="!current.is_default" variant="secondary" @click="confirmDelete = true">
                                    {{ t('whatsapp_qr.delete') }}
                                </BaseButton>
                                <span class="rounded-full bg-success-50 px-3 py-1 text-xs font-semibold text-success-600"
                                    >✓ {{ t('whatsapp_qr.contrast') }}</span
                                >
                            </div>
                            <p v-if="errors.name && !form.errors.name" class="text-sm font-medium text-danger-700" role="alert">{{ errors.name }}</p>
                        </form>
                        <p class="mt-4 border-t border-line pt-4 text-sm text-neutral-600">{{ t('whatsapp_qr.stable') }}</p>
                    </section>
                </div>
            </div>

            <section class="mt-6 max-w-5xl rounded-card border border-primary-600/20 bg-primary-50 p-5" aria-labelledby="tips">
                <h2 id="tips" class="text-base font-semibold text-primary-700">{{ t('whatsapp_qr.tips_title') }}</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-neutral-800">
                    <li>{{ t('whatsapp_qr.tip_size') }}</li>
                    <li>{{ t('whatsapp_qr.tip_places') }}</li>
                    <li>{{ t('whatsapp_qr.tip_caption') }}</li>
                    <li>{{ t('whatsapp_qr.tip_test') }}</li>
                </ul>
            </section>

            <ModalDialog :open="creating" :title="t('whatsapp_qr.new_title')" @close="creating = false">
                <form class="space-y-5" novalidate @submit.prevent="create">
                    <TextField v-model="created.name" :label="t('whatsapp_qr.name_label')" :maxlength="60" required :error="created.errors.name" />
                    <TextareaField
                        v-model="created.message"
                        :label="t('whatsapp_qr.message_label')"
                        :hint="t('whatsapp_qr.message_hint')"
                        :maxlength="200"
                        :error="created.errors.message"
                    />
                    <fieldset>
                        <legend class="mb-1.5 block text-sm font-medium text-neutral-800">{{ t('whatsapp_qr.style') }}</legend>
                        <div class="flex items-center gap-3">
                            <label v-for="color in colors" :key="color" class="cursor-pointer">
                                <input v-model="created.color" type="radio" name="new-qr-color" :value="color" class="peer sr-only" />
                                <span
                                    class="block size-9 rounded-full border-2 border-white shadow ring-1 ring-neutral-300 peer-checked:ring-2 peer-checked:ring-primary-600 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-600"
                                    :style="{ backgroundColor: color }"
                                />
                                <span class="sr-only">{{ t(`whatsapp_qr.colors.${color}`) }}</span>
                            </label>
                        </div>
                    </fieldset>
                    <CheckboxField v-if="hasLogo" v-model="created.show_logo" :label="t('whatsapp_qr.logo_toggle')" :error="created.errors.show_logo" />
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <BaseButton variant="secondary" @click="creating = false">{{ t('whatsapp_qr.cancel') }}</BaseButton>
                        <BaseButton type="submit" :loading="created.processing">{{ t('whatsapp_qr.create') }}</BaseButton>
                    </div>
                </form>
            </ModalDialog>

            <ConfirmDialog
                :open="confirmDelete"
                :title="t('whatsapp_qr.delete_title', { name: current.name })"
                :body="t('whatsapp_qr.delete_body')"
                :confirm-label="t('whatsapp_qr.delete_confirm')"
                :loading="removing"
                @confirm="remove"
                @cancel="confirmDelete = false"
            />
        </template>
    </SettingsLayout>
</template>
