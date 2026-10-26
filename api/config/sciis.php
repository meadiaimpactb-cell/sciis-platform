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
    // Shared secret of the static site build: its GET requests skip the API rate limits (AppServiceProvider).
    'prerender_token' => env('SCIIS_PRERENDER_TOKEN', ''),

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

    'verification' => [
        // Local development only: lets unverified accounts into the portal while email/SMS delivery is not wired.
        // Never enable in production (ACC-01 requires both channels verified).
        'optional' => (bool) env('SCIIS_VERIFICATION_OPTIONAL', false),
        // Phone OTP is part of ACC-01. Switch it off only while no SMS provider is configured (SMS driver `log`);
        // email verification stays mandatory either way.
        'phone' => (bool) env('SCIIS_PHONE_VERIFICATION', true),
    ],

    // BKP-01 — `php artisan backup:run` (scheduled daily; weekly with --files). Keep the path outside the web root.
    'backup' => [
        'path' => env('SCIIS_BACKUP_PATH') ?: storage_path('app/backups'),
        'keep_days' => (int) env('SCIIS_BACKUP_KEEP_DAYS', 14),
        'mysqldump' => env('SCIIS_MYSQLDUMP', 'mysqldump'),
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

    // NTF-04 — WhatsApp key alerts. log | cloud_api (Meta WhatsApp Cloud API; credentials in services.whatsapp).
    // Business-initiated messages need a template approved in WhatsApp Manager; an empty name disables that alert.
    'whatsapp' => [
        'driver' => env('SCIIS_WHATSAPP_DRIVER', 'log'),
        'templates' => [
            'opportunity_status' => env('WHATSAPP_TEMPLATE_OPPORTUNITY_STATUS', 'sciis_opportunity_status'), // {{1}} name {{2}} reference {{3}} status
            'membership_decision' => env('WHATSAPP_TEMPLATE_MEMBERSHIP_DECISION', 'sciis_membership_status'), // {{1}} status
            'meeting_invite' => env('WHATSAPP_TEMPLATE_MEETING_INVITE', 'sciis_meeting_invite'), // {{1}} title {{2}} date-time
        ],
        // Platform locale → template language code registered with Meta.
        'languages' => ['ar' => env('WHATSAPP_LANGUAGE_AR', 'ar'), 'en' => env('WHATSAPP_LANGUAGE_EN', 'en')],
    ],

    // ACC-08 — colleague invitations to organisation accounts.
    'organizations' => [
        'invitation_days' => (int) env('SCIIS_ORG_INVITATION_DAYS', 7),
        'max_members' => (int) env('SCIIS_ORG_MAX_MEMBERS', 20),
    ],

    'identity' => [
        // ACC-09 — none | nafath (needs NAFATH_* credentials from the Nafath onboarding) | fake (local demos only)
        'driver' => env('SCIIS_IDENTITY_DRIVER', 'none'),
    ],

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 's3'),
        // SEC-01 — application-level AES-256-GCM encryption (key derived from APP_KEY) for files on a non-S3 disk.
        // Keep APP_KEY (and APP_PREVIOUS_KEYS after a rotation) safe: without it the files cannot be read.
        'encrypt_at_rest' => (bool) env('DOCUMENTS_ENCRYPT_AT_REST', true),
        'signed_url_minutes' => (int) env('SCIIS_SIGNED_URL_MINUTES', 10),
        // none | clamav
        'scan_driver' => env('SCIIS_SCAN_DRIVER', 'none'),
        // INV-08 — TrueType font with Arabic glyphs for the investor watermark (FEATURE_WATERMARK). Empty = auto-detect
        // (storage/app/fonts/*.ttf, then common system fonts); without one, the watermark falls back to Latin text only.
        'watermark_font' => env('SCIIS_WATERMARK_FONT', ''),
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

    // AGR-05 — e-signature driver. `simple` (built in) = click-to-sign with explicit consent (evidence: signer, time, IP,
    // user agent, SHA-256 of the text). A licensed provider is a SignatureProvider class added to `signature_drivers`
    // as 'key' => class-string, then selected with SCIIS_SIGNATURE_DRIVER=key.
    'agreements' => [
        'signature_driver' => env('SCIIS_SIGNATURE_DRIVER', 'simple'),
        'signature_drivers' => [],
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
        // ADM-03 — post-evaluation stages (eligible → connected → negotiating → in_execution).
        'sla.matching_days' => 30,
        'sla.connection_days' => 30,
        'sla.negotiation_days' => 60,
        // Journey 11.3 — owner reminders (days before the completion due date; 0 = on the day) and auto-close.
        'sla.completion_reminder_days' => [3, 0],
        'sla.completion_auto_close_days' => 14,
        // EVL-05 — per-criterion score difference that flags a divergence between the two evaluators.
        'evaluation.score_diff_threshold' => 2,
        'documents.max_size_mb' => 25,
        'privacy.retention_days.rejected' => 365,
        'privacy.retention_days.withdrawn' => 365,
        'privacy.retention_days.inactive' => 730,
        'membership.fee_sar' => 0,
        'membership.requires_prior_investments' => 2,
        'notifications.sms_sender' => 'SCIIS',
        'reports.allowed_export_roles' => ['super_admin', 'reports_viewer'],
        'public.featured_public' => false,
        // MAT-06 weighted matching (points per criterion; behaviour adjustments in percentage points).
        'matching.weight_sector' => 30,
        'matching.weight_stage' => 15,
        'matching.weight_region' => 15,
        'matching.weight_type' => 15,
        'matching.weight_range' => 10,
        'matching.weight_impact' => 15,
        'matching.decline_penalty' => 10,
        'matching.engagement_bonus' => 5,
        // OPP-07 — minimum weighted fit (0-100) for a "matches my profile" alert.
        'matching.alert_min_score' => 50,
        // INV-07 — days without a profile update before the investor is reminded (0 = off).
        'investor.profile_reminder_days' => 180,
        // MSG-03 — weekly e-mail digest for approved investors.
        'notifications.weekly_digest' => true,
    ],

    'pagination' => [
        'default' => 20,
        'max' => 100,
    ],
];
