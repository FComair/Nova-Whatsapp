<script setup lang="ts">
/**
 * Choix d'une heure dans une liste fermée (et non un champ « heure » du navigateur, qui sur téléphone ouvre une roue sans fin 00–23).
 * Les options viennent du parent, déjà bornées (début avant fin, plage du jour) ; la liste est un simple tableau fini.
 */
defineProps<{ id: string; options: string[]; invalid?: boolean }>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <select
        :id="id"
        v-model="model"
        class="rounded-lg border bg-white px-3 py-2 text-base text-neutral-900 focus:border-primary-600 focus:outline-none"
        :class="invalid ? 'border-danger-600' : 'border-neutral-300'"
        :aria-invalid="invalid ? 'true' : undefined"
    >
        <option v-for="option in options" :key="option" :value="option">{{ option }}</option>
    </select>
</template>
