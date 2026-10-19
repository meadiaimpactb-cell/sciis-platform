<?php

declare(strict_types=1);

namespace App\Services\Sms;

/** NTF-02 — SMS driver contract. */
interface SmsSender
{
    /** @param string $to E.164 number */
    public function send(string $to, string $message): bool;
}
