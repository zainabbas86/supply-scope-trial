<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // Shared with EVERY page. The TypeScript shape of this array lives
            // in resources/js/types/inertia.d.ts and is maintained by hand —
            // PHP types do not flow into TypeScript, so renaming a key here
            // leaves the type-checker green while the UI renders undefined.
            // Change one, change the other.
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],

            // Closures are evaluated per request rather than on every partial
            // reload, and reading a flash key consumes it.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            // Per-file upload rejections. Flashed by UploadDocuments so the
            // list can show exactly which files were refused and why —
            // a batch of twenty with one bad file still uploads nineteen.
            'rejected' => fn () => $request->session()->get('rejected', []),

            // Accepted files, including any that were deduplicated. Written by
            // UploadDocuments and previously flashed but never read — so a
            // successful upload said nothing at all, and a deduplicated one
            // appeared instantly complete with no explanation.
            'uploaded' => fn () => $request->session()->get('uploaded', []),
        ];
    }
}
