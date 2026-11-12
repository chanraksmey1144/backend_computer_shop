<?php

$abaPayWayMode = strtolower(trim((string) env(
    'ABA_PAYMENT_MODE',
    env('ABA_PAYWAY_MODE', 'mock')
)));

if (! in_array($abaPayWayMode, ['mock', 'sandbox', 'production'], true)) {
    $abaPayWayMode = 'mock';
}

$abaPayWayBaseUrl = trim((string) env('ABA_PAYWAY_BASE_URL', ''));
$abaPayWayCallbackUrl = trim((string) env('ABA_PAYWAY_CALLBACK_URL', ''));
$abaPayWayQrLifetime = max(3, (int) env('ABA_PAYWAY_QR_LIFETIME', 15));

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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_REDIRECT_URI',
            env('APP_URL', 'http://127.0.0.1:8000').'/api/auth/google/callback'
        ),
    ],

    'aba_payway' => [
        'mode' => $abaPayWayMode,
        'merchant_id' => env('ABA_PAYWAY_MERCHANT_ID'),
        'api_key' => env('ABA_PAYWAY_API_KEY'),
        'base_url' => $abaPayWayBaseUrl !== ''
            ? $abaPayWayBaseUrl
            : ($abaPayWayMode === 'production'
                ? 'https://checkout.payway.com.kh'
                : 'https://checkout-sandbox.payway.com.kh'),
        'callback_url' => $abaPayWayCallbackUrl !== ''
            ? $abaPayWayCallbackUrl
            : rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/payments/aba/callback',
        'return_url' => env(
            'ABA_PAYWAY_RETURN_URL',
            rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/payment/result'
        ),
        'qr_lifetime_minutes' => $abaPayWayQrLifetime,
        'mock_ttl_minutes' => max(1, (int) env('ABA_MOCK_PAYMENT_TTL_MINUTES', 15)),
        'qr_image_template' => env('ABA_PAYWAY_QR_TEMPLATE', 'template3_color'),
    ],

    'bakong' => [
        'mode' => env('BAKONG_MODE', 'sandbox'),
        'token' => env('BAKONG_TOKEN'),
        'base_url' => env('BAKONG_BASE_URL'),
        'account_id' => env('BAKONG_ACCOUNT_ID'),
    ],

];
