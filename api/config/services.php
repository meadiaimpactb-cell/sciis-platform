<?php

declare(strict_types=1);

return [
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'me-central-1'),
    ],

    'unifonic' => [
        'app_sid' => env('UNIFONIC_APP_SID'),
        'sender_id' => env('UNIFONIC_SENDER_ID', 'SCIIS'),
        'endpoint' => env('UNIFONIC_ENDPOINT', 'https://el.cloud.unifonic.com/rest/SMS/messages'),
    ],

    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'endpoint' => env('MOYASAR_ENDPOINT', 'https://api.moyasar.com/v1'),
    ],

    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
    ],
];
