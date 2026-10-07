import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
    ],
    server: {
        // Dans Docker, Vite écoute sur toutes les interfaces (0.0.0.0) mais le NAVIGATEUR doit joindre « localhost » :
        // sans `origin`, le fichier public/hot annonce http://0.0.0.0:5173, inaccessible depuis le navigateur (écran blanc).
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        cors: { origin: 'http://localhost:8000' },
        hmr: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
