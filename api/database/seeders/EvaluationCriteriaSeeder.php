<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EvaluationCriterion;
use Illuminate\Database\Seeder;

/**
 * EVL-01 / doc §12.5 — criteria on three axes (readiness / feasibility / impact), 5-point scale with Arabic descriptors.
 */
class EvaluationCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $scale = fn (string $one, string $three, string $five): array => [
            '1' => $one,
            '2' => 'بين (1) و(3): يوجد بعض العناصر لكنها غير مكتملة.',
            '3' => $three,
            '4' => 'بين (3) و(5): مكتمل إلى حد كبير مع فجوات بسيطة.',
            '5' => $five,
        ];

        $criteria = [
            // ---------------- Readiness (الجاهزية)
            ['readiness', 'team_capacity', 'قدرة الفريق وخبرته', 'Team capacity & experience', 1.0,
                $scale('فريق غير مكتمل ولا توجد خبرة ذات صلة.', 'فريق أساسي بخبرة جزئية في المجال.', 'فريق مكتمل بخبرات مثبتة وتوزيع واضح للأدوار.')],
            ['readiness', 'legal_status', 'الوضع النظامي والتراخيص', 'Legal status & licensing', 1.0,
                $scale('لا يوجد كيان نظامي أو ترخيص مطلوب غير متوفر.', 'كيان قائم مع بعض التراخيص قيد الاستكمال.', 'كيان نظامي مكتمل وجميع التراخيص سارية.')],
            ['readiness', 'documentation', 'اكتمال المستندات وجودتها', 'Documentation completeness', 0.8,
                $scale('مستندات ناقصة أو غير واضحة.', 'المستندات الأساسية متوفرة بجودة مقبولة.', 'جميع المستندات متوفرة ومحدّثة وعالية الجودة.')],
            ['readiness', 'governance', 'الحوكمة والشفافية', 'Governance & transparency', 1.0,
                $scale('لا توجد هياكل حوكمة واضحة.', 'هياكل حوكمة أساسية مع بعض الفجوات.', 'حوكمة ناضجة وإفصاحات واضحة وتعارض مصالح معلن.')],
            ['readiness', 'operational_readiness', 'الجاهزية التشغيلية', 'Operational readiness', 1.0,
                $scale('لا توجد عمليات تشغيلية قائمة.', 'عمليات قائمة بشكل جزئي أو في مرحلة تجريبية.', 'عمليات تشغيلية مستقرة وقابلة للتوسع.')],

            // ---------------- Feasibility (الجدوى)
            ['feasibility', 'business_model', 'وضوح نموذج العمل والإيرادات', 'Business & revenue model clarity', 1.2,
                $scale('نموذج عمل غير واضح أو غير قابل للتطبيق.', 'نموذج عمل مفهوم مع افتراضات تحتاج تحققًا.', 'نموذج عمل واضح ومثبت بإيرادات أو أدلة قوية.')],
            ['feasibility', 'financial_sustainability', 'الاستدامة المالية', 'Financial sustainability', 1.2,
                $scale('اعتماد كامل على المنح دون مسار للاستدامة.', 'مسار استدامة محدد مع مخاطر متوسطة.', 'استدامة مالية واضحة مع تدفقات نقدية متنوعة.')],
            ['feasibility', 'market_demand', 'الطلب والحاجة في السوق', 'Market need & demand', 1.0,
                $scale('لا توجد أدلة على الطلب.', 'أدلة أولية على الطلب (تجارب أو استطلاعات).', 'طلب مثبت بعملاء أو شركاء فعليين.')],
            ['feasibility', 'funding_use', 'منطقية مبلغ التمويل وأوجه الصرف', 'Funding ask & use of funds', 1.0,
                $scale('مبلغ غير مبرر أو أوجه صرف غير واضحة.', 'مبلغ معقول مع تفصيل جزئي لأوجه الصرف.', 'مبلغ مبرر بالكامل مع خطة صرف تفصيلية ومراحل واضحة.')],
            ['feasibility', 'risk_management', 'إدارة المخاطر', 'Risk management', 0.8,
                $scale('لم تُحدد المخاطر.', 'المخاطر الرئيسية محددة دون خطط معالجة كاملة.', 'المخاطر محددة مع خطط معالجة ومؤشرات إنذار مبكر.')],
            ['feasibility', 'scalability', 'قابلية التوسع', 'Scalability', 0.8,
                $scale('النموذج محدود جغرافيًا أو تشغيليًا.', 'إمكانية توسع متوسطة تتطلب موارد كبيرة.', 'نموذج قابل للتوسع بكفاءة إلى مناطق أو شرائح جديدة.')],

            // ---------------- Impact (الأثر)
            ['impact', 'problem_significance', 'أهمية المشكلة الاجتماعية', 'Problem significance', 1.2,
                $scale('مشكلة غير واضحة أو محدودة الأثر.', 'مشكلة مهمة بأدلة جزئية على حجمها.', 'مشكلة ذات أولوية وطنية بأدلة كمية واضحة.')],
            ['impact', 'theory_of_change', 'وضوح نظرية التغيير', 'Theory of change clarity', 1.2,
                $scale('لا توجد سلسلة منطقية بين الأنشطة والنتائج.', 'سلسلة نتائج واضحة مع فجوات في الافتراضات.', 'نظرية تغيير متكاملة مع افتراضات مثبتة.')],
            ['impact', 'indicators_quality', 'جودة مؤشرات الأثر وقابلية قياسها', 'Impact indicators quality', 1.0,
                $scale('لا توجد مؤشرات أو مؤشرات غير قابلة للقياس.', 'مؤشرات محددة مع خط أساس أو مستهدفات ناقصة.', 'مؤشرات SMART بخط أساس ومستهدفات وطريقة تحقق.')],
            ['impact', 'beneficiary_reach', 'نطاق الوصول إلى المستفيدين', 'Beneficiary reach', 1.0,
                $scale('عدد مستفيدين محدود جدًا دون خطة نمو.', 'نطاق وصول معقول مع خطة توسع.', 'نطاق وصول واسع ومُثبت أو خطة توسع قوية.')],
            ['impact', 'additionality', 'الإضافة النوعية (الأثر الذي لن يحدث بدون المشروع)', 'Additionality', 0.8,
                $scale('أثر يمكن تحقيقه بسهولة عبر بدائل قائمة.', 'إضافة نوعية متوسطة مقارنة بالبدائل.', 'إضافة نوعية عالية لا تتوفر في البدائل القائمة.')],
            ['impact', 'sdg_alignment', 'التوافق مع رؤية 2030 وأهداف التنمية المستدامة', 'Vision 2030 & SDG alignment', 0.8,
                $scale('لا يوجد ربط واضح.', 'ربط عام بأهداف وطنية أو أممية.', 'ربط صريح ومقاس بمستهدفات رؤية 2030 وأهداف التنمية.')],
        ];

        foreach ($criteria as $i => [$axis, $key, $ar, $en, $weight, $scaleDesc]) {
            EvaluationCriterion::query()->updateOrCreate(
                ['key' => $key, 'version' => 1],
                ['axis' => $axis, 'label_ar' => $ar, 'label_en' => $en, 'weight' => $weight, 'scale_descriptions' => $scaleDesc, 'is_active' => true, 'sort' => $i + 1],
            );
        }
    }
}
