<script setup lang="ts">
import { computed } from 'vue';
import { type RuleInput, ruleLines } from '../composables/loyalty';
import { useT } from '../composables/useT';

/** La règle du programme en phrases : gagner, récompense, utilisation. Sert à l'écran de configuration, au tableau de bord et à l'activation. */
const props = defineProps<{ rule: RuleInput }>();
const { t, locale } = useT();
const lines = computed(() => ruleLines(props.rule, t, locale.value));
</script>

<template>
    <div class="space-y-3 text-sm text-neutral-900" data-testid="rule-summary">
        <p class="text-base font-semibold text-nova-900">{{ lines.earn[0] }} {{ lines.reward[0] }}</p>
        <ul class="list-disc space-y-1 pl-5 text-neutral-800">
            <li v-for="line in [...lines.earn.slice(1), ...lines.reward.slice(1), ...lines.use]" :key="line">{{ line }}</li>
        </ul>
    </div>
</template>
