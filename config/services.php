<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'auth_server' => [
        'base_url'     => env('AUTH_SERVER_BASE_URL', 'http://localhost'),
        'verify_path'  => env('AUTH_TOKEN_VERIFY_PATH', '/auth/token-verify'),
        'service_name' => env('SERVICE_NAME', 'data'),
        'call_token'   => env('SERVICE_CALL_TOKEN'),

        'timeout'   => (int) env('AUTH_TIMEOUT_SECONDS', 2),
        'retries'   => (int) env('AUTH_RETRIES', 2),
        'retry_ms'  => (int) env('AUTH_RETRY_BACKOFF_MS', 100),
        'cache_ttl' => (int) env('AUTH_DECISION_CACHE_TTL', 30),
    ],
];
