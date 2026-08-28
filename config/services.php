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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * SMS. The Settings screen is the source of truth for a live shop; these
     * are the fallbacks, and the log_fallback is what makes the whole flow
     * followable locally without a provider account.
     */
    'sms' => [
        'enabled' => env('SMS_ENABLED', false),
        'token' => env('SMS_TOKEN'),
        'greenweb_url' => env('GREENWEB_SMS_URL', 'http://api.greenweb.com.bd/api.php?json'),
        'url' => env('SMS_API_URL'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID'),
        'log_fallback' => env('SMS_LOG_FALLBACK', env('APP_ENV') === 'local'),
    ],

];
