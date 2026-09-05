<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the extraction app lives
    |--------------------------------------------------------------------------
    |
    | The domain root is a portfolio page; the extraction app sits underneath it
    | at this prefix. One deployment, one container, one certificate.
    |
    | This is deliberately NOT read from the environment.
    |
    | An env-switchable prefix would mean the app answers on '/' locally and
    | '/labelextractionagent' in production — so every route, redirect and
    | hardcoded link would be exercised in a shape that never ships. That is the
    | exact failure mode this project has already hit four times: a lowercase
    | `pages` directory, an absent Vite manifest, a missing tests/Unit, a
    | dev-generated package manifest. Each passed locally and broke elsewhere.
    |
    | Same value everywhere. If it is wrong, it is wrong on your machine too,
    | where you will notice.
    |
    */

    'app_prefix' => 'labelextractionagent',

    /*
    |--------------------------------------------------------------------------
    | Portfolio contact
    |--------------------------------------------------------------------------
    |
    | Shared with the portfolio page. No phone number: this page is public and
    | anything on it is scraped within days, which is trivial to do and
    | impossible to undo.
    |
    */

    'contact' => [
        'email' => env('SITE_CONTACT_EMAIL', 'zainabbas86@hotmail.com'),
        'github' => env('SITE_GITHUB_URL', 'https://github.com/zainabbas86'),
        'linkedin' => env('SITE_LINKEDIN_URL', 'https://www.linkedin.com/in/zain-abbas-64a762a'),
        'bugbugbabies' => env('SITE_BUGBUGBABIES', 'https://www.bugbugbabies.au'),
    ],

];
