<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
|
| Only the login screen. The `/up` health endpoint is registered separately
| in bootstrap/app.php and is intentionally NOT behind auth — the compose
| healthcheck and any platform probe have no credentials, and putting it
| behind the login would mean the container never reports healthy.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Throttled per email+IP against credential stuffing. The named limiter is
    // defined in AppServiceProvider.
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
|
| Everything else. There is no self-registration and no password reset: the
| single account is provisioned from the environment by `app:ensure-admin`.
|
*/

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::get('/', fn () => Inertia::render('Documents/Index'))
        ->name('documents.index');

    // Throttled as a SPEND control, not a load control: every accepted file is
    // a vision-model call billed to the API key.
    Route::post('documents', UploadDocuments::class)
        ->middleware('throttle:upload')
        ->name('documents.store');
});
