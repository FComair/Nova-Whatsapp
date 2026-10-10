import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        // Le premier montage d'une page importe tout son arbre de composants : à froid, 5 s ne suffisent pas toujours.
        testTimeout: 20000,
    },
});
