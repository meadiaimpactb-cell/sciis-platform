<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PRJ-02 / PRJ-03 / ENT-10 — follow-up & impact settings: report frequency, grace period and reminders, the
 * "stalled" threshold, and whether owner updates are reviewed by the council before investors receive them.
 * Inserted only when missing so existing environments keep any value already set; fresh installs get the
 * same rows from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string}> */
    private array $rows = [
        ['follow_up.report_frequency', 'quarterly', 'دورية تقارير التقدم للمشاريع الجديدة: monthly | quarterly | semiannual | annual'],
        ['follow_up.report_grace_days', 15, 'مهلة تسليم تقرير التقدم بعد نهاية الفترة (أيام)'],
        ['follow_up.report_reminder_days', [7, 0], 'تذكير صاحب المشروع قبل موعد تسليم التقرير بعدد الأيام (0 = يوم الاستحقاق)'],
        ['follow_up.report_overdue_reminder_days', [3, 10], 'تذكير صاحب المشروع بعد تجاوز موعد التقرير بعدد الأيام'],
        ['follow_up.stalled_after_days', 45, 'يُصنّف المشروع «متوقفًا» إذا تجاوز تأخر مرحلة أو تقرير هذا العدد من الأيام'],
        ['follow_up.updates_require_review', false, 'مراجعة فريق المجلس لتحديثات صاحب المشروع قبل إرسالها للمستثمرين'],
    ];

    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore(array_map(fn (array $r): array => [
            'key' => $r[0], 'value' => json_encode($r[1]), 'group' => 'follow_up', 'description' => $r[2], 'is_public' => false,
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
