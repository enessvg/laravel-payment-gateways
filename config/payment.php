<?php

use App\Services\Payment\IyzicoGateway;
use App\Services\Payment\PaynetGateway;
use App\Services\Payment\PaytrGateway;

return [
    'default' => env('PAYMENT_GATEWAY', 'paynet'),

    'gateways' => [
        'paynet' => [
            'driver' => PaynetGateway::class,
            'domain' => env('PAYNET_DOMAIN', 'localhost:9000'),
            'return_url' => env('PAYNET_RETURN_URL', 'http://localhost:9000/api/payment/callback'),
        ],

        'iyzico' => [
            'driver' => IyzicoGateway::class,

            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
            'return_url' => env('IYZICO_RETURN_URL', 'http://localhost:9000/api/payment/callback'),
        ],

        'paytr' => [
            'driver' => PaytrGateway::class,

            'merchant_id' => env('PAYTR_MERCHANT_ID'),
            'merchant_key' => env('PAYTR_MERCHANT_KEY'),
            'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
            'transport' => env('PAYTR_TRANSPORT', 'browser_post'),
            'payment_url' => env('PAYTR_PAYMENT_URL', 'https://www.paytr.com/odeme'),
            'success_url' => env('PAYTR_SUCCESS_URL', 'http://localhost:9000/api/payment/return/success'),
            'fail_url' => env('PAYTR_FAIL_URL', 'http://localhost:9000/api/payment/return/fail'),
            'user_ip' => env('PAYTR_USER_IP'),
            'test_mode' => env('PAYTR_TEST_MODE', true),
            'debug' => env('PAYTR_DEBUG', false),
            'allow_non_3d' => env('PAYTR_ALLOW_NON_3D', false),
            'connect_timeout' => env('PAYTR_CONNECT_TIMEOUT', 5),
            'timeout' => env('PAYTR_TIMEOUT', 30),
        ],
    ],
];
