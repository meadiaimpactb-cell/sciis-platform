<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\EvaluationResult;
use App\Enums\InstitutionType;
use App\Enums\InterestStatus;
use App\Enums\LegalForm;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OwnerDecision;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Enums\RiskAppetite;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Enums\UserType;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationDecision;
use App\Models\FileAccessGrant;
use App\Models\ImpactIndicator;
use App\Models\Interest;
use App\Models\InvestorProfile;
use App\Models\Membership;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\Organization;
use App\Models\TaxonomyTerm;
use App\Models\Teaser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC §11 — synthetic demo data, every name suffixed "(افتراضي)". SEC-04: never run in production.
 * Password for every demo account: `Password123!`
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder must not run in production (SEC-04).');

            return;
        }

        $this->call([RolePermissionSeeder::class, TaxonomySeeder::class, EvaluationCriteriaSeeder::class, SettingsSeeder::class, LegalPagesSeeder::class]);

        $staff = $this->staff();
        $investors = $this->investors();
        $entrepreneurs = $this->entrepreneurs();
        $this->opportunities($entrepreneurs, $staff, $investors);
        $this->seedOpportunityProfiles();
    }

    /**
     * Team & founders / market & product / motivation answers for the demo opportunities (readiness axis).
     * Fills ONLY columns that are still null (never overwrites what a demo user typed), so it is safe to re-run:
     * `php artisan tinker --execute="(new Database\Seeders\DemoSeeder)->seedOpportunityProfiles();"`.
     * The draft «وصول» is left partly empty on purpose so the owner demo shows the new checklist items.
     */
    public function seedOpportunityProfiles(): void
    {
        $howHeard = fn (string $key): ?int => TaxonomyTerm::query()->group('how_heard')->where('key', $key)->value('id');

        foreach ($this->opportunityProfiles() as $name => $profile) {
            $o = Opportunity::query()->where('name', $name)->first();
            if ($o === null) {
                continue;
            }
            if (array_key_exists('how_heard', $profile)) {
                $profile['how_heard_id'] = $howHeard($profile['how_heard']);
                unset($profile['how_heard']);
            }
            $missing = array_filter($profile, function (mixed $value, string $column) use ($o): bool {
                $current = $o->getAttribute($column);

                return $value !== null && ($current === null || $current === []);
            }, ARRAY_FILTER_USE_BOTH);
            if ($missing === []) {
                continue;
            }
            $o->timestamps = false; // keep the demo ordering (updated_at)
            $o->forceFill($missing)->saveQuietly();
        }
    }

    /** @return array<string, array<string, mixed>> keyed by opportunity name */
    private function opportunityProfiles(): array
    {
        $founder = fn (string $name, string $role, bool $fullTime = true): array => ['name' => $name.' (افتراضي)', 'role' => $role, 'linkedin_url' => null, 'is_full_time' => $fullTime];

        return [
            'سُلّم (افتراضي)' => [
                'founders_count' => 2, 'team_size' => 14, 'founders_commitment' => 'full_time',
                'prior_startup_experience' => true, 'prior_startup_experience_desc' => 'أسست الرئيسة التنفيذية مركز تدريب مهني صغيرًا (2017–2021) ودمجته لاحقًا مع جهة تعليمية.',
                'industry_experience' => true, 'industry_experience_desc' => 'أكثر من عشر سنوات في التوظيف والموارد البشرية بقطاعي الخدمات اللوجستية والتجزئة.',
                'founders_known_years' => '5_plus', 'has_advisory_board' => true, 'advisory_board_desc' => 'مستشاران من شركات لوجستية كبرى ومستشارة متخصصة في قياس الأثر الاجتماعي.',
                'dev_team_structure' => 'hybrid', 'founders_agreement_signed' => true,
                'founders' => [$founder('هيفاء العتيبي', 'الرئيسة التنفيذية'), $founder('ماجد الشهري', 'مدير الشراكات والتوظيف')],
                'target_customers' => 'الشباب الباحثون عن عمل (20–29 عامًا) في المناطق الطرفية، وشركات التوظيف والخدمات التي تحتاج كوادر مؤهلة.',
                'market_size_desc' => 'نحو 180 ألف وظيفة جديدة متوقعة في القطاعات المستهدفة حتى 2030 (السوق القابل للخدمة)، نستهدف تأهيل 2% منها خلال خمس سنوات.',
                'competitors' => 'معاهد التدريب التقليدية وبرامج التوظيف الحكومية العامة دون ارتباط مباشر بأصحاب العمل.',
                'competitive_advantage' => 'تدريب مرتبط بمقابلات مضمونة لدى 11 جهة شريكة، ونموذج دفع جزئي من صاحب العمل عند التثبيت.',
                'go_to_market' => 'شراكات مع صندوق تنمية الموارد البشرية والجمعيات الأهلية في المناطق، وحملات رقمية موجهة.',
                'product_stage' => 'launched', 'technology_desc' => 'منصة تعلّم ومتابعة خاصة مع لوحة متابعة لأصحاب العمل.',
                'traction_desc' => '610 خريجين حتى الآن، ومعدل توظيف 54% خلال 90 يومًا من التخرج، و11 جهة توظيف شريكة.',
                'scalability_desc' => 'نموذج معسكر قابل للتكرار في ثلاث مدن جديدة بتكلفة إطلاق محدودة لكل مدينة.',
                'why_now' => 'نمو الطلب على الكوادر في القطاعات المستهدفة وتوفر شركاء التوظيف يجعلان التوسع الآن مجديًا.',
                'support_needed' => ['investor_intros', 'market_access', 'impact_measurement'],
                'program_expectations' => 'إغلاق جولة التمويل خلال ستة أشهر وبناء إطار معتمد لقياس الأثر.', 'how_heard' => 'event',
            ],
            'مهارة (افتراضي)' => [
                'founders_count' => 3, 'team_size' => 9, 'founders_commitment' => 'full_time',
                'prior_startup_experience' => false, 'industry_experience' => true, 'industry_experience_desc' => 'خبرة في تصميم المناهج الرقمية وإدارة برامج التمكين في القطاع غير الربحي.',
                'founders_known_years' => '3_5', 'has_advisory_board' => false, 'dev_team_structure' => 'in_house', 'founders_agreement_signed' => true,
                'founders' => [$founder('ريم القحطاني', 'الرئيسة التنفيذية'), $founder('لمى الحربي', 'مديرة المحتوى التعليمي'), $founder('عبير الدوسري', 'مديرة العمليات')],
                'target_customers' => 'النساء الباحثات عن عمل أو دخل إضافي في المدن الصغيرة، والجهات التي توظّف عن بُعد.',
                'market_size_desc' => 'أكثر من 400 ألف امرأة في الفئة المستهدفة خارج المدن الكبرى؛ نستهدف 5 آلاف متدربة خلال ثلاث سنوات.',
                'competitors' => 'منصات التعلم المفتوحة العامة ومراكز التدريب الأهلية.',
                'competitive_advantage' => 'مراكز حضورية قريبة مع إرشاد مهني وربط مباشر بفرص العمل عن بُعد.',
                'go_to_market' => 'شراكات مع الجمعيات النسائية والبلديات، وإحالات من الخريجات.',
                'product_stage' => 'launched', 'technology_desc' => 'نظام إدارة تعلم مفتوح المصدر مخصص.',
                'traction_desc' => 'ثلاثة مراكز تشغيلية، و1,200 متدربة، و38% منهن حصلن على دخل خلال ستة أشهر.',
                'scalability_desc' => 'نموذج امتياز اجتماعي للمراكز مع شركاء محليين في كل مدينة.',
                'why_now' => 'تنامي فرص العمل عن بُعد يتيح دخلًا للنساء في المدن الصغيرة دون الحاجة إلى الانتقال.',
                'support_needed' => ['mentoring', 'investor_intros'],
                'program_expectations' => 'توسيع الشبكة إلى خمسة مراكز وتوثيق الأثر.', 'how_heard' => 'referral',
            ],
            'رفق (افتراضي)' => [
                'founders_count' => 2, 'team_size' => 35, 'founders_commitment' => 'full_time',
                'prior_startup_experience' => true, 'prior_startup_experience_desc' => 'أسس المدير التقني تطبيق حجوزات صحية سابقًا وتخارج منه في 2022.',
                'industry_experience' => true, 'industry_experience_desc' => 'المؤسسة الشريكة ممرضة قانونية بخبرة 12 عامًا في الرعاية المنزلية.',
                'founders_known_years' => '1_3', 'has_advisory_board' => true, 'advisory_board_desc' => 'طبيب استشاري في طب المسنين وخبير امتثال صحي.',
                'dev_team_structure' => 'in_house', 'founders_agreement_signed' => true,
                'founders' => [$founder('خالد الزهراني', 'الرئيس التنفيذي'), $founder('منى الغامدي', 'مديرة الرعاية السريرية')],
                'target_customers' => 'الأسر التي ترعى كبار السن في المنزل، وجهات التأمين والرعاية الاجتماعية.',
                'market_size_desc' => 'أكثر من 1.4 مليون مسن في المملكة مع تزايد الطلب على الرعاية المنزلية بنحو 8% سنويًا.',
                'competitors' => 'شركات التمريض المنزلي التقليدية ومكاتب الاستقدام.',
                'competitive_advantage' => 'شبكة ممرضين مرخصين مع متابعة رقمية وتقارير دورية للأسرة.',
                'go_to_market' => 'تعاقدات مع شركات التأمين وبرامج الضمان، وتسويق مباشر للأسر.',
                'product_stage' => 'scaling', 'technology_desc' => 'تطبيق للأسر والممرضين مع جدولة ذكية وسجل رعاية إلكتروني.',
                'traction_desc' => '900 مستفيد نشط، وعقدان مع جهتي تأمين، ونسبة رضا 92%.',
                'scalability_desc' => 'التوسع إلى ثلاث مناطق جديدة عبر نموذج مراكز تشغيل إقليمية.',
                'why_now' => 'تحولات منظومة الرعاية الصحية تدعم الرعاية المنزلية وتخفف الضغط على المستشفيات.',
                'support_needed' => ['investor_intros', 'legal', 'impact_measurement'],
                'program_expectations' => 'تمويل توسع إقليمي وشراكة مع جهة حكومية للرعاية الاجتماعية.', 'how_heard' => 'partner',
            ],
            'شكّن (افتراضي)' => [
                'founders_count' => 4, 'team_size' => 22, 'founders_commitment' => 'part_time', 'founders_commitment_note' => 'اثنان من المؤسسين متفرغان والبقية بدوام جزئي حتى إغلاق جولة التمويل.',
                'prior_startup_experience' => true, 'prior_startup_experience_desc' => 'خبرة سابقة في تطوير مشاريع سكنية متوسطة الحجم.',
                'industry_experience' => true, 'industry_experience_desc' => 'خبرة مجتمعة تتجاوز 40 عامًا في التطوير العقاري والتمويل السكني.',
                'founders_known_years' => '5_plus', 'has_advisory_board' => true, 'advisory_board_desc' => 'مستشارون في التمويل الإسلامي والإسكان التعاوني.',
                'dev_team_structure' => 'outsourced', 'founders_agreement_signed' => true,
                'founders' => [$founder('فهد المالكي', 'الرئيس التنفيذي'), $founder('سلمان العنزي', 'مدير التطوير العقاري'), $founder('نوف الشمري', 'مديرة الشراكات المجتمعية', false), $founder('تركي البقمي', 'المدير المالي', false)],
                'target_customers' => 'الأسر محدودة الدخل المؤهلة للدعم السكني والراغبة في التملك التشاركي.',
                'market_size_desc' => 'فجوة تقدر بمئات الآلاف من الوحدات الميسّرة؛ نستهدف 300 وحدة في المرحلة الأولى.',
                'competitors' => 'المطورون العقاريون التقليديون وبرامج الإسكان التنموي.',
                'competitive_advantage' => 'نموذج تملك تشاركي يخفض الدفعة الأولى ويشرك السكان في الإدارة.',
                'go_to_market' => 'شراكات مع الجمعيات الأهلية وجهات الدعم السكني.',
                'product_stage' => 'mvp', 'technology_desc' => 'منصة لإدارة الحصص والمدفوعات والصيانة التشاركية.',
                'traction_desc' => 'مشروع تجريبي من 40 وحدة بنسبة إشغال 100%.',
                'scalability_desc' => 'نموذج قابل للتكرار عبر صناديق استثمار عقاري ذات أثر.',
                'why_now' => 'برامج الإسكان الوطنية وتوجه الجهات المانحة نحو حلول السكن الميسّر.',
                'support_needed' => ['investor_intros', 'legal'],
                'program_expectations' => 'هيكلة تمويل مختلط يجمع المنح والاستثمار.', 'how_heard' => 'website',
            ],
            'حصاد (افتراضي)' => [
                'founders_count' => 2, 'team_size' => 6, 'founders_commitment' => 'transitioning', 'founders_commitment_note' => 'تتفرغ المؤسِّسة الثانية كليًا مع بداية الموسم الزراعي القادم.',
                'prior_startup_experience' => false, 'industry_experience' => true, 'industry_experience_desc' => 'خبرة أكاديمية وعملية في الزراعة المائية وإدارة المشاريع المجتمعية.',
                'founders_known_years' => '1_3', 'has_advisory_board' => false, 'dev_team_structure' => 'none', 'founders_agreement_signed' => false,
                'founders' => [$founder('نورة السبيعي', 'المديرة التنفيذية'), $founder('هند العمري', 'المسؤولة الفنية', false)],
                'target_customers' => 'الأسر المنتجة في القرى، ومنافذ بيع الخضار المحلية.',
                'market_size_desc' => 'طلب محلي متزايد على الخضار الطازجة في منطقة عسير بقيمة تقديرية تتجاوز 50 مليون ريال سنويًا.',
                'competitors' => 'المزارع التقليدية والخضار المستوردة من مناطق أخرى.',
                'competitive_advantage' => 'استهلاك مياه أقل بنسبة 80% وإنتاج على مدار العام قرب المستهلك.',
                'go_to_market' => 'بيع مباشر لمنافذ التجزئة المحلية والأسواق الأسبوعية.',
                'product_stage' => 'prototype', 'technology_desc' => 'أنظمة زراعة مائية منخفضة التكلفة مع حساسات لمراقبة المياه.',
                'traction_desc' => 'وحدتان تجريبيتان تنتجان 1.5 طن شهريًا، واتفاقية مبدئية مع منفذَي بيع.',
                'scalability_desc' => 'وحدات معيارية يمكن تركيبها لدى 50 أسرة خلال عامين.',
                'why_now' => 'شح المياه وارتفاع أسعار الغذاء يزيدان الحاجة إلى حلول إنتاج محلي مستدام.',
                'support_needed' => ['technical', 'mentoring', 'market_access'],
                'program_expectations' => 'دعم فني لتحسين الإنتاجية وتمويل أول 20 وحدة.', 'how_heard' => 'social_media',
            ],
            'نماء (افتراضي)' => [
                'founders_count' => 1, 'team_size' => 4, 'founders_commitment' => 'part_time',
                'prior_startup_experience' => false, 'industry_experience' => true, 'industry_experience_desc' => 'خبرة في برامج الإقراض متناهي الصغر بإحدى الجمعيات.',
                'founders_known_years' => 'lt_1', 'has_advisory_board' => false, 'dev_team_structure' => 'none', 'founders_agreement_signed' => false,
                'founders' => [$founder('عبدالرحمن اليامي', 'المؤسس', false)],
                'target_customers' => 'أصحاب المشاريع الصغيرة في الأحياء ذات الدخل المنخفض.',
                'market_size_desc' => 'آلاف المشاريع المنزلية غير المسجلة في الأحياء المستهدفة.',
                'competitors' => 'حاضنات الأعمال العامة وبرامج بنك التنمية الاجتماعية.',
                'competitive_advantage' => 'حضور ميداني داخل الأحياء وبرامج قصيرة باللهجة المحلية.',
                'go_to_market' => 'عبر المساجد والمراكز الاجتماعية في الأحياء.',
                'product_stage' => 'mvp', 'technology_desc' => 'لا يوجد مكوّن تقني حاليًا.',
                'traction_desc' => 'دفعة تجريبية من 15 مشروعًا.',
                'scalability_desc' => 'يعتمد التوسع على تدريب ميسرين محليين.',
                'why_now' => 'الحاجة إلى دعم المشاريع الصغيرة بعد ارتفاع تكاليف المعيشة.',
                'support_needed' => ['mentoring', 'impact_measurement'],
                'program_expectations' => 'بناء نموذج تشغيلي مستدام قبل إعادة التقديم.', 'how_heard' => 'media',
            ],
            'دوّر (افتراضي)' => [
                'founders_count' => 3, 'team_size' => 28, 'founders_commitment' => 'full_time',
                'prior_startup_experience' => true, 'prior_startup_experience_desc' => 'أسس أحد المؤسسين شركة لوجستيات عكسية واستحوذت عليها شركة إقليمية.',
                'industry_experience' => true, 'industry_experience_desc' => 'خبرة في إدارة النفايات الإلكترونية والامتثال البيئي وتوظيف ذوي الإعاقة.',
                'founders_known_years' => '3_5', 'has_advisory_board' => true, 'advisory_board_desc' => 'خبير بيئي ومستشار في التوظيف الدامج.',
                'dev_team_structure' => 'hybrid', 'founders_agreement_signed' => true,
                'founders' => [$founder('يزيد الحمدان', 'الرئيس التنفيذي'), $founder('أسماء الرشيدي', 'مديرة التوظيف الدامج'), $founder('بدر الخالدي', 'مدير العمليات')],
                'target_customers' => 'الشركات والجهات الحكومية التي تحتاج إلى تخلص آمن من الأجهزة الإلكترونية.',
                'market_size_desc' => 'تُنتج المملكة مئات الآلاف من أطنان النفايات الإلكترونية سنويًا ويُعاد تدوير جزء محدود منها.',
                'competitors' => 'شركات إعادة التدوير العامة وتجار الخردة غير النظاميين.',
                'competitive_advantage' => 'شهادات إتلاف آمن للبيانات وأثر اجتماعي موثق عبر توظيف ذوي الإعاقة.',
                'go_to_market' => 'عقود سنوية مع الشركات الكبرى ضمن برامج الاستدامة.',
                'product_stage' => 'launched', 'technology_desc' => 'نظام تتبع للأجهزة من الاستلام حتى الإتلاف مع تقارير للعملاء.',
                'traction_desc' => '40 عميلًا مؤسسيًا، و700 طن معالجة سنويًا، و18 موظفًا من ذوي الإعاقة.',
                'scalability_desc' => 'افتتاح مركز معالجة ثانٍ في المنطقة الشرقية.',
                'why_now' => 'تشديد متطلبات الاستدامة على الشركات وتوجهات الاقتصاد الدائري.',
                'support_needed' => ['investor_intros', 'market_access'],
                'program_expectations' => 'تمويل خط المعالجة الثاني وتوسيع قاعدة العملاء.', 'how_heard' => 'partner',
            ],
            // Draft: team + part of the market answers only → the demo checklist shows the new required items.
            'وصول (افتراضي)' => [
                'founders_count' => 2, 'team_size' => 3, 'founders_commitment' => 'part_time',
                'prior_startup_experience' => false, 'industry_experience' => true, 'industry_experience_desc' => 'خبرة في خدمات النقل الطبي ومراكز التأهيل.',
                'founders_known_years' => '1_3', 'dev_team_structure' => 'outsourced',
                'founders' => [$founder('سارة المطيري', 'المؤسسة'), $founder('فيصل العسيري', 'الشريك التشغيلي', false)],
                'product_stage' => 'idea',
                'target_customers' => 'الأشخاص ذوو الإعاقة الحركية الذين يتنقلون يوميًا إلى العمل ومراكز التأهيل.',
            ],
        ];
    }

    /** @return array<string, User> */
    private function staff(): array
    {
        $defs = [
            'super_admin' => ['admin@sciis.test', 'مدير النظام (افتراضي)'],
            'membership_officer' => ['membership@sciis.test', 'مسؤول العضوية (افتراضي)'],
            'reviewer' => ['reviewer@sciis.test', 'المراجع (افتراضي)'],
            'evaluator' => ['evaluator1@sciis.test', 'المقيّم الأول (افتراضي)'],
            'evaluator2' => ['evaluator2@sciis.test', 'المقيّم الثاني (افتراضي)'],
            'matching_officer' => ['matching@sciis.test', 'مسؤول الربط (افتراضي)'],
            'agreements_officer' => ['agreements@sciis.test', 'مسؤول الاتفاقيات (افتراضي)'],
            'impact_officer' => ['impact@sciis.test', 'مسؤول الأثر (افتراضي)'],
            'advisory_member' => ['advisory@sciis.test', 'عضو اللجنة الاستشارية (افتراضي)'],
            'reports_viewer' => ['reports@sciis.test', 'مستعرض التقارير (افتراضي)'],
        ];

        $users = [];
        $i = 0;
        foreach ($defs as $key => [$email, $name]) {
            $role = $key === 'evaluator2' ? 'evaluator' : $key;
            $user = User::query()->updateOrCreate(['email' => $email], [
                'type' => UserType::Staff, 'name' => $name, 'phone' => '+96650000010'.$i, 'password' => self::PASSWORD,
                'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
            $users[$key] = $user;
            $i++;
        }

        return $users;
    }

    /** @return array<string, User> */
    private function investors(): array
    {
        $t = fn (string $group, string $key): ?int => TaxonomyTerm::query()->group($group)->where('key', $key)->value('id');

        $defs = [
            'abdullah' => [
                'user' => ['email' => 'abdullah@investor.test', 'name' => 'عبدالله (مستثمر فرد — افتراضي)', 'type' => UserType::InvestorIndividual, 'phone' => '+966500000201'],
                'profile' => ['country' => 'السعودية', 'city' => 'الرياض', 'job_title' => 'رائد أعمال', 'experience_years' => 12, 'prior_investments_count' => 4, 'risk_appetite' => RiskAppetite::Medium, 'mentoring_available' => true, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r2'), 'how_heard_id' => $t('how_heard', 'event'), 'conflicts_disclosure' => 'لا يوجد', 'preferred_contact' => 'email'],
                'sectors' => ['education', 'employment', 'youth'], 'stages' => ['seed', 'early_stage'], 'types' => ['equity', 'convertible'], 'regions' => ['riyadh', 'qassim'],
                'status' => MembershipStatus::Approved,
            ],
            'family_office' => [
                'user' => ['email' => 'office@investor.test', 'name' => 'مكتب آل سعد العائلي (افتراضي)', 'type' => UserType::InvestorInstitutional, 'phone' => '+966500000202'],
                'org' => ['name' => 'مكتب آل سعد العائلي (افتراضي)', 'institution_type' => InstitutionType::FamilyOffice, 'legal_form' => LegalForm::Company],
                'profile' => ['country' => 'السعودية', 'city' => 'جدة', 'job_title' => 'مدير الاستثمار', 'experience_years' => 20, 'prior_investments_count' => 15, 'risk_appetite' => RiskAppetite::Low, 'mentoring_available' => false, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r3'), 'how_heard_id' => $t('how_heard', 'referral'), 'conflicts_disclosure' => 'لا يوجد', 'preferred_contact' => 'phone'],
                'sectors' => ['health', 'housing', 'elderly', 'disability'], 'stages' => ['early_stage', 'later_stage'], 'types' => ['debt', 'patient_capital', 'blended'], 'regions' => ['makkah', 'madinah'],
                'status' => MembershipStatus::Approved,
            ],
            'fund' => [
                'user' => ['email' => 'fund@investor.test', 'name' => 'صندوق الأثر الوطني (افتراضي)', 'type' => UserType::InvestorInstitutional, 'phone' => '+966500000203'],
                'org' => ['name' => 'صندوق الأثر الوطني (افتراضي)', 'institution_type' => InstitutionType::Fund, 'legal_form' => LegalForm::Company],
                'profile' => ['country' => 'السعودية', 'city' => 'الرياض', 'job_title' => 'شريك', 'experience_years' => 8, 'prior_investments_count' => 9, 'risk_appetite' => RiskAppetite::High, 'mentoring_available' => true, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r3'), 'how_heard_id' => $t('how_heard', 'partner'), 'conflicts_disclosure' => 'عضوية سابقة في مجلس إدارة مبادرة دوّر', 'preferred_contact' => 'email'],
                'sectors' => ['environment', 'circular_economy', 'food_security', 'water_energy', 'employment'], 'stages' => ['seed', 'early_stage'], 'types' => ['equity', 'pay_for_results', 'revenue_share'], 'regions' => ['riyadh', 'eastern', 'asir'],
                'status' => MembershipStatus::UnderReview,
            ],
        ];

        $users = [];
        foreach ($defs as $key => $def) {
            $user = User::query()->updateOrCreate(['email' => $def['user']['email']], $def['user'] + [
                'password' => self::PASSWORD, 'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'last_login_at' => now()->subDays(2),
            ]);
            if (isset($def['org'])) {
                $org = Organization::query()->updateOrCreate(['name' => $def['org']['name']], $def['org'] + ['representative_user_id' => $user->id, 'region_id' => $t('regions', 'riyadh'), 'city' => 'الرياض', 'founded_on' => '2015-01-01']);
                $user->organization_id = $org->id;
                $user->save();
            }

            $profile = InvestorProfile::query()->updateOrCreate(['user_id' => $user->id], $def['profile']);
            $profile->sectors()->sync(TaxonomyTerm::query()->group('sectors')->whereIn('key', $def['sectors'])->pluck('id'));
            $profile->stages()->sync(TaxonomyTerm::query()->group('project_stages')->whereIn('key', $def['stages'])->pluck('id'));
            $profile->investmentTypes()->sync(TaxonomyTerm::query()->group('investment_types')->whereIn('key', $def['types'])->pluck('id'));
            $profile->regions()->sync(TaxonomyTerm::query()->group('regions')->whereIn('key', $def['regions'])->pluck('id'));
            $profile->completion_percent = $profile->computeCompletion();
            $profile->save();

            $approved = $def['status'] === MembershipStatus::Approved;
            Membership::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => $def['status'], 'submitted_at' => now()->subDays(20), 'terms_accepted_at' => now()->subDays(20),
                'reviewed_at' => $approved ? now()->subDays(15) : null, 'approved_at' => $approved ? now()->subDays(15) : null,
            ]);

            $users[$key] = $user;
        }

        return $users;
    }

    /** @return array<int, User> */
    private function entrepreneurs(): array
    {
        $defs = [
            ['email' => 'sara@owner.test', 'name' => 'سارة (صاحبة مشروع — افتراضي)', 'type' => UserType::EntrepreneurIndividual, 'phone' => '+966500000301', 'org' => null],
            ['email' => 'khalid@owner.test', 'name' => 'خالد (صاحب مشروع — افتراضي)', 'type' => UserType::EntrepreneurEntity, 'phone' => '+966500000302', 'org' => 'شركة أثر للتنمية (افتراضي)'],
            ['email' => 'noura@owner.test', 'name' => 'نورة (صاحبة مشروع — افتراضي)', 'type' => UserType::EntrepreneurEntity, 'phone' => '+966500000303', 'org' => 'جمعية نماء المجتمعية (افتراضي)'],
        ];

        $users = [];
        foreach ($defs as $def) {
            $org = $def['org'];
            unset($def['org']);
            $user = User::query()->updateOrCreate(['email' => $def['email']], $def + ['password' => self::PASSWORD, 'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'last_login_at' => now()->subDay()]);
            if ($org) {
                $o = Organization::query()->updateOrCreate(['name' => $org], ['legal_form' => str_contains($org, 'جمعية') ? LegalForm::Nonprofit : LegalForm::Company, 'representative_user_id' => $user->id, 'founded_on' => '2019-06-01', 'employees_count' => 12, 'city' => 'الرياض', 'region_id' => TaxonomyTerm::query()->group('regions')->where('key', 'riyadh')->value('id')]);
                $user->organization_id = $o->id;
                $user->save();
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param  array<int, User>  $owners
     * @param  array<string, User>  $staff
     * @param  array<string, User>  $investors
     */
    private function opportunities(array $owners, array $staff, array $investors): void
    {
        $t = fn (string $group, string $key): ?int => TaxonomyTerm::query()->group($group)->where('key', $key)->value('id');
        $ids = fn (string $group, array $keys) => TaxonomyTerm::query()->group($group)->whereIn('key', $keys)->pluck('id')->all();

        $defs = [
            ['سُلّم (افتراضي)', 'منصة تدريب مهني مرتبطة بالتوظيف للشباب في المناطق الطرفية.', 'education', ProjectStage::EarlyStage, OpportunityStatus::Eligible, 1_500_000, 'r3', ['riyadh', 'qassim'], ['equity', 'convertible'], 0, 'regular', 2400, 'مسار كامل: ترشيح + اهتمام + موافقة + NDA'],
            ['مهارة (افتراضي)', 'مراكز تعليم مهارات رقمية للنساء في المدن الصغيرة.', 'women_empowerment', ProjectStage::Seed, OpportunityStatus::Connected, 800_000, 'r2', ['madinah'], ['equity'], 0, 'early', 1200, 'متصلة بمستثمر'],
            ['رفق (افتراضي)', 'رعاية منزلية لكبار السن عبر تطبيق وشبكة ممرضين.', 'elderly', ProjectStage::EarlyStage, OpportunityStatus::UnderReview, 3_000_000, 'r3', ['makkah'], ['debt', 'patient_capital'], 1, 'regular', 900, 'قيد التقييم'],
            ['شكّن (افتراضي)', 'إسكان ميسّر تشاركي للأسر محدودة الدخل.', 'housing', ProjectStage::LaterStage, OpportunityStatus::New, 12_000_000, 'r4', ['eastern'], ['blended', 'guarantee'], 1, 'regular', 300, 'جديدة بانتظار الفرز'],
            ['حصاد (افتراضي)', 'زراعة مائية مجتمعية لتعزيز الأمن الغذائي.', 'food_security', ProjectStage::Prototype, OpportunityStatus::NeedsCompletion, 450_000, 'r2', ['asir'], ['grant', 'revenue_share'], 2, 'none', 500, 'بحاجة لاستكمال'],
            ['نماء (افتراضي)', 'حاضنة للمشاريع الصغيرة في الأحياء الفقيرة.', 'employment', ProjectStage::Seed, OpportunityStatus::NotEligible, 600_000, 'r2', ['riyadh'], ['grant'], 2, 'early', 150, 'غير مؤهلة — يسمح بإعادة التقديم'],
            ['دوّر (افتراضي)', 'إعادة تدوير النفايات الإلكترونية وتشغيل ذوي الإعاقة.', 'circular_economy', ProjectStage::EarlyStage, OpportunityStatus::Eligible, 2_200_000, 'r3', ['riyadh', 'eastern'], ['equity', 'pay_for_results'], 1, 'regular', 700, 'مؤهلة — تعارض مصالح مع الصندوق'],
            ['وصول (افتراضي)', 'نقل ميسّر لذوي الإعاقة إلى مراكز العمل والتأهيل.', 'transport_mobility', ProjectStage::Idea, OpportunityStatus::Draft, 350_000, 'r2', ['riyadh'], ['grant'], 0, 'none', 200, 'مسودة'],
        ];

        $criteria = EvaluationCriterion::query()->active()->get();
        $ref = 1;

        foreach ($defs as $i => [$name, $short, $sector, $stage, $status, $amount, $range, $regions, $types, $ownerIdx, $revenue, $target, $note]) {
            $owner = $owners[$ownerIdx];
            $submitted = $status !== OpportunityStatus::Draft;

            $o = Opportunity::query()->updateOrCreate(['name' => $name], [
                'owner_user_id' => $owner->id,
                'organization_id' => $owner->organization_id,
                'short_description' => $short,
                'sector_id' => $t('sectors', $sector),
                'stage' => $stage,
                'status' => $status,
                'is_listed' => true,
                'reference_no' => $submitted ? sprintf('A-%s-%04d', now()->format('Y'), $ref++) : null,
                'problem_statement' => 'المشكلة: '.$short,
                'solution' => 'الحل: نموذج تشغيلي مجرّب مع شركاء محليين. (افتراضي)',
                'expected_change' => 'التغيير المتوقع: تحسين مؤشرات الدخل والتمكين خلال 24 شهرًا. (افتراضي)',
                'beneficiaries_desc' => 'الفئات المستهدفة في المناطق المحددة. (افتراضي)',
                'beneficiaries_current' => (int) ($target / 4),
                'beneficiaries_target' => $target,
                'revenue_model_type' => 'subscription',
                'revenue_model_desc' => 'اشتراكات + عقود جهات حكومية. (افتراضي)',
                'revenue_state' => RevenueState::from($revenue),
                'funding_amount' => $amount,
                'funding_range_id' => $t('investment_ranges', $range),
                'use_of_funds' => [['item' => 'التوسع التشغيلي', 'percent' => 50], ['item' => 'التقنية', 'percent' => 30], ['item' => 'التسويق', 'percent' => 20]],
                'prior_funding' => $i % 2 === 0,
                'prior_funding_desc' => $i % 2 === 0 ? 'منحة تأسيسية 200 ألف ريال من جهة مانحة. (افتراضي)' : null,
                'legal_disputes' => false,
                'license_required' => true,
                'license_status' => 'ساري',
                'operational_risks' => 'اعتماد على شركاء تشغيل محدودين؛ خطة التخفيف: تنويع الشركاء. (افتراضي)',
                'council_relationship' => false,
                'data_accuracy_declared_at' => $submitted ? now()->subDays(30 - $i) : null,
                'submitted_at' => $submitted ? now()->subDays(30 - $i) : null,
                'assigned_reviewer_id' => $submitted ? $staff['reviewer']->id : null,
                'review_due_at' => $submitted ? now()->subDays(25 - $i) : null,
                'completion_due_at' => $status === OpportunityStatus::NeedsCompletion ? now()->addDays(7) : null,
                'evaluation_due_at' => $status === OpportunityStatus::UnderReview ? now()->addDays(5) : null,
                'evaluation_result' => in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true) ? EvaluationResult::Eligible : null,
                'evaluation_approved_at' => in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true) ? now()->subDays(10) : null,
            ]);

            $o->regions()->sync($ids('regions', $regions));
            $o->investmentTypes()->sync($ids('investment_types', $types));
            $o->beneficiaryGroups()->sync($ids('beneficiary_groups', ['youth', 'low_income']));
            $o->sdgs()->sync($ids('sdgs', ['sdg_8', 'sdg_10']));

            ImpactIndicator::query()->updateOrCreate(['opportunity_id' => $o->id, 'is_primary' => true], [
                'name' => 'عدد المستفيدين المباشرين', 'definition' => 'إجمالي المستفيدين الذين أكملوا الخدمة', 'unit' => 'مستفيد',
                'baseline' => (int) ($target / 4), 'target' => $target, 'data_source' => 'سجلات النظام', 'frequency' => 'quarterly',
                'verification_method' => 'تدقيق عينة', 'sdg_id' => $t('sdgs', 'sdg_8'), 'is_disclosed' => true,
            ]);
            ImpactIndicator::query()->updateOrCreate(['opportunity_id' => $o->id, 'name' => 'نسبة التوظيف بعد البرنامج'], [
                'unit' => '%', 'baseline' => 20, 'target' => 60, 'frequency' => 'semiannual', 'is_primary' => false, 'is_disclosed' => false,
            ]);

            // Documents (placeholder files on the local disk).
            $this->document($o, $owner, DocumentKind::PitchDeck, 'العرض التقديمي (افتراضي)');
            if ($submitted) {
                $this->document($o, $owner, DocumentKind::FinancialStatements, 'القوائم المالية (افتراضي)');
            }

            // Timeline
            if ($submitted) {
                $this->transition($o, OpportunityStatus::Draft, OpportunityStatus::New, $owner, now()->subDays(30 - $i));
            }
            if (in_array($status, [OpportunityStatus::UnderReview, OpportunityStatus::Eligible, OpportunityStatus::Connected, OpportunityStatus::NotEligible], true)) {
                $this->transition($o, OpportunityStatus::New, OpportunityStatus::UnderReview, $staff['reviewer'], now()->subDays(27 - $i));
            }
            if ($status === OpportunityStatus::NeedsCompletion) {
                $this->transition($o, OpportunityStatus::New, OpportunityStatus::NeedsCompletion, $staff['reviewer'], now()->subDays(27 - $i), 'المطلوب: السجل التجاري + تفصيل أوجه الصرف');
                $o->completionRequests()->create(['requested_by' => $staff['reviewer']->id, 'due_at' => now()->addDays(7), 'items' => [['document_kind' => 'commercial_register', 'note' => 'نسخة سارية'], ['field' => 'use_of_funds', 'note' => 'تفصيل أدق للبنود']]]);
            }
            if ($status === OpportunityStatus::NotEligible) {
                $this->transition($o, OpportunityStatus::UnderReview, OpportunityStatus::NotEligible, $staff['reviewer'], now()->subDays(20), 'خارج نطاق القطاعات المستهدفة حاليًا؛ يمكن إعادة التقديم بعد تعديل النموذج.');
            }

            // Evaluations for under_review / eligible / connected
            if (in_array($status, [OpportunityStatus::UnderReview, OpportunityStatus::Eligible, OpportunityStatus::Connected], true)) {
                foreach (['evaluator', 'evaluator2'] as $k => $evKey) {
                    $assignment = EvaluationAssignment::query()->updateOrCreate(['opportunity_id' => $o->id, 'evaluator_user_id' => $staff[$evKey]->id], [
                        'assigned_by' => $staff['reviewer']->id, 'status' => $status === OpportunityStatus::UnderReview && $k === 1 ? AssignmentStatus::CoiDeclared : AssignmentStatus::Scored,
                        'coi_declared_at' => now()->subDays(15), 'coi_has_conflict' => false, 'due_at' => now()->addDays(5),
                    ]);
                    if ($assignment->status === AssignmentStatus::Scored) {
                        $scores = [];
                        foreach ($criteria as $c) {
                            $scores[$c->key] = ['score' => 3 + (($i + $k + strlen($c->key)) % 3), 'note' => null];
                        }
                        Evaluation::query()->updateOrCreate(['assignment_id' => $assignment->id], [
                            'criteria_version' => 1, 'criteria_snapshot' => $criteria->map(fn ($c) => $c->only(['axis', 'key', 'label_ar', 'weight', 'version']))->all(),
                            'scores' => $scores, 'axis_totals' => null, 'overall' => 3.8 + $k * 0.2, 'recommendation' => EvaluationResult::Eligible, 'comment' => 'تقييم افتراضي', 'submitted_at' => now()->subDays(12),
                        ]);
                    }
                }
                if ($status !== OpportunityStatus::UnderReview) {
                    EvaluationDecision::query()->firstOrCreate(['opportunity_id' => $o->id], ['result' => EvaluationResult::Eligible, 'decided_by' => $staff['super_admin']->id, 'decided_at' => now()->subDays(10)]);
                    $this->transition($o, OpportunityStatus::UnderReview, OpportunityStatus::Eligible, $staff['super_admin'], now()->subDays(10));
                }
            }

            // Teaser for eligible / connected
            if (in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true)) {
                Teaser::query()->updateOrCreate(['opportunity_id' => $o->id], [
                    'summary_ar' => $short.' نموذج مثبت بإيرادات مبكرة وفريق متفرغ. (ملخص افتراضي)',
                    'highlights' => ['فريق متفرغ', 'شركاء حكوميون', 'مؤشرات أثر قابلة للقياس'],
                    'version' => 1, 'approved_by_owner_at' => now()->subDays(9), 'approved_by_council_at' => now()->subDays(9), 'approved_by_user_id' => $staff['matching_officer']->id,
                ]);
            }

            // Matching flows
            if ($name === 'سُلّم (افتراضي)') {
                $abdullah = $investors['abdullah'];
                $nom = Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['sector', 'stage', 'region', 'investment_type'], 'match_score' => 4,
                    'rationale' => 'توافق عالٍ في القطاع والمرحلة والمنطقة ونوع الاستثمار.', 'teaser_version' => 1, 'sent_at' => now()->subDays(8), 'response' => 'interested', 'responded_at' => now()->subDays(7),
                ]);
                $interest = Interest::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], [
                    'nomination_id' => $nom->id, 'status' => InterestStatus::Shared, 'owner_decision' => OwnerDecision::Approved, 'owner_decided_at' => now()->subDays(6), 'owner_decision_due_at' => now()->subDays(1),
                ]);
                FileAccessGrant::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], ['granted_by_owner_at' => now()->subDays(6)]);
                // Family office nominated, no response yet.
                Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $investors['family_office']->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['investment_type'], 'match_score' => 1, 'rationale' => 'اهتمام سابق بالتعليم المهني.', 'teaser_version' => 1, 'sent_at' => now()->subDays(3),
                ]);
            }

            if ($name === 'مهارة (افتراضي)') {
                $fo = $investors['family_office'];
                Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['region'], 'match_score' => 1, 'rationale' => 'المنطقة مستهدفة لدى المكتب.', 'teaser_version' => 1, 'sent_at' => now()->subDays(14), 'response' => 'interested', 'responded_at' => now()->subDays(13),
                ]);
                $consent = $fo->consents()->firstOrCreate(['type' => 'nda', 'subject_type' => 'opportunity', 'subject_id' => $o->id], ['version' => '1.0', 'text_hash' => hash('sha256', 'nda|1.0'), 'accepted_at' => now()->subDays(11)]);
                FileAccessGrant::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], ['granted_by_owner_at' => now()->subDays(12), 'nda_consent_id' => $consent->id]);
                Interest::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], [
                    'status' => InterestStatus::Connected, 'owner_decision' => OwnerDecision::Approved, 'owner_decided_at' => now()->subDays(12),
                ]);
                $this->transition($o, OpportunityStatus::Eligible, OpportunityStatus::Connected, $staff['matching_officer'], now()->subDays(9));
            }
        }
    }

    private function document(Opportunity $o, User $owner, DocumentKind $kind, string $title): void
    {
        $disk = (string) config('sciis.documents.disk', 'local');
        $path = sprintf('opportunity/%d/demo/%s.pdf', $o->id, $kind->value);
        $content = "%PDF-1.4\n% demo placeholder (افتراضي)\n";
        try {
            $written = Storage::disk($disk)->put($path, $content);
        } catch (\Throwable) {
            $written = false;
        }
        if ($written === false) {
            $disk = 'local';
            Storage::disk($disk)->put($path, $content);
        }

        Document::query()->updateOrCreate(['documentable_type' => 'opportunity', 'documentable_id' => $o->id, 'kind' => $kind->value], [
            'owner_user_id' => $owner->id, 'title' => $title, 'disk' => $disk, 'path' => $path, 'mime' => 'application/pdf', 'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content), 'sensitivity' => Sensitivity::Confidential, 'status' => DocumentStatus::Accepted, 'scan_result' => ScanResult::Clean, 'scanned_at' => now(),
        ]);
    }

    private function transition(Opportunity $o, OpportunityStatus $from, OpportunityStatus $to, User $actor, \DateTimeInterface $at, ?string $reason = null): void
    {
        OpportunityStatusTransition::query()->firstOrCreate(
            ['opportunity_id' => $o->id, 'from_status' => $from->value, 'to_status' => $to->value],
            ['actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => $at],
        );
    }
}
