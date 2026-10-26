<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessPolicy;
use App\Enums\FundingDisplay;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * ADM-03 / SPEC §7 — cached key/value settings with config fallbacks.
 */
class SettingsService
{
    private const CACHE_KEY = 'sciis.settings.all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $this->loaded = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return $this->loaded;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }

        $defaults = (array) config('sciis.defaults', []);

        return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /** Trimmed string value, or null when unset/blank. */
    public function nullableString(string $key): ?string
    {
        $value = $this->get($key);
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<int, mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function set(string $key, mixed $value, ?User $actor = null, ?string $group = null, ?string $description = null): Setting
    {
        $setting = Setting::query()->firstOrNew(['key' => $key]);
        $before = $setting->exists ? $setting->value : null;

        $setting->fill([
            'value' => $value,
            'group' => $group ?? $setting->group ?? explode('.', $key)[0],
            'description' => $description ?? $setting->description,
            'updated_by' => $actor?->id,
        ])->save();

        $this->flush();

        $this->audit->log('setting.updated', $setting, ['value' => $before], ['value' => $value], $actor);

        return $setting;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values, ?User $actor = null): void
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $actor);
        }
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    // ---------------------------------------------------------------- typed accessors

    public function accessPolicy(): AccessPolicy
    {
        return AccessPolicy::tryFrom((string) $this->get('opportunity.access_policy', 'nomination_only')) ?? AccessPolicy::NominationOnly;
    }

    public function fundingDisplay(): FundingDisplay
    {
        return FundingDisplay::tryFrom((string) $this->get('opportunity.funding_display', 'band')) ?? FundingDisplay::Band;
    }

    public function slaDays(string $kind): int
    {
        return $this->int("sla.{$kind}_days", 5);
    }

    /**
     * Journey 11.3 — days before a completion due date on which the owner is reminded (0 = on the day), descending.
     *
     * @return array<int, int>
     */
    public function completionReminderDays(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', $this->array('sla.completion_reminder_days', [3, 0])),
            fn (int $d): bool => $d >= 0,
        )));
        rsort($days);

        return $days;
    }

    /** Journey 11.3 — days past the completion due date before the file is closed automatically (0 = off). */
    public function completionAutoCloseDays(): int
    {
        return max(0, $this->int('sla.completion_auto_close_days', 14));
    }

    /** EVL-05 — per-criterion score difference that flags a divergence (min 1). */
    public function scoreDiffThreshold(): int
    {
        return max(1, $this->int('evaluation.score_diff_threshold', 2));
    }

    public function maxDocumentSizeBytes(): int
    {
        return $this->int('documents.max_size_mb', (int) config('sciis.documents.max_size_mb', 25)) * 1024 * 1024;
    }

    /** @return array<int, string> */
    public function allowedMimes(): array
    {
        $mimes = $this->array('documents.allowed_mimes', []);

        return $mimes !== [] ? array_values(array_map('strval', $mimes)) : (array) config('sciis.documents.allowed_mimes', []);
    }

    /** @return array<string, mixed> SPEC §6 GET /settings/public */
    public function publicPayload(): array
    {
        return [
            'platform' => [
                'name_ar' => $this->get('platform.name_ar'),
                'name_en' => $this->get('platform.name_en'),
                'short_name' => $this->get('platform.short_name'),
                'official_email' => $this->get('platform.official_email'),
                'official_phone' => $this->get('platform.official_phone'),
                'whatsapp' => $this->get('platform.whatsapp'),
                // Per-audience inboxes and official social accounts (null/'' = not set → hidden on the site).
                'email_investors' => $this->nullableString('platform.email_investors'),
                'email_entrepreneurs' => $this->nullableString('platform.email_entrepreneurs'),
                'social' => [
                    'x' => $this->nullableString('platform.social.x'),
                    'linkedin' => $this->nullableString('platform.social.linkedin'),
                    'instagram' => $this->nullableString('platform.social.instagram'),
                    'youtube' => $this->nullableString('platform.social.youtube'),
                ],
            ],
            'funding_display' => $this->fundingDisplay()->value,
            'access_policy' => $this->accessPolicy()->value,
            'max_nominations_per_opportunity' => $this->int('opportunity.max_nominations_per_opportunity', 5),
            'supported_locales' => feature('english') ? config('sciis.locales') : ['ar'],
            // Local-dev only (SCIIS_VERIFICATION_OPTIONAL): the web shows a "skip" on the OTP screen when true.
            'verification_optional' => (bool) config('sciis.verification.optional'),
            // ACC-01: false while no SMS provider is configured → the phone OTP step is hidden and not required.
            'phone_verification' => (bool) config('sciis.verification.phone'),
            // ACC-07: the portal signs out after this many idle minutes (0 = off).
            'idle_timeout_minutes' => $this->int('security.idle_timeout_minutes', 30),
            // CMS-04: analytics loads only after the visitor accepts analytics cookies.
            'analytics' => ['ga4' => $this->nullableString('analytics.ga4_measurement_id')],
            'features' => [
                'payments' => feature('payments'),
                'english' => feature('english'),
                'browse_all' => feature('browse_all'),
                'watermark' => feature('watermark'),
                'dark_mode' => feature('dark_mode'),
            ],
            'documents' => [
                'max_size_mb' => $this->int('documents.max_size_mb', 25),
                'allowed_mimes' => $this->allowedMimes(),
            ],
            'legal_versions' => [
                'privacy_policy' => $this->get('legal.privacy_policy_version', '1.0'),
                'terms' => $this->get('legal.terms_version', '1.0'),
                'nda' => $this->get('legal.nda_version', '1.0'),
            ],
            'membership' => [
                'fee_sar' => $this->int('membership.fee_sar', 0),
            ],
            'featured_public' => $this->bool('public.featured_public', false),
        ];
    }
}
