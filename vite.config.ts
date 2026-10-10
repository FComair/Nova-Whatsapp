import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    // Codespaces : le navigateur joint Vite et l'application par des adresses https de redirection de ports, pas par localhost.
    // post-start.sh écrit ces variables dans .env ; sans elles, tout reste sur localhost (Docker local).
    const env = loadEnv(mode, process.cwd(), '');
    const origin = env.VITE_DEV_SERVER_ORIGIN || 'http://localhost:5173';
    const corsOrigin = env.VITE_CORS_ORIGIN || 'http://localhost:8000';
    const hmr = env.VITE_HMR_HOST
        ? { host: env.VITE_HMR_HOST, protocol: env.VITE_HMR_PROTOCOL || 'wss', clientPort: Number(env.VITE_HMR_CLIENT_PORT || 443) }
        : { host: 'localhost' };

    return {
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
            origin,
            cors: { origin: corsOrigin },
            hmr,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
