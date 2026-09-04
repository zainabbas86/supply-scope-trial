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
            /**
             * The prefix the extraction app is mounted at, e.g.
             * "/labelextractionagent". Set into resources/js/lib/url.ts at boot
             * and read from there — components should call appUrl(), not this.
             */
            appBase: string;
            auth: {
                user: {
                    id: number;
                    name: string;
                    email: string;
                } | null;
            };
            flash: {
                success?: string;
                error?: string;
            };
            rejected: Array<{
                filename: string;
                code: string;
                reason: string;
            }>;
            uploaded: Array<{
                id: string;
                filename: string;
                status: string;
                duplicate_of_existing: boolean;
            }>;
        };
    }
}

export {};
