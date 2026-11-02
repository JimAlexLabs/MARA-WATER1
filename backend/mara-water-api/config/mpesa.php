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
    // Prefer explicit shortcode; fall back to the Paybill number (same value for many tills).
    'shortcode' => env('MPESA_SHORTCODE') ?: env('MPESA_PAYBILL'),
    'passkey' => env('MPESA_PASSKEY'),
    'env' => env('MPESA_ENV', 'sandbox'),
    'callback_url' => env('MPESA_CALLBACK_URL'),
    'paybill' => env('MPESA_PAYBILL') ?: env('MPESA_SHORTCODE'),
    'c2b_confirmation_url' => env('MPESA_C2B_CONFIRMATION_URL'),
    'c2b_validation_url' => env('MPESA_C2B_VALIDATION_URL'),
];
