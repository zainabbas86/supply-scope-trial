<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded administrator
    |--------------------------------------------------------------------------
    |
    | The single account, created or rotated by `php artisan app:ensure-admin`.
    | There is no self-registration: this app has exactly one way in, and it is
    | provisioned from the environment.
    |
    | `password` intentionally has NO default. An unset value must mean "create
    | nothing" — a predictable fallback like `password` on a public host is far
    | worse than having no account at all.
    |
    */

    'admin' => [
        // Stored in the `email` column (Laravel's auth convention) but treated
        // as an opaque identifier — no address format is required or checked.
        'username' => env('ADMIN_USERNAME'),
        'name' => env('ADMIN_NAME', 'Admin'),
        'password' => env('ADMIN_PASSWORD'),
        'min_password_length' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Sized per route against the real traffic shape rather than one blanket
    | number.
    |
    | `status` is deliberately generous: the documents list polls every 2.5s
    | while anything is in flight, which is 24 requests/minute PER OPEN TAB. A
    | single shared 60/min limit looks reasonable and silently breaks the live
    | status view as soon as someone has three tabs open.
    |
    | `upload` is a SPEND control, not a load control — every accepted file is
    | a vision-model call billed to the API key.
    |
    */

    'throttle' => [
        // Two limiters guard the login, deliberately at different sizes:
        //
        //  login     per email+IP, in the controller. The credential-stuffing
        //            bound against ONE account, and the one that produces a
        //            helpful "try again in N seconds" message.
        //  login_ip  per IP, on the route. A cruder flood bound that must sit
        //            ABOVE the per-account limit — set equal, and it always
        //            fires first, so the friendly message never appears and a
        //            whole office behind one NAT is locked out by one person's
        //            typos. (Found by testing: both were 5, and every response
        //            was a generic 429.)
        'login' => (int) env('THROTTLE_LOGIN_PER_MINUTE', 5),
        'login_ip' => (int) env('THROTTLE_LOGIN_IP_PER_MINUTE', 30),
        'upload' => (int) env('THROTTLE_UPLOAD_PER_MINUTE', 20),
        'status' => (int) env('THROTTLE_STATUS_PER_MINUTE', 120),
        'default' => (int) env('THROTTLE_DEFAULT_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily extraction ceiling
    |--------------------------------------------------------------------------
    |
    | A hard cap on extractions started per day. Rate limiting alone bounds the
    | burst, not the total: 20/minute sustained overnight is ~28,000 calls. This
    | is what stops a leaked credential producing a bill nobody authorised.
    |
    */

    'extraction_daily_limit' => (int) env('EXTRACTION_DAILY_LIMIT', 200),

];
