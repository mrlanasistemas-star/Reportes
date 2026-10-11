import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    // Fase 1 (corrección 2026-10-11): el dev server NO debe autoincrementar de
    // puerto en silencio cuando 5173 está ocupado (ver docs/vite-diagnostico.md) —
    // eso fue la causa raíz de que `public/hot` quedara en un puerto distinto al
    // que procesos PHP de larga vida (`php artisan serve`, modo -S) ya tenían
    // cacheado, produciendo URLs de assets apuntando a un Vite que no respondía.
    // `strictPort` falla fuerte y explícito en vez de desplazarse a 5174/5175/...
    // Host fijo en IPv4 (127.0.0.1) — nunca alternar con la forma IPv6 ([::1]).
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
});
