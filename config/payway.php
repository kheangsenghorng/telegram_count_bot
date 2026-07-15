<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ABA PayWay environment
    |--------------------------------------------------------------------------
    */

    'base_url' => env(
        'PAYWAY_BASE_URL',
        'https://checkout-sandbox.payway.com.kh'
    ),

    /*
    |--------------------------------------------------------------------------
    | Merchant credentials
    |--------------------------------------------------------------------------
    */

    'merchant_id' => env('PAYWAY_MERCHANT_ID'),

    /*
    |--------------------------------------------------------------------------
    | HMAC API key
    |--------------------------------------------------------------------------
    |
    | This is the secret used to generate the HMAC SHA-512 hash:
    |
    | base64_encode(
    |     hash_hmac('sha512', $beforeHash, $apiKey, true)
    | )
    |
    | It is not the RSA public key.
    |
    */

    'api_key' => env('PAYWAY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | RSA public key
    |--------------------------------------------------------------------------
    |
    | This PEM public key is used to encrypt merchant_auth with
    | openssl_public_encrypt().
    |
    | Recommended:
    | storage/app/payway/public_key.pem
    |
    */

    'rsa_public_key_path' => env(
        'PAYWAY_RSA_PUBLIC_KEY_PATH',
        storage_path('app/payway/public_key.pem')
    ),

    /*
    |--------------------------------------------------------------------------
    | Payment-link callback
    |--------------------------------------------------------------------------
    |
    | PayWay sends a server-to-server POST request to this URL after
    | the customer pays. This is not a browser redirect.
    |
    */

   'callback_url' => env(
    'PAYWAY_CALLBACK_URL',
    rtrim((string) env('APP_URL'), '/')
        .'/api/v1/payway/payment-link/callback'
),

    /*
    |--------------------------------------------------------------------------
    | Laravel payment pages
    |--------------------------------------------------------------------------
    |
    | These are application URLs controlled by Laravel. They are not part
    | of the PayWay payment-link return_url callback behavior.
    |
    */

    'payment_page_url' => env(
        'PAYWAY_PAYMENT_PAGE_URL',
        rtrim((string) env('APP_URL'), '/')
            .'/payway/payments'
    ),

    'success_url' => env(
        'PAYWAY_SUCCESS_URL',
        rtrim((string) env('APP_URL'), '/')
            .'/payway/payment-result'
    ),

    'cancel_url' => env(
        'PAYWAY_CANCEL_URL',
        rtrim((string) env('APP_URL'), '/')
            .'/payway/payment-cancelled'
    ),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    'default_currency' => env(
        'PAYWAY_DEFAULT_CURRENCY',
        'USD'
    ),

    'payment_link_lifetime_minutes' => (int) env(
        'PAYWAY_PAYMENT_LINK_LIFETIME_MINUTES',
        30
    ),

    /*
    |--------------------------------------------------------------------------
    | HTTP settings
    |--------------------------------------------------------------------------
    */

    'connect_timeout' => (int) env(
        'PAYWAY_CONNECT_TIMEOUT',
        10
    ),

    'timeout' => (int) env(
        'PAYWAY_TIMEOUT',
        30
    ),
];