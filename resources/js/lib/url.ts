/**
 * Internal URL building for the extraction app.
 *
 * The app is not mounted at the domain root — the root is a portfolio page and
 * the app sits under a prefix (see config/site.php). Every internal link and
 * every `router.post` therefore needs that prefix, and pasting the literal into
 * each call site guarantees that the day it changes, the one that was missed is
 * a 404 nobody notices until a user finds it.
 *
 * The base is set ONCE at boot in app.tsx from the `appBase` shared prop, so
 * PHP remains the single source of truth. It is a module-level value rather
 * than a `usePage()` hook for two reasons:
 *
 *   1. Non-component code can use it. A hook cannot be called from an event
 *      handler or a plain function.
 *   2. The component tests mock `@inertiajs/react` with `useForm` alone.
 *      A hook here would make every one of them crash on an undefined
 *      `usePage`, forcing routing knowledge into tests that are about
 *      rendering.
 *
 * Under Vitest the base stays '' and appUrl('/documents') returns
 * '/documents' — which is what those tests already assert, and correctly so:
 * they verify that the dropzone posts to the documents endpoint, not where
 * that endpoint is mounted. Where it is mounted is verified server-side by the
 * Pest routing tests, and the joining logic itself is covered by url.test.ts.
 */
let base = '';

export function setAppBase(value: string): void {
    if (typeof value !== 'string') {
        /*
         * A missing appBase means the served HTML and the JS bundle disagree
         * about the shared props — a stale server against a fresh build, which
         * is exactly what a bind-mounted dev container does when opcache is
         * still holding the old bytecode.
         *
         * Left alone this is `undefined.replace is not a function` thrown
         * before the first render: a white page, and a stack trace pointing
         * here rather than at the mismatch. Say what is actually wrong.
         */
        throw new Error(
            'Inertia shared prop `appBase` is missing. The page HTML was ' +
                'rendered by a server that does not share it, while this ' +
                'bundle expects it — restart the web container so PHP picks ' +
                'up the current code, then hard-reload.',
        );
    }

    // Trailing slash removed so appUrl() can join with exactly one.
    base = value.replace(/\/+$/, '');
}

export function getAppBase(): string {
    return base;
}

export function appUrl(path = '/'): string {
    const suffix = path.replace(/^\/+/, '');

    return suffix === '' ? base || '/' : `${base}/${suffix}`;
}
