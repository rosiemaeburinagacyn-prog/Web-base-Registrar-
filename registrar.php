<?php

return [
    'payments' => [
        'gateway' => env('PAYMENT_GATEWAY', 'paymongo'),
    ],

    'paymongo' => [
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
    ],

    'xendit' => [
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'payment_methods' => env('XENDIT_PAYMENT_METHODS', 'GCASH'),
        'invoice_duration' => env('XENDIT_INVOICE_DURATION', 86400),
    ],

    'gcash' => [
        'account_name' => env('GCASH_ACCOUNT_NAME', 'ISU Registrar Office'),
        'number' => env('GCASH_NUMBER', '09510988779'),
        'qr_path' => env('GCASH_QR_PATH', 'images/gcash-qr.jpg'),
    ],
];
