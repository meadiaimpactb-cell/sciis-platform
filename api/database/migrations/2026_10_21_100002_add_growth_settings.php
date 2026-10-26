<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Social links + per-audience contact e-mails (exposed in GET /settings/public → platform).
 * Inserted only when missing so existing environments keep any value already set; fresh installs get the
 * same rows from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string}> */
    private array $rows = [
        ['platform.email_investors', 'بريد التواصل المخصّص للمستثمرين (اختياري)'],
        ['platform.email_entrepreneurs', 'بريد التواصل المخصّص لأصحاب المشاريع (اختياري)'],
        ['platform.social.x', 'رابط حساب المنصة على X (اختياري)'],
        ['platform.social.linkedin', 'رابط صفحة المنصة على LinkedIn (اختياري)'],
        ['platform.social.instagram', 'رابط حساب المنصة على Instagram (اختياري)'],
        ['platform.social.youtube', 'رابط قناة المنصة على YouTube (اختياري)'],
    ];

    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore(array_map(fn (array $r): array => [
            'key' => $r[0], 'value' => null, 'group' => 'platform', 'description' => $r[1], 'is_public' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], $this->rows));

        Cache::forget('sciis.settings.all');
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column($this->rows, 0))->delete();
        Cache::forget('sciis.settings.all');
    }
};
