import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Storefront and admin are separate entry points, so builder and
            // admin code never loads on public pages (PRD section 9).
            input: ['resources/css/app.css', 'resources/js/storefront/app.ts', 'resources/js/admin/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
