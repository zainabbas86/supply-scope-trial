<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session login for the single seeded account.
 *
 * Hand-rolled rather than Breeze/Fortify: both scaffold self-registration and
 * password reset, which is a mailer plus several routes and views to secure and
 * test. Registration in particular would reopen the exact hole the login
 * closes — anyone could sign themselves up and spend the API key.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        // NOT validated as an email address. This is a single account
        // provisioned from the environment, so the identifier is whatever the
        // operator chose — 'admin' is perfectly reasonable. Demanding an
        // RFC-valid address here adds friction and buys nothing: the only
        // check that matters is whether the credentials match.
        //
        // The column is still called `email` because that is Laravel's auth
        // convention and renaming it would mean a migration for no gain.
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        // Auth::attempt hashes and compares in constant time, and returns the
        // same false for "no such user" and "wrong password" — so the response
        // cannot be used to enumerate which emails exist.
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey($request));

            // Literal strings, not __('auth.failed'): Laravel 11+ ships no
            // lang/ directory, so the translation key would render verbatim to
            // the user. i18n is out of scope (see README), so a real sentence
            // beats a published lang file nobody translates.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        // Session fixation defence, and the single most commonly omitted line
        // in a hand-rolled login. Without it, a session id an attacker planted
        // before login stays valid afterwards and is now authenticated.
        $request->session()->regenerate();

        // route('documents.index'), NOT '/'. The site root is the public
        // portfolio page, so a bare '/' would sign a user in and then drop them
        // outside the app they just authenticated into.
        return redirect()->intended(route('documents.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // invalidate() drops the session data; regenerateToken() issues a new
        // CSRF token. Skipping the second leaves the old token valid against
        // the next session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Throttle on email + IP together.
     *
     * IP alone punishes everyone behind one NAT; email alone lets a botnet
     * spread attempts across addresses. The pair bounds credential stuffing
     * against a specific account without locking out a shared network.
     */
    private function throttleKey(Request $request): string
    {
        return Str::transliterate(
            Str::lower((string) $request->input('email')).'|'.$request->ip()
        );
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        $max = (int) config('access.throttle.login');

        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), $max)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }
}
