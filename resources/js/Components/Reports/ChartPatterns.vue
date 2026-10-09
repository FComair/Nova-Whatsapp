<script setup lang="ts">
/**
 * Trames SVG des séries : chaque série a sa couleur ET sa trame (pleine, rayures, points, quadrillage), pour rester lisible en
 * niveaux de gris, à l'impression et pour les personnes qui distinguent mal les couleurs.
 */
defineProps<{ id: string; series: { key: string; color: string; pattern: 'solid' | 'stripes' | 'dots' | 'cross' }[] }>();
</script>

<template>
    <defs>
        <template v-for="item in series" :key="item.key">
            <pattern
                v-if="item.pattern === 'stripes'"
                :id="`${id}-${item.key}`"
                width="6"
                height="6"
                patternUnits="userSpaceOnUse"
                patternTransform="rotate(45)"
            >
                <rect width="6" height="6" :fill="item.color" />
                <rect width="2" height="6" fill="#fff" fill-opacity="0.6" />
            </pattern>
            <pattern v-else-if="item.pattern === 'dots'" :id="`${id}-${item.key}`" width="6" height="6" patternUnits="userSpaceOnUse">
                <rect width="6" height="6" :fill="item.color" />
                <circle cx="3" cy="3" r="1.2" fill="#fff" fill-opacity="0.75" />
            </pattern>
            <pattern v-else-if="item.pattern === 'cross'" :id="`${id}-${item.key}`" width="6" height="6" patternUnits="userSpaceOnUse">
                <rect width="6" height="6" :fill="item.color" />
                <path d="M0 0L6 6M6 0L0 6" stroke="#fff" stroke-opacity="0.55" stroke-width="1" />
            </pattern>
        </template>
    </defs>
</template>
