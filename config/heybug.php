<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DSN (Data Source Name)
    |--------------------------------------------------------------------------
    |
    | Single string to configure HeyBug.
    | Format: https://{api_key}:{project_id}@api.heybug.io/{ingestion-path}
    | Include the ingestion path if your server expects reports at a
    | specific route rather than the bare host.
    |
    */
    'dsn' => env('HEYBUG_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Individual Keys (alternative to DSN)
    |--------------------------------------------------------------------------
    */
    'api_key' => env('HEYBUG_API_KEY'),
    'project_id' => env('HEYBUG_PROJECT_ID'),
    'server' => env('HEYBUG_SERVER', 'https://api.heybug.io'),

    /*
    |--------------------------------------------------------------------------
    | Environment Filtering
    |--------------------------------------------------------------------------
    |
    | Only report exceptions in these environments.
    |
    */
    'environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Exception Filtering
    |--------------------------------------------------------------------------
    |
    | Skip these exception classes.
    |
    */
    'except' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Context
    |--------------------------------------------------------------------------
    |
    | Number of lines around exception line to include (max 50).
    |
    */
    'lines_count' => 12,

    /*
    |--------------------------------------------------------------------------
    | Duplicate Prevention
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before reporting the same exception again.
    | Set to 0 to disable.
    |
    */
    'sleep' => 60,

    /*
    |--------------------------------------------------------------------------
    | User Details
    |--------------------------------------------------------------------------
    |
    | Whether to attach the authenticated user to reports, and which
    | attributes to send. Attributes listed in the model's $hidden are
    | never sent, regardless of this list.
    |
    */
    'send_user' => env('HEYBUG_SEND_USER', true),

    'user_attributes' => ['id', 'name', 'email'],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Data Filtering
    |--------------------------------------------------------------------------
    |
    | Keys matching these patterns will be filtered. Supports wildcards.
    | Patterns are matched against the lowercased key, so they must be
    | narrow enough to avoid eating ordinary fields: "*key*" would also
    | redact "monkey" and "keyword", "*auth*" would redact "author".
    |
    */
    'blacklist' => [
        '*password*',
        '*token*',
        '*secret*',
        '*_key*',
        '*-key*',
        '*apikey*',
        'auth',
        'authorization',
        '*credit*',
        '*card_number*',
        '*cardnumber*',
        '*cvv*',
        '*cvc*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'enabled' => env('HEYBUG_QUEUE_ENABLED', false),
        'track_processing' => false,
        'track_completed' => true,
        'track_failed' => true,
        'only_queues' => [],
        'ignore_queues' => [],
        'ignore_jobs' => [],
    ],
];
