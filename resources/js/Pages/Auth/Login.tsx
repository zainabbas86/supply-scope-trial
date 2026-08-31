import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

/**
 * The only way into the application.
 *
 * There is no "create an account" link, and that is deliberate rather than
 * unfinished: every upload is a vision-model call billed to the API key, so
 * self-registration would mean anyone could spend it.
 */
export default function Login() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Clear the password on any outcome so a failed attempt never leaves a
        // credential sitting in a form field.
        post('/login', { onFinish: () => reset('password') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-neutral-50 px-4">
            <Head title="Sign in" />

            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <h1 className="text-xl font-semibold text-neutral-900">
                        Label Extraction Agent
                    </h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Sign in to upload and review documents
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm"
                >
                    <div>
                        <label
                            htmlFor="email"
                            className="block text-sm font-medium text-neutral-700"
                        >
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            autoComplete="username"
                            autoFocus
                            required
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
                        />
                        {/* The server returns one message for both "no such user"
                            and "wrong password", so this never reveals whether an
                            email is registered. */}
                        {errors.email && (
                            <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label
                            htmlFor="password"
                            className="block text-sm font-medium text-neutral-700"
                        >
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            autoComplete="current-password"
                            required
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-1 focus:ring-teal-600"
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                        )}
                    </div>

                    <label className="flex items-center gap-2 text-sm text-neutral-600">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-neutral-300 text-teal-600 focus:ring-teal-600"
                        />
                        Remember me
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>

                <p className="mt-4 text-center text-xs text-neutral-400">
                    Access is provisioned by the administrator. There is no self-registration.
                </p>
            </div>
        </div>
    );
}
