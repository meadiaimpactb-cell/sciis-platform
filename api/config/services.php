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

    // ACC-09 — Nafath MFA (Elm). Issued when the platform is onboarded with the Nafath service.
    'nafath' => [
        'base_url' => env('NAFATH_BASE_URL', ''),
        'app_id' => env('NAFATH_APP_ID', ''),
        'app_key' => env('NAFATH_APP_KEY', ''),
        'service' => env('NAFATH_SERVICE', 'DigitalServiceEnrollmentWithoutBio'),
        'ttl_seconds' => (int) env('NAFATH_TTL_SECONDS', 180),
        'timeout' => (int) env('NAFATH_TIMEOUT', 10),
    ],

    // NTF-04 — Meta WhatsApp Cloud API (driver `cloud_api`): phone number id + permanent access token of the app.
    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'token' => env('WHATSAPP_ACCESS_TOKEN', ''),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'endpoint' => env('WHATSAPP_ENDPOINT', 'https://graph.facebook.com'),
    ],

    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
    ],
];
