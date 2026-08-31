/**
 * Type augmentation for Inertia's shared props.
 *
 * `@inertiajs/core` exposes an intentionally empty `InertiaConfig` interface as
 * a declaration-merging point. Filling in `sharedPageProps` here means every
 * `usePage()` call in the app is typed without per-call generics.
 *
 * IMPORTANT: this must be kept in step with `HandleInertiaRequests::share()`.
 * Nothing enforces the match — PHP types do not flow into TypeScript — so a
 * renamed key server-side leaves this file happily green while the UI renders
 * `undefined`. That drift is the main risk of Inertia + TS.
 */
declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: {
            auth: {
                user: {
                    id: string;
                    name: string;
                    email: string;
                } | null;
            };
            flash: {
                success?: string;
                error?: string;
            };
        };
    }
}

export {};
