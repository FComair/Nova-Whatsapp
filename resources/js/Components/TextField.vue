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
        <label :for="id" class="mb-1.5 block text-sm font-semibold text-neutral-900">
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
                class="block w-full rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova placeholder:text-neutral-500 focus:outline-none focus:ring-4 disabled:bg-surface-2 disabled:text-muted"
                :class="[
                    error
                        ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                        : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20',
                    isPassword ? 'pr-20' : '',
                ]"
            />

            <datalist v-if="suggestions?.length" :id="listId">
                <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
            </datalist>

            <button
                v-if="isPassword"
                type="button"
                class="absolute inset-y-0 right-0 rounded-r-control px-3 text-sm font-semibold text-primary-700 hover:text-nova-700"
                :aria-pressed="revealed"
                :aria-label="revealed ? t('common.hide_password') : t('common.show_password')"
                @click="revealed = !revealed"
            >
                <!-- Libellé visible court (pas de chevauchement avec la saisie) ; le nom accessible reste complet. -->
                {{ revealed ? t('common.hide') : t('common.show') }}
            </button>
        </div>

        <p v-if="hint" :id="hintId" class="mt-1.5 text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
