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
            // Build con compilador de plantillas en tiempo de ejecución:
            // necesario para montar componentes Vue escritos directamente en .blade.php.
            vue: 'vue/dist/vue.esm-bundler.js',
            '@': '/resources/js',
        },
    },
});
