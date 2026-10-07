<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { useT } from '../composables/useT';

const props = withDefaults(
    defineProps<{
        label: string;
        type?: 'text' | 'email' | 'password' | 'tel' | 'date' | 'time';
        error?: string;
        hint?: string;
        autocomplete?: string;
        inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email';
        suggestions?: string[];
        required?: boolean;
        disabled?: boolean;
        maxlength?: number;
    }>(),
    {
        type: 'text',
        required: false,
        disabled: false,
        error: undefined,
        hint: undefined,
        autocomplete: undefined,
        inputmode: undefined,
        maxlength: undefined,
        suggestions: undefined,
    },
);

const model = defineModel<string>({ required: true });
const { t } = useT();

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const listId = `${id}-list`;
const revealed = ref(false);

const isPassword = computed(() => props.type === 'password');
const effectiveType = computed(() => (isPassword.value && revealed.value ? 'text' : props.type));
const describedBy = computed(() => [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-neutral-800">
            {{ label }}<span v-if="required" class="text-danger-600" aria-hidden="true"> *</span>
        </label>

        <div class="relative">
            <input
                :id="id"
                v-model="model"
                :type="effectiveType"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :required="required"
                :disabled="disabled"
                :maxlength="maxlength"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                :list="suggestions?.length ? listId : undefined"
                class="block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:outline-none disabled:bg-neutral-100 disabled:text-neutral-600"
                :class="[error ? 'border-danger-600' : 'border-neutral-300', isPassword ? 'pr-20' : '']"
            />

            <datalist v-if="suggestions?.length" :id="listId">
                <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
            </datalist>

            <button
                v-if="isPassword"
                type="button"
                class="absolute inset-y-0 right-0 rounded-r-lg px-3 text-sm font-medium text-primary-600 hover:text-primary-700"
                :aria-pressed="revealed"
                :aria-label="revealed ? t('common.hide_password') : t('common.show_password')"
                @click="revealed = !revealed"
            >
                <!-- Libellé visible court (pas de chevauchement avec la saisie) ; le nom accessible reste complet. -->
                {{ revealed ? t('common.hide') : t('common.show') }}
            </button>
        </div>

        <p v-if="hint" :id="hintId" class="mt-1.5 text-sm text-neutral-600">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ error }}</p>
    </div>
</template>
