<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Tokens other applications send to read the customer directory (comma-separated, one per application).
    'customer_api' => [
        'tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('CUSTOMER_API_TOKENS', ''))))),
        // These may also write each customer's OLT/ODC/ODP (the FTTH mapping app).
        'write_tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('CUSTOMER_API_WRITE_TOKENS', ''))))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
