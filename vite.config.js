import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    build: { outDir: 'dist', chunkSizeWarningLimit: 600 }, // paths.json chunk is ~550 kB raw, lazy-loaded
});
