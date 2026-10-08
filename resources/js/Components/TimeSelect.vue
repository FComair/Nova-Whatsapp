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
        class="rounded-control border bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-4"
        :class="
            invalid
                ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
        "
        :aria-invalid="invalid ? 'true' : undefined"
    >
        <option v-for="option in options" :key="option" :value="option">{{ option }}</option>
    </select>
</template>
