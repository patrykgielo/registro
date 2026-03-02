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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID'),
    ],

    'smsapi' => [
        'token' => env('SMSAPI_TOKEN'),
        'service' => env('SMSAPI_SERVICE', 'pl'),
        'webhook_secret' => env('SMSAPI_WEBHOOK_SECRET'),
        'rate_limit_per_minute' => env('SMSAPI_RATE_LIMIT', 60),
    ],

    'sms' => [
        'daily_limit' => env('SMS_DAILY_LIMIT', 500),
        'monthly_limit' => env('SMS_MONTHLY_LIMIT', 10000),
        'alert_threshold' => env('SMS_ALERT_THRESHOLD', 80),
        'alert_email' => env('SMS_ALERT_EMAIL', 'admin@example.com'),
    ],

];
