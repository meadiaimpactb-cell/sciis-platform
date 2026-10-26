<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * NTF-01 — notification texts editable from the admin. The defaults stay in `lang/{locale}/notifications.php`;
 * admin overrides live in `notification_templates` and are laid over that group by the translation loader
 * (App\Support\TemplateOverrideLoader), so every mail, SMS and in-app notification picks them up unchanged.
 */
class NotificationTemplateService
{
    public const GROUP = 'notifications';

    private const CACHE_KEY = 'sciis.notification-templates';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, string> overrides for one locale, dot keys */
    public function overrides(string $locale): array
    {
        try {
            $all = Cache::rememberForever(self::CACHE_KEY, fn (): array => NotificationTemplate::query()->get(['locale', 'key', 'value'])
                ->groupBy('locale')->map(fn ($rows) => $rows->pluck('value', 'key')->all())->all());
        } catch (\Throwable) {
            return []; // no table yet (fresh install / during migrations)
        }

        return $all[$locale] ?? [];
    }

    /** @return array<string, string> file defaults for one locale, dot keys (string leaves only) */
    public function defaults(string $locale): array
    {
        $path = lang_path("{$locale}/".self::GROUP.'.php');
        $lines = is_file($path) ? require $path : [];

        return array_filter(Arr::dot(is_array($lines) ? $lines : []), 'is_string');
    }

    /** `:name` placeholders used in a text. @return array<int, string> */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/i', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** Sets (or with null resets) one override. */
    public function set(string $locale, string $key, ?string $value, User $actor): void
    {
        $before = $this->overrides($locale)[$key] ?? null;

        if ($value === null) {
            NotificationTemplate::query()->where(['locale' => $locale, 'key' => $key])->delete();
        } else {
            NotificationTemplate::query()->updateOrCreate(['locale' => $locale, 'key' => $key], ['value' => $value, 'updated_by' => $actor->id]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->audit->log($value === null ? 'notification_template.reset' : 'notification_template.updated', null,
            ['locale' => $locale, 'key' => $key, 'value' => $before], ['locale' => $locale, 'key' => $key, 'value' => $value], $actor);
    }
}
