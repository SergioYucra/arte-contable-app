import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Deja que Laravel/Vite resuelvan las URLs de assets
                    // en lugar de reescribirlas como imports de módulo.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // Runtime puro de Vue 3 (sin compilador en navegador):
            // previene inyección de plantillas (CSTI) y permite CSP sin 'unsafe-eval'.
            '@': '/resources/js',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('pdfjs-dist')) {
                        return 'vendor-pdf';
                    }
                    if (id.includes('/vue/') || id.includes('/@vue/') || id.includes('/pinia/')) {
                        return 'vendor-vue';
                    }
                    if (id.includes('lucide-vue-next')) {
                        return 'vendor-icons';
                    }
                    if (id.includes('fuse.js') || id.includes('axios')) {
                        return 'vendor-utils';
                    }
                    return undefined;
                },
            },
        },
    },
});
