<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\NewsPost;
use App\Models\Page;
use App\Support\NdaDraft;
use Illuminate\Database\Seeder;

/** CMS-02 — legal page placeholders marked "بانتظار الاعتماد القانوني". */
class LegalPagesSeeder extends Seeder
{
    public function run(): void
    {
        $pending = "\n\n> هذا النص مؤقت — بانتظار الاعتماد القانوني.";

        $pages = [
            ['privacy-policy', 'سياسة الخصوصية', 'Privacy Policy', "# سياسة الخصوصية\n\nيلتزم المجلس بحماية البيانات الشخصية وفق نظام حماية البيانات الشخصية في المملكة العربية السعودية. تُجمع البيانات للأغراض المحددة في المنصة فقط، وتُحفظ داخل المملكة، ولا تُشارك مع أطراف ثالثة دون موافقة صريحة.".$pending, '1.0'],
            ['terms', 'الشروط والأحكام', 'Terms & Conditions', "# الشروط والأحكام\n\nالمنصة أداة ربط ولا تقدم استشارات استثمارية ولا تجمع أموال المستثمرين ولا تدير حسابات ضمان. كل قرار استثماري مسؤولية المستثمر وصاحب المشروع.".$pending, '1.0'],
            ['membership-terms', 'شروط العضوية', 'Membership Terms', "# شروط العضوية\n\nالعضوية في المرحلة التأسيسية مجانية. يتعهد العضو بالسرية وبعدم استخدام المعلومات لغير غرض دراسة الفرصة.".$pending, '1.0'],
            // MSG-02 — full bilingual draft (pending legal review); the investor reads it before accepting.
            [NdaDraft::SLUG, NdaDraft::TITLE_AR, NdaDraft::TITLE_EN, NdaDraft::BODY_AR, '1.0', NdaDraft::BODY_EN],
            ['about', 'عن المجلس', 'About the Council', "# المجلس السعودي للمستثمرين الاجتماعيين\n\nمنصة وطنية تربط المستثمرين الاجتماعيين بالفرص ذات الأثر، دعمًا لمستهدفات رؤية 2030.", null],
            ['disclaimer', 'إخلاء المسؤولية', 'Disclaimer', "# إخلاء المسؤولية\n\nلا تُعد المعلومات المعروضة في المنصة عرضًا لبيع أوراق مالية أو دعوة للاستثمار. المجلس ليس طرفًا في أي اتفاقية بين المستثمر وصاحب المشروع.".$pending, '1.0'],
        ];

        foreach ($pages as $i => $row) {
            [$slug, $ar, $en, $body, $version] = $row;
            $bodyEn = $row[5] ?? null;
            Page::query()->updateOrCreate(['slug' => $slug], [
                'title_ar' => $ar, 'title_en' => $en, 'body_ar' => $body, 'body_en' => $bodyEn, 'version' => $version,
                'is_published' => true, 'published_at' => now(), 'sort' => $i + 1,
            ]);
        }

        // BRD-03 — home page record: hero images live in meta.hero_images ([{url, path, alt_ar, alt_en, width, height}], max 3).
        $home = Page::query()->firstOrNew(['slug' => 'home']);
        if (! $home->exists) {
            $home->fill([
                'title_ar' => 'الصفحة الرئيسية', 'title_en' => 'Home', 'body_ar' => null, 'body_en' => null, 'version' => null,
                'is_published' => true, 'published_at' => now(), 'sort' => 0, 'meta' => ['hero_images' => []],
            ])->save();
        }

        // CMS-02 — one published launch post with a real author (E-E-A-T); only created when no news exists yet.
        if (! NewsPost::query()->exists()) {
            NewsPost::query()->create([
                'slug' => 'founding-phase-launch',
                'title_ar' => 'إطلاق المرحلة التأسيسية لمنصة المجلس السعودي للمستثمرين الاجتماعيين',
                'title_en' => 'Launch of the founding phase of the SCIIS platform',
                'body_ar' => "أعلن المجلس السعودي للمستثمرين الاجتماعيين إطلاق المرحلة التأسيسية لمنصته الرقمية التي تربط المستثمرين الاجتماعيين المعتمدين بالفرص ذات الأثر، ضمن إطار تنظيمي واضح لا يتضمن أي أنشطة مالية مرخّصة.\n\nتُركّز المرحلة التأسيسية على بناء قاعدة الأعضاء المؤسسين، واستقبال الدفعة الأولى من الفرص، وتجربة رحلة الربط من أول اهتمام حتى التواصل المباشر بموافقة مزدوجة.\n\n## ماذا تعني المرحلة التأسيسية للمستثمرين؟\n\nعضوية مجانية، وترشيحات مبنية على قواعد مطابقة شفافة، وتحكّم كامل في البيانات والتفاعلات.\n\n## وماذا تعني لأصحاب المشاريع؟\n\nنموذج تقديم موحّد، وتقييم مهني بمعايير معلنة، وتواصل مع مستثمرين مهتمين فعلًا بالمجال.",
                'body_en' => null,
                'author_name' => 'د. نورة الشهري',
                'author_title' => 'مديرة البرامج — المجلس السعودي للمستثمرين الاجتماعيين',
                'author_bio' => 'باحثة ومتخصصة في تمويل الأثر الاجتماعي، قادت برامج تقييم المشاريع الاجتماعية لأكثر من عشر سنوات، وتشرف على منهجية التقييم والمطابقة في المنصة.',
                'author_avatar_url' => null,
                'author_linkedin_url' => 'https://www.linkedin.com/company/sciis',
                'is_published' => true, 'published_at' => now(), 'sort' => 1,
            ]);
        }

        $faqs = [
            ['investor', 'من يمكنه الانضمام كمستثمر اجتماعي؟', 'الأفراد والمؤسسات (شركات، صناديق، مكاتب عائلية، جهات مانحة) ممن لديهم اهتمام بالاستثمار ذي الأثر، بعد التحقق من الهوية واعتماد العضوية.'],
            ['investor', 'هل تجمع المنصة أموال المستثمرين؟', 'لا. المنصة أداة ربط فقط؛ لا تجمع أموالًا ولا تدير حسابات ضمان ولا تصدر أوراقًا مالية.'],
            ['entrepreneur', 'كيف أقدّم فرصة مشروع؟', 'أنشئ حسابًا كصاحب مشروع، واستكمل أقسام النموذج (الأساسيات، الجهة، الأثر، البيانات المالية، الإفصاحات) ثم اضغط "تقديم".'],
            ['entrepreneur', 'من يطّلع على بياناتي المالية؟', 'فريق المجلس والمقيّمون المسندون فقط. لا يطّلع المستثمر على الملف الكامل إلا بعد موافقتك وقبوله اتفاقية عدم الإفصاح.'],
            ['general', 'ما مدة مراجعة الفرصة؟', 'الفرز الأولي خلال 5 أيام عمل والتقييم خلال 10 أيام عمل تقريبًا، وتُحدَّث هذه المدد في إعدادات المنصة.'],
        ];

        foreach ($faqs as $i => [$category, $q, $a]) {
            Faq::query()->updateOrCreate(['slug' => 'faq-'.($i + 1)], [
                'title_ar' => $q, 'body_ar' => $a, 'category' => $category, 'is_published' => true, 'published_at' => now(), 'sort' => $i + 1,
            ]);
        }
    }
}
