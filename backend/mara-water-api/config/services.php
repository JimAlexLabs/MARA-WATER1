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

    // Round 6: the shared AfriGig M-Pesa STK Push gateway. MARA is one
    // of possibly several client apps of this gateway, never talks to
    // Safaricom/Daraja directly. `mock` defaults true in
    // PaymentGatewayClient whenever `url` is empty, so a fresh
    // environment never tries to call a gateway that isn't configured.
    'payment_gateway' => [
        'url' => env('PAYMENT_GATEWAY_URL'),
        'api_key' => env('PAYMENT_GATEWAY_API_KEY'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'mock' => env('PAYMENT_MOCK', true),
    ],

];
