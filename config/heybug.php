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
    | TLS Verification
    |--------------------------------------------------------------------------
    |
    | Set to false when reporting to a self-hosted or proxied endpoint that
    | presents a certificate your PHP installation does not trust. Leave
    | this on when reporting to api.heybug.io.
    |
    */
    'verify_ssl' => env('HEYBUG_VERIFY_SSL', true),

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
    | Lines of source to include on *each side* of the failing line, so the
    | default of 12 produces 25 lines in total: 12 before, the failing line
    | itself, and 12 after. The payload is capped at 50 lines however this
    | is set, so values above 24 have no further effect.
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
    | Keys matching these patterns are replaced with [FILTERED]. Wildcards
    | are supported and matching is case-insensitive.
    |
    | The package always applies its own baseline of patterns — passwords,
    | tokens, secrets, API keys, card numbers — from DataFilter::defaults().
    | Anything listed here is added to that baseline, so a config file
    | published against an older release still picks up patterns added in
    | later ones. Set blacklist_defaults to false to opt out of the
    | baseline entirely and scrub only what you list here.
    |
    */
    'blacklist_defaults' => true,

    'blacklist' => [
        // '*ssn*',
        // '*passport*',
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
