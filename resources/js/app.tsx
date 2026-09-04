import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import { setAppBase } from '@/lib/url';

const appName = import.meta.env.VITE_APP_NAME || 'Label Extraction Agent';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    // LOWERCASE `pages`.
    //
    // inertia-laravel's view finder defaults to resource_path('js/pages'), and
    // it is what `assertInertia()->component()` checks against. With a capital
    // P this works on Windows — whose filesystem is case-insensitive — and
    // fails in the Linux container with "page component file does not exist".
    // Matching the library's own convention removes the trap entirely.
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            // The negated pattern is NOT optional. `./pages/**/*.tsx` alone
            // also matches Index.test.tsx and Show.test.tsx — this directory is
            // a glob-resolved route namespace, so anything in it is treated as
            // a page. That shipped 500 KB of Vitest and Testing Library into
            // the production bundle, and made `Documents/Index.test` resolvable
            // as a route.
            import.meta.glob<ResolvedComponent>(
                ['./pages/**/*.tsx', '!./pages/**/*.test.tsx'],
                { import: 'default' },
            ),
        ),

    setup({ el, App, props }) {
        // Before the first render, so no component can build a URL against an
        // empty base. PHP owns this value (config/site.php); the frontend only
        // ever reads it.
        setAppBase(props.initialPage.props.appBase);

        createRoot(el).render(<App {...props} />);
    },

    progress: {
        color: '#0f766e',
    },
});

