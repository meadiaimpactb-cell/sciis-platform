<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Story;
use Illuminate\Database\Seeder;

/**
 * Two clearly-labelled synthetic impact stories «(افتراضي)» so the home «قصص أثر» section can be previewed locally.
 * Runs only when the `stories` table is empty and never in production (SEC-04). Partners are deliberately NOT seeded:
 * the doc forbids showing partner names/logos without their approval.
 *
 *   php artisan db:seed --class=DemoStoriesSeeder
 */
class DemoStoriesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoStoriesSeeder must not run in production (SEC-04).');

            return;
        }
        if (Story::query()->exists()) {
            $this->command?->info('stories table is not empty — skipped.');

            return;
        }

        $stories = [
            [
                'slug' => 'demo-story-water-access',
                'title_ar' => 'مياه نظيفة لقرى الجنوب (افتراضي)',
                'title_en' => 'Clean water for southern villages (demo)',
                'body_ar' => 'قصة توضيحية افتراضية: مشروع اجتماعي لتركيب وحدات تنقية مياه تعمل بالطاقة الشمسية، رُبط بمستثمرَين اجتماعيَّين عبر المنصة بعد التقييم على المحاور الثلاثة.',
                'body_en' => 'Illustrative demo story: a social venture installing solar-powered water purification units, connected with two impact investors through the platform after the three-axis evaluation.',
                'sector_ar' => 'المياه والبيئة', 'sector_en' => 'Water & environment',
                'beneficiaries' => 4200,
                'impact_metric_ar' => 'انخفاض حالات الأمراض المنقولة بالمياه 35% (افتراضي)',
                'impact_metric_en' => '35% fewer water-borne illness cases (demo)',
                'sort' => 1,
            ],
            [
                'slug' => 'demo-story-digital-skills',
                'title_ar' => 'مهارات رقمية لتوظيف الشباب (افتراضي)',
                'title_en' => 'Digital skills for youth employment (demo)',
                'body_ar' => 'قصة توضيحية افتراضية: برنامج تدريب مكثف على المهارات الرقمية للشباب الباحثين عن عمل، حصل على تمويل اجتماعي وإرشاد من أعضاء المجلس.',
                'body_en' => 'Illustrative demo story: an intensive digital-skills bootcamp for young job seekers that secured social funding and mentoring from council members.',
                'sector_ar' => 'التعليم والتوظيف', 'sector_en' => 'Education & employment',
                'beneficiaries' => 650,
                'impact_metric_ar' => '62% من الخريجين التحقوا بوظائف خلال 6 أشهر (افتراضي)',
                'impact_metric_en' => '62% of graduates employed within 6 months (demo)',
                'sort' => 2,
            ],
        ];

        foreach ($stories as $story) {
            Story::query()->create($story + ['is_published' => true, 'published_at' => now()]);
        }

        $this->command?->info('Seeded 2 demo impact stories «(افتراضي)».');
    }
}
