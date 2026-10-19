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
            return app(NotificationService::class)->channelsFor($notifiable, $this->category());
        }

        return ['database'];
    }

    protected function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
