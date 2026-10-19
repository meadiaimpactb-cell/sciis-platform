<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development / test driver: writes SMS to the `sms` log channel. */
final class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        Log::channel('sms')->info('SMS', ['to' => $to, 'message' => $message]);

        return true;
    }
}
