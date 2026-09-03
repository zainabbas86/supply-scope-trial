import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

/*
 * Vitest boots a Vite server internally, which means every plugin's config hook
 * runs during a component test — including plugins that have nothing to do with
 * rendering a component.
 *
 * `laravel-vite-plugin` refuses to start in a CI environment ("You should not
 * run the Vite HMR server in CI"), which is correct: it serves assets to a
 * running Laravel app, and CI has none. The result was a suite that passed
 * locally and failed on GitHub Actions with a startup error.
 *
 * `LARAVEL_BYPASS_ENV_CHECK=1` would silence it, but that suppresses a warning
 * that is right rather than removing the reason for it. Component tests need
 * exactly two things: JSX compilation and the `@` alias. Tailwind is not needed
 * either — the tests assert on class NAMES, never on computed styles.
 */
const isTest = process.env.VITEST !== undefined;

export default defineConfig({
    plugins: [
        // Asset pipeline: build and dev-server only.
        ...(isTest
            ? []
            : [
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
            ]),

        // Required in BOTH modes: without it, JSX does not compile.
        react(),
    ],

    test: {
        // jsdom, not node: these components render DOM and the assertions are
        // about what a user would actually see.
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
