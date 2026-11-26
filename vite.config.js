import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Storefront and admin are separate entry points, so builder and
            // admin code never loads on public pages (PRD section 9).
            input: ['resources/css/app.css', 'resources/js/storefront/app.ts', 'resources/js/storefront/product.ts', 'resources/js/storefront/checkout.ts', 'resources/js/storefront/order.ts', 'resources/js/storefront/selling.ts', 'resources/js/storefront/chat.ts', 'resources/js/admin/app.tsx', 'resources/js/admin/builder-canvas.ts', 'resources/css/builder-canvas.css', 'resources/css/fonts/manrope.css', 'resources/css/fonts/dm-sans.css', 'resources/css/fonts/lora.css', 'resources/css/fonts/playfair-display.css'],
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
