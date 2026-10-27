<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * CMS — marketing copy of the public pages (home / about / impact-investing / for-investors / for-entrepreneurs),
 * stored in pages.meta.sections as { key: { ar: value, en: value } } where value is a string, a string list or a
 * list of objects (same shapes as the web i18n fallback). Text copied verbatim from web/locales/{ar,en}.json.
 *
 * Idempotent and non-destructive: only missing pages / section keys are filled, so admin edits survive re-seeding,
 * and other meta keys (e.g. home.meta.hero_images) are always preserved.
 */
class PublicPagesContentSeeder extends Seeder
{
    /** slug => [title_ar, title_en, sort] for pages created by this seeder. */
    private const TITLES = [
        'home' => ['الصفحة الرئيسية', 'Home', 0],
        'about' => ['عن المجلس', 'About the Council', 5],
        'impact-investing' => ['الاستثمار الاجتماعي', 'Impact investing', 10],
        'for-investors' => ['للمستثمرين', 'For investors', 11],
        'for-entrepreneurs' => ['لأصحاب المشاريع', 'For entrepreneurs', 12],
    ];

    public function run(): void
    {
        foreach (self::sections() as $slug => $sections) {
            $page = Page::query()->firstOrNew(['slug' => $slug]);
            if (! $page->exists) {
                [$ar, $en, $sort] = self::TITLES[$slug];
                $page->fill([
                    'title_ar' => $ar, 'title_en' => $en, 'body_ar' => null, 'body_en' => null, 'version' => null,
                    'is_published' => true, 'published_at' => now(), 'sort' => $sort,
                ]);
            }

            $meta = is_array($page->meta) ? $page->meta : [];
            $existing = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
            foreach ($sections as $key => $value) {
                foreach (['ar', 'en'] as $locale) {
                    $current = $existing[$key][$locale] ?? null;
                    if ($current === null || $current === '' || $current === []) {
                        $existing[$key][$locale] = $value[$locale];
                    }
                }
            }
            $meta['sections'] = $existing;
            if ($slug === 'home' && ! array_key_exists('hero_images', $meta)) {
                $meta['hero_images'] = [];
            }

            $page->meta = $meta;
            $page->save();
        }

        $this->seedGovernance();
    }

    /**
     * About → governance chart (CMS-editable): `governance` page with meta.bodies [{name_ar, name_en, level}].
     * Created only when missing; the structure mirrors the profile document (pending final approval).
     */
    private function seedGovernance(): void
    {
        if (Page::query()->where('slug', 'governance')->exists()) {
            return;
        }

        Page::query()->create([
            'slug' => 'governance',
            'title_ar' => 'هيكل الحوكمة',
            'title_en' => 'Governance structure',
            'body_ar' => 'يتألف المجلس من جمعية عمومية ومجلس إدارة ولجان متخصصة (العضوية، التقييم، الربط، الأثر)، إضافة إلى لجنة استشارية. تفاصيل الهيكل والأسماء ستُنشر بعد اعتمادها.',
            'body_en' => 'The council comprises a general assembly, a board of directors and specialised committees (membership, evaluation, matching, impact), plus an advisory committee. Names and details will be published once approved.',
            'version' => null,
            'is_published' => true,
            'published_at' => now(),
            'sort' => 6,
            'meta' => ['bodies' => [
                ['name_ar' => 'الجمعية العمومية', 'name_en' => 'General assembly', 'level' => 1],
                ['name_ar' => 'مجلس الإدارة', 'name_en' => 'Board of directors', 'level' => 2],
                ['name_ar' => 'لجنة العضوية', 'name_en' => 'Membership committee', 'level' => 3],
                ['name_ar' => 'لجنة التقييم', 'name_en' => 'Evaluation committee', 'level' => 3],
                ['name_ar' => 'لجنة الربط', 'name_en' => 'Matching committee', 'level' => 3],
                ['name_ar' => 'لجنة الأثر', 'name_en' => 'Impact committee', 'level' => 3],
                ['name_ar' => 'اللجنة الاستشارية', 'name_en' => 'Advisory committee', 'level' => 3],
                ['name_ar' => 'الأمانة التنفيذية', 'name_en' => 'Executive secretariat', 'level' => 4],
            ]],
        ]);
    }

    /** @return array<string, array<string, array{ar: mixed, en: mixed}>> */
    public static function sections(): array
    {
        return [
            // Design v3 (web utils/pageSections.ts → landing.* texts); generated, keep in step with the web schema.
            'home' => [
                'hero_badge' => ['ar' => 'المنصة الوطنية للاستثمار الاجتماعي', 'en' => 'Saudi Arabia\'s national impact-investing platform'],
                'headline' => ['ar' => 'حيث يلتقي رأس المال الصبور بالأثر القابل للقياس', 'en' => 'Where patient capital meets measurable impact'],
                'headline_lead' => ['ar' => 'منصة المجلس السعودي للمستثمرين الاجتماعيين تدير رحلة الربط بين الطرفين من أول اهتمام حتى اللقاء المباشر، بشفافية وبدون وساطة مالية.', 'en' => 'The SCII platform manages the connection between both sides, from first interest to a direct meeting, transparently and with no financial intermediation.'],
                'investors_label' => ['ar' => 'للمستثمرين الاجتماعيين', 'en' => 'For impact investors'],
                'investors_title' => ['ar' => 'فرص مُقيَّمة، لا عروض', 'en' => 'Evaluated opportunities, not pitches'],
                'investors_text' => ['ar' => 'مشاريع مرّت بتقييم مهني وفق معايير معلنة، تصلك بحسب قطاعاتك ومراحلك المفضلة.', 'en' => 'Projects that passed a professional evaluation against published criteria, matched to your preferred sectors and stages.'],
                'investors_cta' => ['ar' => 'استكشف الفرص', 'en' => 'Explore opportunities'],
                'projects_label' => ['ar' => 'لأصحاب المشاريع', 'en' => 'For project owners'],
                'projects_title' => ['ar' => 'تقييم مهني، وموافقتك أولًا', 'en' => 'Professional evaluation, your consent first'],
                'projects_text' => ['ar' => 'نموذج موحّد، ملاحظات واضحة في كل مرحلة، ولا تواصل مع أي مستثمر قبل إذنك.', 'en' => 'One unified form, clear feedback at every stage, and no contact with any investor before you allow it.'],
                'projects_cta' => ['ar' => 'قدّم مشروعك', 'en' => 'Submit your project'],
                'stages_label' => ['ar' => 'رحلة من 9 مراحل معلنة لكل مشروع', 'en' => 'A published 9-stage journey for every project'],
                'stages_list' => [
                    'ar' => [
                        'التسجيل',
                        'التحقق',
                        'اكتمال الملف',
                        'المراجعة والتقييم',
                        'التأهيل والنشر',
                        'إبداء الاهتمام',
                        'الموافقة المزدوجة',
                        'اجتماع التعارف',
                        'المتابعة وتقارير الأثر',
                    ],
                    'en' => [
                        'Registration',
                        'Verification',
                        'Profile completion',
                        'Review & evaluation',
                        'Eligibility & listing',
                        'Expression of interest',
                        'Dual consent',
                        'Introduction meeting',
                        'Follow-up & impact reports',
                    ],
                ],
                'impact_label' => ['ar' => 'ما الذي نعنيه بالأثر؟', 'en' => 'What we mean by impact'],
                'impact_heading' => ['ar' => 'الأثر تغيّر إيجابي قابل للقياس في حياة المستفيدين، لا نيّة ولا شعار.', 'en' => 'Impact is measurable positive change in beneficiaries\' lives. Not an intention, not a slogan.'],
                'impact_body' => ['ar' => 'نعدّه نتيجة تُقاس وتُتابع ويُتحقّق منها، بمؤشرات لها خط أساس ومستهدف ومصدر بيانات واضح.', 'en' => 'We treat it as a result that is measured, tracked and verified, through indicators with a baseline, a target and a clear data source.'],
                'impact_criteria' => [
                    'ar' => [
                        'نيّة واضحة للأثر منذ التصميم',
                        'مؤشرات بخط أساس ومستهدف',
                        'قابلية التحقق عبر مصادر بيانات',
                        'التوازن بين العائد المالي والاجتماعي',
                    ],
                    'en' => [
                        'Clear impact intent from the design stage',
                        'Indicators with a baseline and a target',
                        'Verifiable through data sources',
                        'Balance between financial and social return',
                    ],
                ],
                'path_investor_title' => ['ar' => 'أنا مستثمر اجتماعي', 'en' => 'I am an impact investor'],
                'path_investor_text' => ['ar' => 'أكمل ملفك الاستثماري واحصل على العضوية، لتصلك فرص مُقيَّمة تطابق قطاعاتك ومراحلك المفضلة.', 'en' => 'Complete your investor profile and get membership, then receive evaluated opportunities matching your preferred sectors and stages.'],
                'path_investor_points' => [
                    'ar' => [
                        'فرص مُقيَّمة بمعايير معلنة',
                        'ترشيحات حسب القطاع والمرحلة',
                        'بياناتك لا تُشارك إلا بإذنك',
                    ],
                    'en' => [
                        'Opportunities evaluated against published criteria',
                        'Recommendations by sector and stage',
                        'Your data is shared only with your permission',
                    ],
                ],
                'path_investor_cta' => ['ar' => 'ابدأ رحلة العضوية', 'en' => 'Start the membership journey'],
                'path_investor_tool' => ['ar' => 'اختبر جاهزيتك كمستثمر', 'en' => 'Test your investor readiness'],
                'path_project_title' => ['ar' => 'أنا صاحب مشروع', 'en' => 'I have a project'],
                'path_project_text' => ['ar' => 'قدّم مشروعك عبر نموذج موحّد، واحصل على تقييم مهني، وتواصل مع مستثمرين مهتمين فعلًا.', 'en' => 'Submit your project through a unified form, receive a professional evaluation, and talk to investors who are genuinely interested.'],
                'path_project_points' => [
                    'ar' => [
                        'رحلة من 9 مراحل بمُدد معلنة',
                        'ملاحظات تقييم واضحة في كل مرحلة',
                        'موافقتك مطلوبة قبل أي تواصل',
                    ],
                    'en' => [
                        'A 9-stage journey with published timelines',
                        'Clear evaluation feedback at every stage',
                        'Your consent is required before any contact',
                    ],
                ],
                'path_project_cta' => ['ar' => 'قدّم مشروعك الآن', 'en' => 'Submit your project now'],
                'path_project_tool' => ['ar' => 'اختبر جاهزية مشروعك', 'en' => 'Test your project\'s readiness'],
                'how_label' => ['ar' => 'كيف تعمل المنصة', 'en' => 'How the platform works'],
                'how_heading' => ['ar' => 'رحلة واضحة من التسجيل حتى الربط', 'en' => 'A clear journey from registration to connection'],
                'how_steps_list' => [
                    'ar' => [
                        ['title' => 'التسجيل والتحقق', 'text' => 'أنشئ حسابك وتحقق من بريدك ورقم جوالك.', 'meta' => 'يستغرق أقل من 5 دقائق'],
                        ['title' => 'الملف والعضوية', 'text' => 'أكمل ملفك ليراجعه فريق المجلس وتُمنح العضوية.', 'meta' => 'مراجعة من فريق المجلس'],
                        ['title' => 'الاستكشاف أو الترشيح', 'text' => 'تصفّح الفرص المؤهلة أو استقبل ترشيحات مطابقة لاهتماماتك.', 'meta' => 'ترشيحات حسب القطاع والمرحلة'],
                        ['title' => 'إبداء الاهتمام', 'text' => 'عبّر عن اهتمامك، ويوافق صاحب المشروع على مشاركة ملفه الكامل.', 'meta' => 'موافقة مزدوجة قبل أي مشاركة'],
                        ['title' => 'الربط والمتابعة', 'text' => 'اجتماع تعارف منظّم يديره فريق المجلس، ثم تقارير متابعة للأثر.', 'meta' => 'تقارير أثر دورية بعد الربط'],
                    ],
                    'en' => [
                        ['title' => 'Register & verify', 'text' => 'Create your account and verify your email and mobile number.', 'meta' => 'Takes under 5 minutes'],
                        ['title' => 'Profile & membership', 'text' => 'Complete your profile for the Council team to review and grant membership.', 'meta' => 'Reviewed by the Council team'],
                        ['title' => 'Discover or get matched', 'text' => 'Browse eligible opportunities or receive recommendations matching your interests.', 'meta' => 'Matched by sector and stage'],
                        ['title' => 'Express interest', 'text' => 'Signal your interest; the project owner consents to share the full file.', 'meta' => 'Dual consent before anything is shared'],
                        ['title' => 'Connect & follow up', 'text' => 'A structured introduction meeting run by the Council team, then impact follow-up reports.', 'meta' => 'Periodic impact reports after connection'],
                    ],
                ],
                'how_note' => ['ar' => 'المُدد الزمنية لكل مرحلة معلنة داخل المنصة.', 'en' => 'Timelines for every stage are published inside the platform.'],
                'benefits_label' => ['ar' => 'مزايا العضوية', 'en' => 'Membership benefits'],
                'benefits_heading' => ['ar' => 'العضوية مجانية في المرحلة التأسيسية، وتمنحك:', 'en' => 'Membership is free during the founding phase and gives you:'],
                'benefits_list' => [
                    'ar' => [
                        'الوصول إلى فرص اجتماعية مؤهلة ومُقيَّمة',
                        'ترشيحات مطابقة لقطاعاتك ومراحلك المفضلة',
                        'ملخصات تنفيذية معتمدة من أصحاب المشاريع',
                        'اجتماعات تعارف منظّمة يديرها فريق المجلس',
                        'تقارير متابعة للأثر بعد الربط',
                        'تحكّم كامل في خصوصيتك وبياناتك',
                    ],
                    'en' => [
                        'Access to eligible, evaluated impact opportunities',
                        'Recommendations matching your preferred sectors and stages',
                        'Executive summaries approved by project owners',
                        'Structured introduction meetings run by the Council team',
                        'Impact follow-up reports after connection',
                        'Full control over your privacy and data',
                    ],
                ],
                'join_title' => ['ar' => 'جاهز للانضمام؟', 'en' => 'Ready to join?'],
                'join_text' => ['ar' => 'التسجيل يستغرق أقل من عشر دقائق.', 'en' => 'Registration takes under ten minutes.'],
            ],
            'about' => [
                'title' => ['ar' => 'نجمع المستثمرين حول أثرٍ يُقاس', 'en' => 'Bringing investors together around measurable impact'],
                'lead' => ['ar' => 'المجلس السعودي للمستثمرين الاجتماعيين يوحّد جهود المستثمرين في المملكة لتوجيه رأس المال نحو حلول قابلة للقياس لتحديات اجتماعية وبيئية حقيقية.', 'en' => 'The Saudi Council for Impact Investors unites investors across the Kingdom to direct capital toward measurable solutions to real social and environmental challenges.'],
                'vision_text' => ['ar' => 'أن يكون الاستثمار الاجتماعي خيارًا رئيسيًا لرأس المال الخاص في المملكة، بما يسهم في تحقيق مستهدفات رؤية 2030 في جودة الحياة وتنمية القطاع غير الربحي.', 'en' => 'Impact investing becomes a primary choice for private capital in the Kingdom, contributing to Vision 2030 targets in quality of life and nonprofit-sector growth.'],
                'mission_text' => ['ar' => 'بناء منظومة موثوقة تربط المستثمرين بالمشاريع ذات الأثر عبر معايير تقييم واضحة، وحوكمة شفافة، وأدوات متابعة للأثر.', 'en' => 'Build a trusted ecosystem connecting investors with high-impact projects through clear evaluation criteria, transparent governance and impact-tracking tools.'],
                'goals' => [
                    'ar' => [
                        'رفع حجم رأس المال الموجّه للمشاريع الاجتماعية',
                        'رفع جاهزية المشاريع الاجتماعية للاستثمار',
                        'توحيد لغة قياس الأثر بين المستثمرين والمشاريع',
                        'بناء شبكة مهنية للمستثمرين الاجتماعيين',
                        'تطوير السياسات والممارسات الداعمة للاستثمار الاجتماعي',
                    ],
                    'en' => [
                        'Increase capital directed to social projects',
                        'Raise the investment readiness of social projects',
                        'Unify the language of impact measurement',
                        'Build a professional network of impact investors',
                        'Develop supportive policies and practices',
                    ],
                ],
                'governance_text' => ['ar' => 'يتألف المجلس من جمعية عمومية ومجلس إدارة ولجان متخصصة (العضوية، التقييم، الربط، الأثر)، إضافة إلى لجنة استشارية. تفاصيل الهيكل والأسماء ستُنشر بعد اعتمادها.', 'en' => 'The Council comprises a general assembly, a board, specialised committees (membership, evaluation, matching, impact) and an advisory committee. Details will be published once approved.'],
            ],
            'impact-investing' => [
                'title' => ['ar' => 'استثمارٌ يُقاس بعائده وأثره', 'en' => 'Investment measured by its return and its impact'],
                'lead' => ['ar' => 'الاستثمار الاجتماعي (المؤثر) استثمارٌ يُقصد به تحقيق أثر اجتماعي أو بيئي إيجابي قابل للقياس، إلى جانب عائد مالي.', 'en' => 'Impact investing is investment made with the intention to generate positive, measurable social or environmental impact alongside a financial return.'],
                'definition_text' => ['ar' => 'الاستثمار الاجتماعي هو توجيه رأس المال نحو مشاريع ومنظمات تُحدث تغييرًا إيجابيًا في المجتمع أو البيئة، مع التزام صريح بقياس هذا التغيير والإبلاغ عنه، وبعائد مالي يتراوح بين الحفاظ على رأس المال والعائد السوقي بحسب طبيعة الفرصة.', 'en' => 'Impact investing directs capital to projects and organisations that create positive change in society or the environment, with an explicit commitment to measure and report that change, and a financial return ranging from capital preservation to market rate depending on the opportunity.'],
                'impact_def_text' => ['ar' => 'الأثر هو النتيجة النهائية للتغيّر في حياة المستفيدين، ويُقاس بمؤشرات تمتلك: تعريفًا، وحدة، خط أساس، مستهدفًا، مصدر بيانات، وتكرارًا للقياس.', 'en' => 'Impact is the ultimate change in beneficiaries\' lives, measured by indicators that have a definition, unit, baseline, target, data source and measurement frequency.'],
                'characteristics' => [
                    'ar' => [
                        ['title' => 'النيّة', 'text' => 'قصد واضح ومعلن لتحقيق أثر إيجابي، وليس أثرًا عرضيًا.'],
                        ['title' => 'الأدلة والبيانات', 'text' => 'تصميم الاستثمار على أساس أدلة ومعطيات عن المشكلة والحل.'],
                        ['title' => 'إدارة الأثر', 'text' => 'متابعة المؤشرات وإدارة الأداء الاجتماعي كما تُدار المالية.'],
                        ['title' => 'الإسهام في نمو القطاع', 'text' => 'مشاركة الدروس والممارسات لتوسيع الاستثمار الاجتماعي.'],
                    ],
                    'en' => [
                        ['title' => 'Intentionality', 'text' => 'A clear, stated intent to create positive impact, not incidental impact.'],
                        ['title' => 'Evidence & data', 'text' => 'Investment design grounded in evidence about the problem and the solution.'],
                        ['title' => 'Impact management', 'text' => 'Tracking indicators and managing social performance as rigorously as financials.'],
                        ['title' => 'Contribution to the sector', 'text' => 'Sharing lessons and practices to grow impact investing.'],
                    ],
                ],
                'networks_text' => ['ar' => 'تتوافق منهجية المنصة مع الممارسات العالمية الشائعة في مجال الاستثمار المؤثر، مع تكييفها للسياق السعودي.', 'en' => 'The platform\'s methodology aligns with widely used global impact-investing practices, adapted to the Saudi context.'],
                'networks' => [
                    'ar' => [
                        ['name' => 'GIIN — الشبكة العالمية للاستثمار المؤثر', 'desc' => 'تعريفات وخصائص الاستثمار المؤثر ومنظومة IRIS+ للمؤشرات.'],
                        ['name' => 'Impact Management Project (IMP)', 'desc' => 'الأبعاد الخمسة لإدارة الأثر: ماذا، من، كم، الإسهام، المخاطر.'],
                        ['name' => 'أهداف التنمية المستدامة (SDGs)', 'desc' => 'ربط كل فرصة بهدف أو أكثر من الأهداف السبعة عشر.'],
                        ['name' => 'Operating Principles for Impact Management', 'desc' => 'مبادئ تشغيلية لإدارة الأثر عبر دورة الاستثمار.'],
                    ],
                    'en' => [
                        ['name' => 'GIIN — Global Impact Investing Network', 'desc' => 'Definitions and characteristics of impact investing and the IRIS+ indicator system.'],
                        ['name' => 'Impact Management Project (IMP)', 'desc' => 'Five dimensions of impact: what, who, how much, contribution, risk.'],
                        ['name' => 'Sustainable Development Goals (SDGs)', 'desc' => 'Linking every opportunity to one or more of the 17 goals.'],
                        ['name' => 'Operating Principles for Impact Management', 'desc' => 'Operating principles for managing impact through the investment cycle.'],
                    ],
                ],
            ],
            'for-investors' => [
                'title' => ['ar' => 'استثمر في أثرٍ تراه وتقيسه', 'en' => 'Invest in impact you can see and measure'],
                'lead' => ['ar' => 'فرص مُقيَّمة بمعايير معلنة، وترشيحات تطابق اهتماماتك، ومتابعة للأثر بعد الربط — ضمن شبكة من المستثمرين الاجتماعيين.', 'en' => 'Opportunities evaluated against published criteria, nominations matched to your interests and impact follow-up after connection — within a network of impact investors.'],
                'why' => [
                    'ar' => [
                        ['title' => 'فرص مُقيَّمة', 'text' => 'كل فرصة تمر بفرز أولي وتقييم مستقل من مقيّمَين على ثلاثة محاور: الجاهزية، الجدوى، الأثر.'],
                        ['title' => 'مطابقة شفافة', 'text' => 'تُرشَّح الفرص لك وفق معايير معلنة: القطاع، المرحلة، المنطقة، نوع الاستثمار، والنطاق.'],
                        ['title' => 'خصوصية محكمة', 'text' => 'لا تُشارك هويتك مع صاحب المشروع إلا بعد موافقة مزدوجة.'],
                        ['title' => 'بلا وساطة مالية', 'text' => 'المنصة لا تجمع أموالًا ولا تحتفظ بها ولا تُصدر أوراقًا مالية.'],
                    ],
                    'en' => [
                        ['title' => 'Evaluated opportunities', 'text' => 'Every opportunity passes initial screening and independent scoring by two evaluators on readiness, feasibility and impact.'],
                        ['title' => 'Transparent matching', 'text' => 'Opportunities are nominated to you by published criteria: sector, stage, region, investment type and range.'],
                        ['title' => 'Strict privacy', 'text' => 'Your identity is never shared with a project owner before dual consent.'],
                        ['title' => 'No financial intermediation', 'text' => 'The platform collects no money, holds no escrow and issues no securities.'],
                    ],
                ],
                'journey' => [
                    'ar' => [
                        ['title' => 'التسجيل', 'text' => 'بيانات أساسية والتحقق من البريد والجوال.'],
                        ['title' => 'الملف الاستثماري', 'text' => 'اهتماماتك وخبراتك وإقراراتك.'],
                        ['title' => 'المراجعة', 'text' => 'يراجع مسؤول العضوية طلبك خلال أيام العمل.'],
                        ['title' => 'الاعتماد', 'text' => 'قبول الشروط والحصول على صلاحية تصفح الفرص.'],
                    ],
                    'en' => [
                        ['title' => 'Register', 'text' => 'Basic data plus email and mobile verification.'],
                        ['title' => 'Investment profile', 'text' => 'Your interests, experience and declarations.'],
                        ['title' => 'Review', 'text' => 'The membership officer reviews within working days.'],
                        ['title' => 'Approval', 'text' => 'Accept the terms and gain access to opportunities.'],
                    ],
                ],
                'requirements' => [
                    'ar' => [
                        'خبرة استثمارية سابقة أو اهتمام موثّق بالاستثمار الاجتماعي',
                        'استكمال الملف الاستثماري بنسبة 80% على الأقل',
                        'الإقرار بسياسة الخصوصية والشروط والأحكام',
                        'الالتزام بحضور اجتماعات التعارف المجدولة',
                    ],
                    'en' => [
                        'Prior investment experience or documented interest in impact investing',
                        'Investment profile at least 80% complete',
                        'Acceptance of the privacy policy and terms',
                        'Commitment to attend scheduled introduction meetings',
                    ],
                ],
            ],
            'for-entrepreneurs' => [
                'title' => ['ar' => 'من مشروعٍ ذي أثر إلى مستثمرٍ يؤمن به', 'en' => 'From a project with impact to an investor who believes in it'],
                'lead' => ['ar' => 'قدّم مشروعك عبر نموذج موحّد، واحصل على تقييم مهني بملاحظات واضحة، وتواصل مع مستثمرين مهتمين فعلًا بمجالك — بموافقتك أولًا.', 'en' => 'Submit your project through one unified form, receive a professional evaluation with clear feedback and connect with investors genuinely interested in your field — with your consent first.'],
                'criteria' => [
                    'ar' => [
                        'مشروع ذو أثر اجتماعي أو بيئي واضح وقابل للقياس',
                        'نموذج عمل أو خطة إيرادات واضحة (ولو في مرحلة مبكرة)',
                        'فريق مؤسس يعمل بصورة فعلية على المشروع',
                        'مقر أو نشاط داخل المملكة أو يخدم مستفيدين فيها',
                        'عدم وجود نزاعات قانونية قائمة تمنع الاستثمار',
                    ],
                    'en' => [
                        'Clear, measurable social or environmental impact',
                        'A business model or revenue plan (even if early)',
                        'A founding team actively working on the project',
                        'Based in or serving beneficiaries in the Kingdom',
                        'No ongoing legal disputes preventing investment',
                    ],
                ],
                'stages' => [
                    'ar' => [
                        ['title' => 'الفرز الأولي', 'text' => 'اكتمال الطلب ووقوعه ضمن نطاق المنصة — خلال 5 أيام عمل.', 'sla' => '5 أيام'],
                        ['title' => 'استكمال البيانات', 'text' => 'إن وُجدت نواقص، تصلك قائمة واضحة بما هو مطلوب — مهلة 14 يومًا.', 'sla' => '14 يومًا'],
                        ['title' => 'التقييم المستقل', 'text' => 'مقيّمان مستقلان يسجّلان الدرجات على ثلاثة محاور — خلال 10 أيام عمل.', 'sla' => '10 أيام'],
                        ['title' => 'القرار والملخص', 'text' => 'قرار الأهلية، ثم إعداد ملخص تنفيذي توافق عليه قبل نشره.'],
                    ],
                    'en' => [
                        ['title' => 'Initial screening', 'text' => 'Completeness and scope — within 5 working days.', 'sla' => '5 days'],
                        ['title' => 'Completion', 'text' => 'If anything is missing you get a clear list — 14-day window.', 'sla' => '14 days'],
                        ['title' => 'Independent evaluation', 'text' => 'Two independent evaluators score three axes — within 10 working days.', 'sla' => '10 days'],
                        ['title' => 'Decision & teaser', 'text' => 'Eligibility decision, then an executive summary you approve before publication.'],
                    ],
                ],
                'documents' => [
                    'ar' => [
                        ['name' => 'العرض التعريفي (Pitch Deck)', 'required' => true],
                        ['name' => 'السجل التجاري / الترخيص', 'required' => true],
                        ['name' => 'القوائم المالية أو كشف الحساب', 'required' => false],
                        ['name' => 'خطة العمل', 'required' => false],
                        ['name' => 'السير الذاتية للفريق', 'required' => true],
                        ['name' => 'أدلة الأثر (تقارير، شهادات)', 'required' => false],
                        ['name' => 'خطاب تفويض (للمنشآت)', 'required' => true],
                    ],
                    'en' => [
                        ['name' => 'Pitch deck', 'required' => true],
                        ['name' => 'Commercial register / licence', 'required' => true],
                        ['name' => 'Financial statements or bank statement', 'required' => false],
                        ['name' => 'Business plan', 'required' => false],
                        ['name' => 'Team CVs', 'required' => true],
                        ['name' => 'Impact evidence (reports, certificates)', 'required' => false],
                        ['name' => 'Authorisation letter (entities)', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
