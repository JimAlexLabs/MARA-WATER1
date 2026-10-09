<?php

return [
    // null = auto: Daraja when keys exist, otherwise test mode.
    'mock' => env('PAYMENT_MOCK'),
    'gateway_url' => env('PAYMENT_GATEWAY_URL'),
    'gateway_key' => env('PAYMENT_GATEWAY_API_KEY'),
    'gateway_anon_key' => env('PAYMENT_GATEWAY_ANON_KEY'),
    'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'shortcode' => env('MPESA_SHORTCODE'),
    'passkey' => env('MPESA_PASSKEY'),
    'env' => env('MPESA_ENV', 'sandbox'),
    'callback_url' => env('MPESA_CALLBACK_URL'),
    'paybill' => env('MPESA_PAYBILL'),
];
