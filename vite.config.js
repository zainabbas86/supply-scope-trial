import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        // Required for JSX. The package was installed but never registered,
        // so every .tsx file would have failed to compile.
        react(),
    ],
    // Vitest shares the Vite config, so the `@` alias and the React plugin
    // apply to tests without a second setup to keep in sync.
    test: {
        // jsdom, not node: these components render DOM and assert on what a
        // user would actually see.
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
    resolve: {
        // Mirrors the `@/*` path mapping in tsconfig.json. Both are needed:
        // tsconfig teaches the type-checker, this teaches the bundler.
        alias: { '@': '/resources/js' },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
