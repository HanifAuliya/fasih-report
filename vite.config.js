import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Tanpa preload: @font-face sudah inline di <head> (@fonts), jadi font langsung ditemukan;
                // preload malah memicu peringatan "not used within a few seconds" (terutama saat wire:navigate)
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                    preload: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
