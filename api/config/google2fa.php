<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'lifetime' => 0,
    'keep_alive' => true,
    'auth_guard' => 'api',
    'session_var' => 'google2fa',
    'otp_input' => 'one_time_password',
    'window' => 1,
    'forbid_old_passwords' => false,
    'otp_secret_column' => 'two_factor_secret',
    'view' => '',
    'error_messages' => [
        'wrong_otp' => 'The one-time password is invalid.',
        'cannot_be_empty' => 'One-time password cannot be empty.',
        'unknown' => 'An unknown error has occurred. Please try again.',
    ],
    'throw_exceptions' => false,
    'qrcode_image_backend' => 'svg',
];
