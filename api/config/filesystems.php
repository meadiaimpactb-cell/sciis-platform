<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    // Disk used for confidential documents (DOC-01/02, SEC-01). Must be KSA-resident in production (PRV-05).
    'documents_disk' => env('DOCUMENTS_DISK', 's3'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'me-central-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
            'options' => [
                'ServerSideEncryption' => env('AWS_SSE', 'AES256'),
            ],
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
