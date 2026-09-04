import { Link, router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { appUrl } from '@/lib/url';

export default function AppLayout({
    title,
    children,
}: PropsWithChildren<{ title: string }>) {
    const { auth, flash } = usePage().props;

    return (
        <div className="min-h-screen bg-neutral-50">
            <header className="border-b border-neutral-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <Link href={appUrl('/')} className="text-sm font-semibold text-neutral-900">
                        Label Extraction Agent
                    </Link>

                    <div className="flex items-center gap-4 text-sm text-neutral-600">
                        <span>{auth.user?.name}</span>
                        <button
                            type="button"
                            onClick={() => router.post(appUrl('/logout'))}
                            className="rounded-md px-2 py-1 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                        >
                            Sign out
                        </button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-8">
                <h1 className="mb-6 text-lg font-semibold text-neutral-900">{title}</h1>

                {/* role="status" so a screen reader announces a flash that
                    appears after a redirect without stealing focus. */}
                {flash?.success && (
                    <div
                        role="status"
                        className="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                    >
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div
                        role="alert"
                        className="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                    >
                        {flash.error}
                    </div>
                )}

                {children}
            </main>
        </div>
    );
}
