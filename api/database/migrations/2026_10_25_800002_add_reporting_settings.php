<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADM-09 automatic reviewer assignment and RPT-04..06 export limit. Inserted only when missing so existing
 * environments keep any value already set; fresh installs get the same rows from SettingsSeeder.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: mixed, 2: string, 3: string}> */
    private array $rows = [
        ['assignment.auto_assign_reviewer', false, 'assignment', 'إسناد المشاريع الجديدة تلقائيًا إلى مراجع عند تقديمها (true/false)'],
        ['assignment.reviewer_strategy', 'least_workload', 'assignment', 'طريقة الإسناد التلقائي: least_workload (الأقل عبئًا) | round_robin (بالتناوب)'],
        ['reports.max_export_rows', 5000, 'reports', 'الحد الأقصى لعدد الصفوف في تصدير التقارير المخصصة (Excel/PDF)'],
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
