<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AGR-01 — a bilingual starting template (social investment agreement), inserted inactive and marked as pending legal
 * approval: the legal team reviews the wording and activates it from Admin → Agreements → Templates.
 */
return new class extends Migration
{
    private const KEY = 'social_investment';

    public function up(): void
    {
        if (DB::table('agreement_templates')->where('key', self::KEY)->exists()) {
            return;
        }

        $now = now();
        DB::table('agreement_templates')->insert([
            'key' => self::KEY,
            'title' => 'اتفاقية استثمار اجتماعي (نموذج إرشادي)',
            'title_en' => 'Social investment agreement (guidance template)',
            'body' => self::BODY_AR,
            'body_en' => self::BODY_EN,
            'description' => 'نموذج إرشادي بين صاحب المشروع والمستثمر بعد الربط — بانتظار الاعتماد القانوني.',
            'version' => 1,
            'is_active' => false,
            'legal_approved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('agreement_templates')->where('key', self::KEY)->where('version', 1)->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('agreements')->whereColumn('agreements.template_id', 'agreement_templates.id'),
        )->delete();
    }

    private const BODY_AR = <<<'TXT'
# اتفاقية استثمار اجتماعي

رقم الاتفاقية: {agreement_no}
التاريخ: {date}

## الأطراف
الطرف الأول (صاحب المشروع): {owner_name}، عن الجهة: {organization_name}.
الطرف الثاني (المستثمر): {investor_name}.

## التمهيد
لما كان الطرف الأول صاحب مشروع «{project_name}» المسجّل في منصة المجلس السعودي للمستثمرين الاجتماعيين بالرقم المرجعي {reference_no}، ورغب الطرف الثاني في الاستثمار في المشروع بما يحقق أثرًا اجتماعيًا قابلًا للقياس، فقد اتفق الطرفان على ما يلي:

## البند 1: موضوع الاتفاقية
يستثمر الطرف الثاني في المشروع مبلغًا قدره {amount} ريال سعودي وفق الشروط الواردة في هذه الاتفاقية.

## البند 2: الأثر الاجتماعي
يلتزم الطرف الأول بالعمل على تحقيق مؤشرات الأثر المعتمدة للمشروع وتقديم تقارير المتابعة الدورية عبر المنصة.

## البند 3: السرية
يلتزم الطرفان بالمحافظة على سرية المعلومات المتبادلة بينهما وعدم إفشائها لأي طرف ثالث إلا بموافقة كتابية مسبقة من الطرف الآخر.

## البند 4: دور المجلس
المجلس ليس طرفًا في هذه الاتفاقية، ويقتصر دوره على تيسير التواصل بين الطرفين وتوثيق الاتفاقية على المنصة.

## البند 5: النظام الواجب التطبيق
تخضع هذه الاتفاقية للأنظمة المعمول بها في المملكة العربية السعودية، وتختص الجهات القضائية في مدينة {city} بالنظر في أي نزاع ينشأ عنها.

## البند 6: التوقيع
حُرّرت هذه الاتفاقية إلكترونيًا، ويُعد توقيع الطرفين عليها عبر المنصة إقرارًا بالاطلاع على جميع بنودها وقبولها.

(نموذج إرشادي — بانتظار الاعتماد القانوني)
TXT;

    private const BODY_EN = <<<'TXT'
# Social investment agreement

Agreement no.: {agreement_no}
Date: {date}

## Parties
First party (project owner): {owner_name}, on behalf of: {organization_name}.
Second party (investor): {investor_name}.

## Recitals
Whereas the first party owns the project "{project_name}", registered on the Saudi Council for Impact Investors platform under reference {reference_no}, and the second party wishes to invest in the project to achieve a measurable social impact, the parties agree as follows:

## Clause 1: Subject
The second party invests SAR {amount} in the project under the terms of this agreement.

## Clause 2: Social impact
The first party works towards the approved impact indicators of the project and submits the periodic follow-up reports through the platform.

## Clause 3: Confidentiality
Both parties keep the information they exchange confidential and do not disclose it to any third party without the other party's prior written consent.

## Clause 4: Role of the Council
The Council is not a party to this agreement; its role is limited to facilitating communication between the parties and recording the agreement on the platform.

## Clause 5: Governing law
This agreement is governed by the laws of the Kingdom of Saudi Arabia, and the competent judicial authorities in {city} have jurisdiction over any dispute arising from it.

## Clause 6: Signature
This agreement is executed electronically; signing it through the platform confirms that each party has read and accepted all of its clauses.

(Guidance template — pending legal approval)
TXT;
};
