<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * NTF-01/02 — single entry point for sending notifications; resolves channels from the
 * notification's category + the user's preferences (database always on).
 */
class NotificationService
{
    public function send(?User $user, Notification $notification): void
    {
        if ($user === null) {
            return;
        }

        $user->notify($notification);
    }

    /** @param iterable<User> $users */
    public function sendToMany(iterable $users, Notification $notification): void
    {
        foreach ($users as $user) {
            $this->send($user, $notification);
        }
    }

    /**
     * Channels enabled for a user/category: database is always on; mail/sms follow preferences
     * (default enabled when no preference row exists).
     *
     * @return array<int, string>
     */
    public function channelsFor(User $user, string $category): array
    {
        $prefs = $user->notificationPreferences()->where('category', $category)->get()->keyBy(fn (NotificationPreference $p): string => $p->channel->value);

        $channels = ['database'];
        foreach ([NotificationChannel::Mail, NotificationChannel::Sms] as $channel) {
            $pref = $prefs->get($channel->value);
            $enabled = $pref === null ? true : $pref->enabled;
            if ($channel === NotificationChannel::Sms && empty($user->phone)) {
                $enabled = false;
            }
            if ($enabled) {
                $channels[] = $channel === NotificationChannel::Mail ? 'mail' : 'sms';
            }
        }

        return $channels;
    }

    /** Builds the default preferences matrix for GET /me/notification-preferences. */
    public function preferencesMatrix(User $user): Collection
    {
        $existing = $user->notificationPreferences()->get();

        return collect(NotificationPreference::CATEGORIES)->map(function (string $category) use ($existing): array {
            $row = ['category' => $category];
            foreach (NotificationChannel::cases() as $channel) {
                $pref = $existing->first(fn (NotificationPreference $p): bool => $p->category === $category && $p->channel === $channel);
                $row[$channel->value] = $channel === NotificationChannel::Database ? true : ($pref?->enabled ?? true);
            }

            return $row;
        });
    }

    /** @param array<int, array{category: string, mail?: bool, sms?: bool}> $rows */
    public function updatePreferences(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $category = (string) ($row['category'] ?? '');
            if (! in_array($category, NotificationPreference::CATEGORIES, true)) {
                continue;
            }
            foreach (['mail', 'sms'] as $channel) {
                if (array_key_exists($channel, $row)) {
                    NotificationPreference::query()->updateOrCreate(
                        ['user_id' => $user->id, 'channel' => $channel, 'category' => $category],
                        ['enabled' => (bool) $row[$channel]],
                    );
                }
            }
        }
    }

    public static function usesPreferences(Notification $notification): bool
    {
        return in_array(RespectsPreferences::class, class_uses_recursive($notification), true);
    }
}
