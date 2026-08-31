<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Size and count caps
    |--------------------------------------------------------------------------
    |
    | These must stay in step with the PHP limits baked into the Dockerfile
    | (`upload_max_filesize = 20M`, `post_max_size = 200M`, `max_file_uploads`).
    | PHP discards an oversized request body BEFORE any application code runs
    | and hands over an empty $_FILES — so a mismatch here does not surface as
    | a validation message, it surfaces as "my upload silently did nothing".
    |
    */

    'max_file_size_kb' => (int) env('UPLOAD_MAX_FILE_SIZE_KB', 20480),
    'max_files_per_request' => (int) env('UPLOAD_MAX_FILES_PER_REQUEST', 20),

    /*
    |--------------------------------------------------------------------------
    | Accepted types
    |--------------------------------------------------------------------------
    |
    | Extension => the content types that extension is allowed to actually be.
    |
    | Both halves are checked. The extension alone is attacker-controlled, and
    | the browser-supplied Content-Type is too — so the real gate is the type
    | sniffed from the file's own bytes, and it must agree with the extension.
    | An .exe renamed to .pdf fails that agreement.
    |
    */

    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF page cap
    |--------------------------------------------------------------------------
    |
    | NOT a cost control — the API key's spend is not a constraint here. It is a
    | latency and abuse control: at roughly 18 seconds for 3 pages, a 200-page
    | PDF would hold a worker for many minutes and blow the 120s job timeout,
    | failing after having consumed the worker the whole time.
    |
    */

    'max_pdf_pages' => (int) env('UPLOAD_MAX_PDF_PAGES', 15),

    /*
    |--------------------------------------------------------------------------
    | Storage layout
    |--------------------------------------------------------------------------
    |
    | Paths are date-sharded and named by UUID. Never the user's filename: it is
    | attacker-controlled and would allow traversal, collisions, and leaking one
    | user's filename to another. The original name is kept as a column, for
    | display only.
    |
    */

    'path_prefix' => 'documents',

];
