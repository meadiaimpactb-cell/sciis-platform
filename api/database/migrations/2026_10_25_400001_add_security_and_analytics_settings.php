<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ACC-07 idle timeout, ADM-01 per-transition permissions and CMS-04 analytics (loaded only after cookie consent). Inserted only when missing so
 * existing environments keep any value already set; fresh installs get the same rows from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string, 3: string, 4: bool}> */
    private array $rows = [
        ['security.idle_timeout_minutes', 30, 'security', 'الخروج التلقائي بعد عدم النشاط (دقائق، 0 = تعطيل)', true],
        ['workflow.transition_permissions', [], 'workflow', 'الصلاحية المطلوبة من فريق المجلس لكل انتقال في حالة المشروع، بصيغة JSON مثل {"in_execution": "agreements.manage"}. الحالات غير المذكورة تتطلب opportunities.transition', false],
        ['analytics.ga4_measurement_id', null, 'analytics', 'معرّف Google Analytics 4 (مثل G-XXXXXXX) — يُحمَّل فقط بعد موافقة الزائر على ملفات الارتباط', true],
    ];

    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore(array_map(fn (array $r): array => [
            'key' => $r[0], 'value' => json_encode($r[1]), 'group' => $r[2], 'description' => $r[3], 'is_public' => $r[4],
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
