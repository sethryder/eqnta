import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Only the weights/subset actually used, and no preload — `preload: true` (the default)
            // forces every weight×unicode-range variant to download up front. With swap, the latin
            // subset lazy-loads on demand instead.
            fonts: [
                bunny('Oswald', { weights: [500, 600, 700], subsets: ['latin'], preload: false }),
                bunny('JetBrains Mono', { weights: [400, 700], subsets: ['latin'], preload: false }),
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
