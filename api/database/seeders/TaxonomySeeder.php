<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TaxonomyTerm;
use Illuminate\Database\Seeder;

/** FRM-04 — 19 sectors, 13 regions + outside KSA, 9 investment types, 17 SDGs, stages, ranges, decline reasons, how_heard. */
class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'sectors' => [
                ['education', 'التعليم والتدريب', 'Education & training'],
                ['health', 'الصحة والرعاية', 'Health & care'],
                ['employment', 'التوظيف والتمكين الاقتصادي', 'Employment & economic empowerment'],
                ['housing', 'الإسكان', 'Housing'],
                ['financial_inclusion', 'الشمول المالي', 'Financial inclusion'],
                ['food_security', 'الأمن الغذائي والزراعة', 'Food security & agriculture'],
                ['environment', 'البيئة والاستدامة', 'Environment & sustainability'],
                ['water_energy', 'المياه والطاقة', 'Water & energy'],
                ['circular_economy', 'الاقتصاد الدائري وإدارة النفايات', 'Circular economy & waste'],
                ['family_wellbeing', 'الأسرة والرفاه الاجتماعي', 'Family & social wellbeing'],
                ['disability', 'ذوو الإعاقة وإمكانية الوصول', 'Disability & accessibility'],
                ['elderly', 'كبار السن', 'Elderly care'],
                ['youth', 'الشباب والطفولة', 'Youth & childhood'],
                ['women_empowerment', 'تمكين المرأة', 'Women empowerment'],
                ['culture_heritage', 'الثقافة والتراث والحرف', 'Culture, heritage & crafts'],
                ['sports', 'الرياضة والصحة المجتمعية', 'Sports & community health'],
                ['digital_inclusion', 'الشمول الرقمي والتقنية الاجتماعية', 'Digital inclusion & social tech'],
                ['nonprofit_capacity', 'بناء قدرات القطاع غير الربحي', 'Nonprofit capacity building'],
                ['transport_mobility', 'النقل والتنقل', 'Transport & mobility'],
            ],
            'regions' => [
                ['riyadh', 'الرياض', 'Riyadh'], ['makkah', 'مكة المكرمة', 'Makkah'], ['madinah', 'المدينة المنورة', 'Madinah'],
                ['qassim', 'القصيم', 'Qassim'], ['eastern', 'الشرقية', 'Eastern Province'], ['asir', 'عسير', 'Asir'],
                ['tabuk', 'تبوك', 'Tabuk'], ['hail', 'حائل', 'Hail'], ['northern_borders', 'الحدود الشمالية', 'Northern Borders'],
                ['jazan', 'جازان', 'Jazan'], ['najran', 'نجران', 'Najran'], ['bahah', 'الباحة', 'Al Bahah'], ['jouf', 'الجوف', 'Al Jouf'],
                ['outside_ksa', 'خارج المملكة', 'Outside KSA'],
            ],
            'investment_types' => [
                ['equity', 'ملكية (أسهم)', 'Equity'], ['debt', 'دين', 'Debt'], ['pay_for_results', 'الدفع مقابل النتائج', 'Pay for results'],
                ['grant', 'منحة', 'Grant'], ['guarantee', 'ضمان', 'Guarantee'], ['patient_capital', 'رأس مال صبور', 'Patient capital'],
                ['convertible', 'قابل للتحويل', 'Convertible'], ['revenue_share', 'مشاركة الإيرادات', 'Revenue share'], ['blended', 'تمويل مدمج', 'Blended'],
            ],
            'beneficiary_groups' => [
                ['children', 'الأطفال', 'Children'], ['youth', 'الشباب', 'Youth'], ['women', 'النساء', 'Women'], ['elderly', 'كبار السن', 'Elderly'],
                ['pwd', 'ذوو الإعاقة', 'Persons with disabilities'], ['low_income', 'الأسر محدودة الدخل', 'Low-income families'],
                ['orphans', 'الأيتام', 'Orphans'], ['job_seekers', 'الباحثون عن عمل', 'Job seekers'], ['smes', 'المنشآت الصغيرة', 'SMEs'],
                ['nonprofits', 'الجهات غير الربحية', 'Nonprofits'], ['rural', 'المجتمعات الريفية', 'Rural communities'], ['general', 'عموم المجتمع', 'General public'],
            ],
            'sdgs' => [
                ['sdg_1', '1 القضاء على الفقر', 'No poverty'], ['sdg_2', '2 القضاء التام على الجوع', 'Zero hunger'], ['sdg_3', '3 الصحة الجيدة والرفاه', 'Good health'],
                ['sdg_4', '4 التعليم الجيد', 'Quality education'], ['sdg_5', '5 المساواة بين الجنسين', 'Gender equality'], ['sdg_6', '6 المياه النظيفة', 'Clean water'],
                ['sdg_7', '7 طاقة نظيفة وبأسعار معقولة', 'Affordable clean energy'], ['sdg_8', '8 العمل اللائق ونمو الاقتصاد', 'Decent work'],
                ['sdg_9', '9 الصناعة والابتكار والهياكل الأساسية', 'Industry & innovation'], ['sdg_10', '10 الحد من أوجه عدم المساواة', 'Reduced inequalities'],
                ['sdg_11', '11 مدن ومجتمعات محلية مستدامة', 'Sustainable cities'], ['sdg_12', '12 الاستهلاك والإنتاج المسؤولان', 'Responsible consumption'],
                ['sdg_13', '13 العمل المناخي', 'Climate action'], ['sdg_14', '14 الحياة تحت الماء', 'Life below water'], ['sdg_15', '15 الحياة في البر', 'Life on land'],
                ['sdg_16', '16 السلام والعدل والمؤسسات القوية', 'Peace & justice'], ['sdg_17', '17 عقد الشراكات لتحقيق الأهداف', 'Partnerships'],
            ],
            'project_stages' => [
                ['idea', 'فكرة', 'Idea'], ['prototype', 'نموذج أولي', 'Prototype'], ['pre_seed', 'ما قبل البذرة', 'Pre-seed'],
                ['seed', 'بذرة', 'Seed'], ['early_stage', 'مرحلة مبكرة', 'Early stage'], ['later_stage', 'مرحلة متقدمة', 'Later stage'],
            ],
            'investment_ranges' => [
                ['r1', 'أقل من 250 ألف ريال', '< 250k SAR', ['min' => 0, 'max' => 250000]],
                ['r2', '250 ألف – مليون ريال', '250k – 1M SAR', ['min' => 250000, 'max' => 1000000]],
                ['r3', '1 – 5 مليون ريال', '1M – 5M SAR', ['min' => 1000000, 'max' => 5000000]],
                ['r4', '5 – 20 مليون ريال', '5M – 20M SAR', ['min' => 5000000, 'max' => 20000000]],
                ['r5', 'أكثر من 20 مليون ريال', '> 20M SAR', ['min' => 20000000, 'max' => null]],
            ],
            'annual_investment_bands' => [
                ['a1', 'أقل من 500 ألف ريال سنويًا', '< 500k SAR / year'], ['a2', '500 ألف – 2 مليون', '500k – 2M'],
                ['a3', '2 – 10 مليون', '2M – 10M'], ['a4', 'أكثر من 10 مليون', '> 10M'],
            ],
            'revenue_bands' => [
                ['none', 'لا إيرادات', 'None'], ['b1', 'أقل من 100 ألف', '< 100k'], ['b2', '100 – 500 ألف', '100k – 500k'],
                ['b3', '500 ألف – 2 مليون', '500k – 2M'], ['b4', 'أكثر من 2 مليون', '> 2M'],
            ],
            'decline_reasons' => [
                ['sector_fit', 'عدم توافق القطاع', 'Sector mismatch'], ['stage_fit', 'عدم توافق المرحلة', 'Stage mismatch'],
                ['ticket_size', 'حجم الاستثمار غير مناسب', 'Ticket size'], ['region', 'المنطقة الجغرافية', 'Region'],
                ['timing', 'التوقيت غير مناسب', 'Timing'], ['capacity', 'لا توجد سعة حاليًا', 'No capacity'],
                ['risk', 'مستوى المخاطر', 'Risk level'], ['info_insufficient', 'معلومات غير كافية', 'Insufficient information'],
                ['other', 'أخرى', 'Other'],
            ],
            'how_heard' => [
                ['website', 'الموقع الإلكتروني', 'Website'], ['social_media', 'وسائل التواصل الاجتماعي', 'Social media'],
                ['event', 'فعالية أو مؤتمر', 'Event'], ['referral', 'ترشيح من شخص', 'Referral'], ['partner', 'جهة شريكة', 'Partner'],
                ['media', 'الإعلام', 'Media'], ['other', 'أخرى', 'Other'],
            ],
            'causes' => [
                ['poverty', 'الحد من الفقر', 'Poverty reduction'], ['education_access', 'الوصول للتعليم', 'Education access'],
                ['health_access', 'الوصول للرعاية الصحية', 'Health access'], ['jobs', 'خلق فرص العمل', 'Job creation'],
                ['climate', 'المناخ والبيئة', 'Climate & environment'], ['inclusion', 'الدمج الاجتماعي', 'Social inclusion'],
            ],
        ];

        foreach ($groups as $group => $terms) {
            foreach ($terms as $i => $term) {
                TaxonomyTerm::query()->updateOrCreate(
                    ['group' => $group, 'key' => $term[0]],
                    ['label_ar' => $term[1], 'label_en' => $term[2], 'sort' => $i + 1, 'is_active' => true, 'meta' => $term[3] ?? null],
                );
            }
        }
    }
}
