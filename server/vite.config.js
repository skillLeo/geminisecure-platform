import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// No Tailwind plugin: the approved wireframes are hand-written CSS and no
// component library or utility framework is permitted.
//
// No bunny() font helper either: fonts are self-hosted through @fontsource
// and imported from resources/css/app.css. Nothing may be fetched from a CDN.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
