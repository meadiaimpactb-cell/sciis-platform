<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCIIS platform configuration
|--------------------------------------------------------------------------
| Feature flags & hard defaults. Business-tunable values live in the
| `settings` table (SPEC §7) and are read through SettingsService; the
| values here are the fallback used when a key has not been seeded yet.
*/

return [
    'features' => [
        // SPEC §0 / §6 Payments: membership / services / training / sponsorship only.
        'payments' => (bool) env('FEATURE_PAYMENTS', false),
        'english' => (bool) env('FEATURE_ENGLISH', false),
        'browse_all' => (bool) env('FEATURE_BROWSE_ALL', false),
        'watermark' => (bool) env('FEATURE_WATERMARK', false),
        'dark_mode' => (bool) env('FEATURE_DARK_MODE', false),
    ],

    'locales' => ['ar', 'en'],

    'two_factor' => [
        // ACC-05: TOTP mandatory for staff.
        'enforce_for_staff' => (bool) env('SCIIS_2FA_ENFORCE_STAFF', true),
        'issuer' => env('APP_NAME', 'SCIIS'),
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    'sms' => [
        // log | unifonic
        'driver' => env('SCIIS_SMS_DRIVER', 'log'),
    ],

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 's3'),
        'signed_url_minutes' => (int) env('SCIIS_SIGNED_URL_MINUTES', 10),
        // none | clamav
        'scan_driver' => env('SCIIS_SCAN_DRIVER', 'none'),
        'max_size_mb' => 25,
        'allowed_mimes' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ],

    'payments' => [
        // moyasar | fake
        'gateway' => env('PAYMENT_GATEWAY', 'moyasar'),
        'currency' => 'SAR',
        'vat_rate' => 0.15,
    ],

    'defaults' => [
        'opportunity.access_policy' => 'nomination_only',
        'opportunity.funding_display' => 'band',
        'opportunity.max_nominations_per_opportunity' => 5,
        'sla.review_days' => 5,
        'sla.completion_days' => 14,
        'sla.evaluation_days' => 10,
        'sla.owner_decision_days' => 7,
        'documents.max_size_mb' => 25,
        'privacy.retention_days.rejected' => 365,
        'privacy.retention_days.withdrawn' => 365,
        'privacy.retention_days.inactive' => 730,
        'membership.fee_sar' => 0,
        'membership.requires_prior_investments' => 2,
        'notifications.sms_sender' => 'SCIIS',
        'reports.allowed_export_roles' => ['super_admin', 'reports_viewer'],
        'public.featured_public' => false,
    ],

    'pagination' => [
        'default' => 20,
        'max' => 100,
    ],
];
