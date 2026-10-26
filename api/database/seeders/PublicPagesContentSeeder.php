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
            'home' => [
                'hero_eyebrow' => ['ar' => 'منصة وطنية للاستثمار الاجتماعي', 'en' => 'A national impact-investing platform'],
                'hero_title' => ['ar' => 'فرص استثمار اجتماعي مُراجَعة، لمستثمرين يقيسون الأثر كما يقيسون العائد', 'en' => 'Reviewed impact-investment opportunities, for investors who measure impact as rigorously as returns'],
                'hero_text' => ['ar' => 'منصة المجلس السعودي للمستثمرين الاجتماعيين تربط المستثمرين المعتمدين بفرص اجتماعية مُقيَّمة بمعايير واضحة، وتدير رحلة الربط من أول اهتمام حتى التواصل المباشر — بشفافية، وبدون وساطة مالية.', 'en' => 'The SCII platform connects accredited investors with impact opportunities evaluated against clear criteria, and manages the journey from first interest to direct connection — transparently and without financial intermediation.'],
                'paths_title' => ['ar' => 'مساران، منصة واحدة', 'en' => 'Two paths, one platform'],
                'paths_sub' => ['ar' => 'اختر المسار المناسب لك — ولكل مسار رحلة واضحة ومتطلبات محددة.', 'en' => 'Choose your path — each has a clear journey and defined requirements.'],
                'investor_path_title' => ['ar' => 'أنا مستثمر اجتماعي', 'en' => 'I am an impact investor'],
                'investor_path_text' => ['ar' => 'سجّل، أكمل ملفك الاستثماري، واحصل على العضوية لتصفح الفرص المؤهّلة أو تلقّي ترشيحات مطابقة لاهتماماتك.', 'en' => 'Register, complete your investment profile, and get membership to browse eligible opportunities or receive nominations matched to your interests.'],
                'investor_path_points' => [
                    'ar' => [
                        'فرص مُقيَّمة بمعيارين مستقلين',
                        'ترشيحات مبنية على قواعد مطابقة شفافة',
                        'تحكّم كامل في بياناتك وتفاعلاتك',
                    ],
                    'en' => [
                        'Opportunities scored by two independent evaluators',
                        'Nominations based on transparent matching rules',
                        'Full control over your data and interactions',
                    ],
                ],
                'entrepreneur_path_title' => ['ar' => 'أنا صاحب مشروع', 'en' => 'I am a project owner'],
                'entrepreneur_path_text' => ['ar' => 'قدّم مشروعك عبر نموذج موحّد، واحصل على تقييم مهني، وتواصل مع مستثمرين مهتمين فعلًا بمجالك.', 'en' => 'Submit your project through a unified form, get a professional evaluation, and connect with investors genuinely interested in your field.'],
                'entrepreneur_path_points' => [
                    'ar' => [
                        'رحلة من 9 مراحل واضحة',
                        'تحكّم في ما يُشارك من بياناتك',
                        'موافقة مزدوجة قبل أي تواصل',
                    ],
                    'en' => [
                        'A clear 9-stage journey',
                        'Control over what is shared',
                        'Dual consent before any contact',
                    ],
                ],
                'how_title' => ['ar' => 'كيف تعمل المنصة', 'en' => 'How the platform works'],
                'how_sub' => ['ar' => 'رحلة واضحة من التسجيل حتى الربط، بخطوات محددة ومُدد زمنية معلنة.', 'en' => 'A clear journey from registration to connection, with defined steps and published timelines.'],
                'how_steps' => [
                    'ar' => [
                        ['title' => 'التسجيل والتحقق', 'text' => 'أنشئ حسابك وتحقّق من بريدك ورقم جوالك.'],
                        ['title' => 'الملف والعضوية', 'text' => 'أكمل ملفك ليراجعه فريق العضوية خلال أيام.'],
                        ['title' => 'الاستكشاف أو الترشيح', 'text' => 'تصفّح الفرص المؤهّلة أو استقبل ترشيحات مطابقة.'],
                        ['title' => 'إبداء الاهتمام', 'text' => 'أبدِ اهتمامك؛ يوافق صاحب المشروع على مشاركة ملفه الكامل.'],
                        ['title' => 'الربط والمتابعة', 'text' => 'اجتماع تعارف منظّم، ثم متابعة التقدم والأثر.'],
                    ],
                    'en' => [
                        ['title' => 'Register & verify', 'text' => 'Create your account and verify email and mobile.'],
                        ['title' => 'Profile & membership', 'text' => 'Complete your profile for the membership team to review.'],
                        ['title' => 'Explore or get nominated', 'text' => 'Browse eligible opportunities or receive matched nominations.'],
                        ['title' => 'Express interest', 'text' => 'Express interest; the owner approves sharing the full file.'],
                        ['title' => 'Connect & follow up', 'text' => 'A structured introduction meeting, then progress and impact follow-up.'],
                    ],
                ],
                'impact_title' => ['ar' => 'ما الذي نعنيه بالأثر؟', 'en' => 'What do we mean by impact?'],
                'impact_text' => ['ar' => 'الأثر هو التغيّر الإيجابي القابل للقياس في حياة المستفيدين أو المجتمع، الناتج عن نشاط المشروع، والمُثبت بمؤشرات لها خط أساس ومستهدف ومصدر بيانات واضح. لا نعدّ الأثر نيّة أو شعارًا، بل نتيجة تُقاس وتُتابع وتُتحقّق منها.', 'en' => 'Impact is the measurable positive change in the lives of beneficiaries or the community caused by the project, evidenced by indicators with a baseline, a target and a clear data source. Impact is not an intention or a slogan — it is a result that is measured, tracked and verified.'],
                'impact_points' => [
                    'ar' => [
                        'نيّة واضحة للأثر منذ التصميم',
                        'مؤشرات بخط أساس ومستهدف',
                        'قابلية التحقق عبر مصادر بيانات',
                        'التوازن بين العائد المالي والاجتماعي',
                    ],
                    'en' => [
                        'Clear intent from design',
                        'Indicators with baseline and target',
                        'Verifiable through data sources',
                        'Balance of financial and social return',
                    ],
                ],
                'benefits_title' => ['ar' => 'مزايا العضوية', 'en' => 'Membership benefits'],
                'benefits_sub' => ['ar' => 'العضوية في المرحلة التأسيسية مجانية، وتمنحك:', 'en' => 'Membership is free in the founding phase and gives you:'],
                'benefits' => [
                    'ar' => [
                        'الوصول إلى فرص اجتماعية مؤهّلة ومُقيَّمة',
                        'ترشيحات مطابقة لقطاعاتك ومراحلك المفضلة',
                        'ملخصات تنفيذية معتمدة من أصحاب المشاريع',
                        'اجتماعات تعارف منظّمة يديرها فريق المجلس',
                        'تقارير متابعة للأثر بعد الربط',
                        'شبكة من المستثمرين الاجتماعيين في المملكة',
                        'فعاليات وورش عمل متخصصة',
                        'تحكّم كامل في خصوصيتك وبياناتك',
                    ],
                    'en' => [
                        'Access to eligible, evaluated impact opportunities',
                        'Nominations matched to your sectors and stages',
                        'Executive summaries approved by project owners',
                        'Structured introduction meetings run by the Council',
                        'Impact follow-up reports after connection',
                        'A network of impact investors in the Kingdom',
                        'Specialised events and workshops',
                        'Full control over your privacy and data',
                    ],
                ],
                'cta_band_title' => ['ar' => 'جاهز للانضمام؟', 'en' => 'Ready to join?'],
                'cta_band_text' => ['ar' => 'التسجيل يستغرق أقل من عشر دقائق، والعضوية مجانية في المرحلة التأسيسية.', 'en' => 'Registration takes under ten minutes and membership is free in the founding phase.'],
            ],
            'about' => [
                'title' => ['ar' => 'عن المجلس السعودي للمستثمرين الاجتماعيين', 'en' => 'About the Saudi Council for Impact Investors'],
                'lead' => ['ar' => 'كيان يجمع المستثمرين الاجتماعيين في المملكة حول رؤية واحدة: توجيه رأس المال نحو حلول قابلة للقياس لتحديات اجتماعية حقيقية.', 'en' => 'A body uniting impact investors in the Kingdom around one vision: directing capital toward measurable solutions to real social challenges.'],
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
                'title' => ['ar' => 'ما هو الاستثمار الاجتماعي (المؤثر)؟', 'en' => 'What is impact investing?'],
                'lead' => ['ar' => 'استثمار يُقصد به تحقيق أثر اجتماعي أو بيئي إيجابي قابل للقياس إلى جانب عائد مالي.', 'en' => 'Investment made with the intention to generate positive, measurable social or environmental impact alongside a financial return.'],
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
                'title' => ['ar' => 'للمستثمرين الاجتماعيين', 'en' => 'For impact investors'],
                'lead' => ['ar' => 'انضم إلى شبكة من المستثمرين الذين يوجّهون رأس المال نحو أثر قابل للقياس.', 'en' => 'Join a network of investors directing capital toward measurable impact.'],
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
                'title' => ['ar' => 'لأصحاب المشاريع الاجتماعية', 'en' => 'For social project owners'],
                'lead' => ['ar' => 'قدّم مشروعك واحصل على تقييم مهني وفرصة للتواصل مع مستثمرين اجتماعيين مهتمين فعلًا بمجالك.', 'en' => 'Submit your project, receive a professional evaluation and connect with impact investors genuinely interested in your field.'],
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
