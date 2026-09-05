<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | base_url is overridable so a deployed instance can be pointed at a mock,
    | and so the failure path can be exercised by aiming it at an unreachable
    | host without touching code.
    |
    */

    'model' => env('OPENAI_MODEL', 'gpt-5.5'),
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | These three values have a REQUIRED ORDER, and getting it wrong causes
    | duplicate work rather than an error:
    |
    |     http timeout (90s) < job timeout (120s) < queue retry_after (180s)
    |
    | If the job timeout were below the HTTP timeout, the job would be killed
    | mid-call every time. If retry_after were below the job timeout, Redis
    | would redeliver a job that is still running — two workers, one document,
    | two bills.
    |
    | 90s gives roughly 5x headroom on the ~18s a real 3-page sheet measured.
    |
    */

    'connect_timeout' => (int) env('EXTRACTION_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('EXTRACTION_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | A dedicated queue so extraction work can be scaled and drained
    | independently of anything else — the worker container consumes only this.
    |
    */

    'queue' => env('EXTRACTION_QUEUE', 'extraction'),
    'max_tries' => (int) env('EXTRACTION_MAX_TRIES', 3),
    'job_timeout' => (int) env('EXTRACTION_JOB_TIMEOUT', 120),

    'rate_limit_per_minute' => (int) env('EXTRACTION_RATE_LIMIT_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Prompt
    |--------------------------------------------------------------------------
    |
    | Recorded on every attempt so a bad batch of results can be attributed to
    | the prompt change that caused it.
    |
    | v2 adds the prompt-injection framing: the uploaded file is treated
    | explicitly as untrusted DATA rather than as instructions, and the model
    | is told to flag rather than obey anything in it that looks addressed to
    | it. v1 is kept rather than edited so results extracted under it stay
    | attributable — every attempt row records the version that produced it.
    |
    */

    'prompt_version' => env('EXTRACTION_PROMPT_VERSION', 'v2'),
    'schema_version' => 1,

];
