<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;
use App\Policies\DocumentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters, sized per route.
     *
     * A single blanket `throttle:60,1` is the trap here: it looks reasonable
     * and silently breaks the live status view, because the documents list
     * polls every 2.5s while anything is in flight — 24 requests/minute per
     * open tab, so three tabs exceed it. Limits have to match the traffic
     * shape of each route.
     */
    private function registerRateLimiters(): void
    {
        // A crude per-IP flood bound. Must sit ABOVE the controller's per-email
        // limit, or it fires first and swallows the specific message — and one
        // person's typos lock out everyone sharing their NAT.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('access.throttle.login_ip')
        )->by($request->ip()));

        // A SPEND control, not a load control: every accepted file is a
        // vision-model call billed to the API key.
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(
            (int) config('access.throttle.upload')
        )->by($this->identify($request)));

        // Deliberately generous — see the note above.
        RateLimiter::for('status', fn (Request $request) => Limit::perMinute(
            (int) config('access.throttle.status')
        )->by($this->identify($request)));

        RateLimiter::for('default', fn (Request $request) => Limit::perMinute(
            (int) config('access.throttle.default')
        )->by($this->identify($request)));
    }

    /**
     * Per user once authenticated, per IP before that.
     *
     * Keying purely on IP would make every user behind one office NAT share a
     * single budget.
     */
    private function identify(Request $request): string
    {
        return $request->user()?->getAuthIdentifier()
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }
}
