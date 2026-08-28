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

        /*
         * Per-message switches, normally set from Settings -> SMS. Left null
         * here so an unset key falls through to the default in
         * SmsService::EVENTS rather than reading as "off".
         */
        'on_order_placed' => env('SMS_ON_ORDER_PLACED'),
        'on_shipped' => env('SMS_ON_SHIPPED'),
        'on_delivered' => env('SMS_ON_DELIVERED'),
        'on_cancelled' => env('SMS_ON_CANCELLED'),
        'on_returned' => env('SMS_ON_RETURNED'),
        'on_refund' => env('SMS_ON_REFUND'),
        'on_payment_due' => env('SMS_ON_PAYMENT_DUE'),
    ],

];
