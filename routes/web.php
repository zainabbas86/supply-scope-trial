<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PortfolioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Site root
|--------------------------------------------------------------------------
|
| The domain root is a portfolio page, public and unauthenticated. The
| extraction app lives underneath it at config('site.app_prefix').
|
| `/up` is registered separately in bootstrap/app.php and stays at the root:
| the compose healthcheck and any platform probe have no credentials, and
| moving it under the prefix would only make it easier to get wrong.
|
*/

Route::get('/', PortfolioController::class)->name('portfolio');

/*
|--------------------------------------------------------------------------
| The extraction app
|--------------------------------------------------------------------------
|
| Route NAMES are unchanged. Every redirect and every `route()` call keeps
| working because the prefix is applied here, in one place, rather than
| written into each path. The frontend gets the same value as a shared Inertia
| prop — see HandleInertiaRequests::share().
|
*/

Route::prefix(config('site.app_prefix'))->group(function () {

    /*
    | Guest routes. Only the login screen: there is no self-registration and
    | no password reset, because the single account is provisioned from the
    | environment by `app:ensure-admin`.
    */
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthenticatedSessionController::class, 'create'])
            ->name('login');

        // Throttled per email+IP against credential stuffing. The named
        // limiter is defined in AppServiceProvider.
        Route::post('login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login');
    });

    /*
    | Authenticated routes.
    */
    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');

        // The app's own home, which is NOT the site root any more.
        Route::get('/', [DocumentController::class, 'index'])
            ->name('documents.index');

        // Polled every 2.5s per open tab while anything is in flight, hence its
        // own generous limiter — the default 60/min breaks with three tabs open.
        Route::get('documents/status', [DocumentController::class, 'status'])
            ->middleware('throttle:status')
            ->name('documents.status');

        Route::get('documents/{document}', [DocumentController::class, 'show'])
            ->name('documents.show');

        Route::post('documents/{document}/retry', [DocumentController::class, 'retry'])
            ->middleware('throttle:upload')
            ->name('documents.retry');

        // Throttled as a SPEND control, not a load control: every accepted file
        // is a vision-model call billed to the API key.
        Route::post('documents', UploadDocuments::class)
            ->middleware('throttle:upload')
            ->name('documents.store');
    });
});
