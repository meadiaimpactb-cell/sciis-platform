<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MAT-06 weighted matching, OPP-07 profile-match alerts, INV-07 profile reminder interval and MSG-03 weekly digest.
 * Inserted only when missing so existing environments keep any value already set; fresh installs get the same rows
 * from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string, 3: string}> */
    private array $rows = [
        ['matching.weight_sector', 30, 'matching', 'وزن توافق القطاع في الترتيب الذكي للمرشحين (نقاط)'],
        ['matching.weight_stage', 15, 'matching', 'وزن توافق المرحلة (نقاط)'],
        ['matching.weight_region', 15, 'matching', 'وزن توافق المنطقة (نقاط)'],
        ['matching.weight_type', 15, 'matching', 'وزن توافق نوع الاستثمار (نقاط)'],
        ['matching.weight_range', 10, 'matching', 'وزن توافق شريحة التمويل (نقاط)'],
        ['matching.weight_impact', 15, 'matching', 'وزن التوافق في الأثر: أهداف التنمية والفئات المستفيدة (نقاط)'],
        ['matching.decline_penalty', 10, 'matching', 'خصم (نقاط مئوية) لكل اعتذار سابق للمستثمر عن فرصة مشابهة لسبب مرتبط (بحد أقصى ضعفه)'],
        ['matching.engagement_bonus', 5, 'matching', 'إضافة (نقاط مئوية) عندما أبدى المستثمر اهتمامًا سابقًا بفرص في القطاع نفسه'],
        ['matching.alert_min_score', 50, 'matching', 'أدنى نسبة توافق (0-100) لتنبيه المستثمر بفرصة جديدة تطابق ملفه'],
        ['investor.profile_reminder_days', 180, 'investor', 'تذكير المستثمر بتحديث ملفه بعد عدد الأيام هذا دون تحديث (0 = معطّل)'],
        ['notifications.weekly_digest', true, 'notifications', 'إرسال الملخص الأسبوعي بالبريد للمستثمرين المعتمدين (يمكن للمستثمر إيقافه من تفضيلاته)'],
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
