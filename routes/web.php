<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocuments;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

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

    Route::get('/', [DocumentController::class, 'index'])
        ->name('documents.index');

    // Polled every 2.5s per open tab while anything is in flight, hence its own
    // generous limiter — the default 60/min breaks with three tabs open.
    Route::get('documents/status', [DocumentController::class, 'status'])
        ->middleware('throttle:status')
        ->name('documents.status');

    Route::get('documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');

    Route::post('documents/{document}/retry', [DocumentController::class, 'retry'])
        ->middleware('throttle:upload')
        ->name('documents.retry');

    // Throttled as a SPEND control, not a load control: every accepted file is
    // a vision-model call billed to the API key.
    Route::post('documents', UploadDocuments::class)
        ->middleware('throttle:upload')
        ->name('documents.store');
});
