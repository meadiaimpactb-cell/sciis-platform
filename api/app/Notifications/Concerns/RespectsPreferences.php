<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\NotificationService;

/**
 * Resolves delivery channels from the user's notification preferences (NTF-02).
 * Notifications define `category(): string`.
 */
trait RespectsPreferences
{
    abstract public function category(): string;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            $channels = app(NotificationService::class)->channelsFor($notifiable, $this->category());

            // NTF-04 — only key alerts (notifications with a WhatsApp template) go out on WhatsApp.
            return method_exists($this, 'toWhatsApp') ? $channels : array_values(array_diff($channels, ['whatsapp']));
        }

        return ['database'];
    }

    protected function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
