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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'paddle' => [
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'sandbox' => filter_var(env('PADDLE_SANDBOX', true), FILTER_VALIDATE_BOOL),
        'success_url' => env('PADDLE_SUCCESS_URL', env('FRONTEND_URL', 'http://localhost:5173').'/app?billing=success'),
        'cancel_url' => env('PADDLE_CANCEL_URL', env('FRONTEND_URL', 'http://localhost:5173').'/app/plan?billing=cancel'),
    ],

        'catalog' => [
            'url' => rtrim((string) env('PRODUCT_SERVICE_URL', 'http://127.0.0.1:8001/api/v1'), '/'),
            'token' => env('PRODUCT_SERVICE_TOKEN'),
            'timeout' => (int) env('PRODUCT_SERVICE_TIMEOUT', 8),
            'cache_ttl' => (int) env('PRODUCT_SERVICE_CACHE_TTL', 300),
            'sync_ttl' => (int) env('PRODUCT_SERVICE_SYNC_TTL', 900),
        ],

];
