<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADM-03 / EVL-05 / journey 11.3 — SLA days for the post-evaluation stages, owner completion reminders,
 * auto-close after an overdue completion request, and the evaluator divergence threshold.
 * Inserted only when missing so existing environments keep any value already set; fresh installs get the
 * same rows from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string, 3: string}> */
    private array $rows = [
        ['sla.matching_days', 30, 'sla', 'مدة الترشيح والربط بعد اعتماد الفرصة (أيام)'],
        ['sla.connection_days', 30, 'sla', 'مدة الانتقال من الربط إلى التفاوض (أيام)'],
        ['sla.negotiation_days', 60, 'sla', 'مدة التفاوض قبل بدء التنفيذ (أيام)'],
        ['sla.completion_reminder_days', [3, 0], 'sla', 'تذكير صاحب المشروع قبل موعد الاستكمال بعدد الأيام (0 = يوم الاستحقاق)'],
        ['sla.completion_auto_close_days', 14, 'sla', 'إغلاق الفرصة تلقائيًا بعد تجاوز موعد الاستكمال بعدد الأيام (0 = معطّل)'],
        ['evaluation.score_diff_threshold', 2, 'evaluation', 'فرق الدرجات بين المقيّمَين الذي يستدعي التنبيه'],
    ];

    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore(array_map(fn (array $r): array => [
            'key' => $r[0], 'value' => json_encode($r[1]), 'group' => $r[2], 'description' => $r[3], 'is_public' => false,
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
