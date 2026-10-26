<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/** NTF-04 — WhatsApp driver contract (same pattern as the SMS sender). */
interface WhatsAppSender
{
    /** @param string $to E.164 number */
    public function send(string $to, WhatsAppMessage $message): bool;

    /** Driver name shown in the admin system status. */
    public function driver(): string;
}
