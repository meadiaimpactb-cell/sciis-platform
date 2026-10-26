<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/** SPEC §7 — seeded settings. Pending decisions (معلّق) are stored with the stated defaults. */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // platform
            ['platform.name_ar', 'المجلس السعودي للمستثمرين الاجتماعيين', 'platform', 'الاسم العربي للمنصة', true],
            ['platform.name_en', 'Saudi Council for Impact Investors', 'platform', 'الاسم الإنجليزي للمنصة', true],
            ['platform.short_name', 'SCIIS', 'platform', 'الاسم المختصر (SCII|SCIIS — معلّق بانتظار القرار)', true],
            ['platform.official_email', 'info@sciis.sa', 'platform', 'البريد الرسمي', true],
            ['platform.official_phone', '+966500000000', 'platform', 'الهاتف الرسمي', true],
            ['platform.whatsapp', null, 'platform', 'رقم واتساب (اختياري)', true],
            ['platform.email_investors', null, 'platform', 'بريد التواصل المخصّص للمستثمرين (اختياري)', true],
            ['platform.email_entrepreneurs', null, 'platform', 'بريد التواصل المخصّص لأصحاب المشاريع (اختياري)', true],
            ['platform.social.x', null, 'platform', 'رابط حساب المنصة على X (اختياري)', true],
            ['platform.social.linkedin', null, 'platform', 'رابط صفحة المنصة على LinkedIn (اختياري)', true],
            ['platform.social.instagram', null, 'platform', 'رابط حساب المنصة على Instagram (اختياري)', true],
            ['platform.social.youtube', null, 'platform', 'رابط قناة المنصة على YouTube (اختياري)', true],
            // opportunity
            ['opportunity.access_policy', 'nomination_only', 'opportunity', 'سياسة وصول المستثمرين للفرص: nomination_only | browse_all | both (معلّق بانتظار الرأي القانوني)', true],
            ['opportunity.funding_display', 'band', 'opportunity', 'طريقة عرض مبلغ التمويل: band | exact | hidden', true],
            ['opportunity.max_nominations_per_opportunity', 5, 'opportunity', 'الحد الأقصى للترشيحات لكل فرصة', false],
            // sla
            ['sla.review_days', 5, 'sla', 'مدة الفرز الأولي (أيام)', false],
            ['sla.completion_days', 14, 'sla', 'مدة استكمال البيانات (أيام)', false],
            ['sla.evaluation_days', 10, 'sla', 'مدة التقييم (أيام)', false],
            ['sla.owner_decision_days', 7, 'sla', 'مدة قرار صاحب المشروع على طلب الاطلاع (أيام)', false],
            ['sla.matching_days', 30, 'sla', 'مدة الترشيح والربط بعد اعتماد الفرصة (أيام)', false],
            ['sla.connection_days', 30, 'sla', 'مدة الانتقال من الربط إلى التفاوض (أيام)', false],
            ['sla.negotiation_days', 60, 'sla', 'مدة التفاوض قبل بدء التنفيذ (أيام)', false],
            ['sla.completion_reminder_days', [3, 0], 'sla', 'تذكير صاحب المشروع قبل موعد الاستكمال بعدد الأيام (0 = يوم الاستحقاق)', false],
            ['sla.completion_auto_close_days', 14, 'sla', 'إغلاق الفرصة تلقائيًا بعد تجاوز موعد الاستكمال بعدد الأيام (0 = معطّل)', false],
            // evaluation
            ['evaluation.score_diff_threshold', 2, 'evaluation', 'فرق الدرجات بين المقيّمَين الذي يستدعي التنبيه', false],
            // follow-up & impact (PRJ-02 / PRJ-03 / ENT-10)
            ['follow_up.report_frequency', 'quarterly', 'follow_up', 'دورية تقارير التقدم للمشاريع الجديدة: monthly | quarterly | semiannual | annual', false],
            ['follow_up.report_grace_days', 15, 'follow_up', 'مهلة تسليم تقرير التقدم بعد نهاية الفترة (أيام)', false],
            ['follow_up.report_reminder_days', [7, 0], 'follow_up', 'تذكير صاحب المشروع قبل موعد تسليم التقرير بعدد الأيام (0 = يوم الاستحقاق)', false],
            ['follow_up.report_overdue_reminder_days', [3, 10], 'follow_up', 'تذكير صاحب المشروع بعد تجاوز موعد التقرير بعدد الأيام', false],
            ['follow_up.stalled_after_days', 45, 'follow_up', 'يُصنّف المشروع «متوقفًا» إذا تجاوز تأخر مرحلة أو تقرير هذا العدد من الأيام', false],
            ['follow_up.updates_require_review', false, 'follow_up', 'مراجعة فريق المجلس لتحديثات صاحب المشروع قبل إرسالها للمستثمرين', false],
            // documents
            ['documents.max_size_mb', 25, 'documents', 'الحد الأقصى لحجم الملف (ميجابايت)', true],
            ['documents.allowed_mimes', [
                'application/pdf', 'image/png', 'image/jpeg',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ], 'documents', 'أنواع الملفات المسموحة', true],
            // privacy
            ['privacy.retention_days.rejected', 365, 'privacy', 'مدة الاحتفاظ ببيانات الطلبات المرفوضة (أيام)', false],
            ['privacy.retention_days.withdrawn', 365, 'privacy', 'مدة الاحتفاظ ببيانات الفرص المسحوبة (أيام)', false],
            ['privacy.retention_days.inactive', 730, 'privacy', 'مدة الاحتفاظ بحسابات غير نشطة (أيام)', false],
            // features (mirrors of env flags for visibility — runtime uses config('sciis.features'))
            ['features.payments', false, 'features', 'تفعيل المدفوعات (رسوم عضوية/خدمات/تدريب/رعاية فقط)', true],
            ['features.english', false, 'features', 'تفعيل اللغة الإنجليزية', true],
            ['features.browse_all', false, 'features', 'تفعيل تصفح جميع الفرص', true],
            ['features.watermark', false, 'features', 'علامة مائية على المستندات (المرحلة 2)', true],
            ['features.dark_mode', false, 'features', 'الوضع الداكن', true],
            // membership
            ['membership.fee_sar', 0, 'membership', 'رسوم العضوية بالريال (المرحلة التأسيسية مجانية)', true],
            ['membership.requires_prior_investments', 2, 'membership', 'عدد الاستثمارات السابقة المفضّل', false],
            // security (ACC-07) / analytics (CMS-04, loaded only after cookie consent)
            ['security.idle_timeout_minutes', 30, 'security', 'الخروج التلقائي بعد عدم النشاط (دقائق، 0 = تعطيل)', true],
            // ADM-01 — staff permission per target status (unmapped → opportunities.transition)
            ['workflow.transition_permissions', [], 'workflow', 'الصلاحية المطلوبة من فريق المجلس لكل انتقال في حالة المشروع، بصيغة JSON مثل {"in_execution": "agreements.manage"}. الحالات غير المذكورة تتطلب opportunities.transition', false],
            ['analytics.ga4_measurement_id', null, 'analytics', 'معرّف Google Analytics 4 (مثل G-XXXXXXX) — يُحمَّل فقط بعد موافقة الزائر على ملفات الارتباط', true],
            // MAT-06 weighted matching / OPP-07 profile-match alerts / INV-07 profile reminder
            ['matching.weight_sector', 30, 'matching', 'وزن توافق القطاع في الترتيب الذكي للمرشحين (نقاط)', false],
            ['matching.weight_stage', 15, 'matching', 'وزن توافق المرحلة (نقاط)', false],
            ['matching.weight_region', 15, 'matching', 'وزن توافق المنطقة (نقاط)', false],
            ['matching.weight_type', 15, 'matching', 'وزن توافق نوع الاستثمار (نقاط)', false],
            ['matching.weight_range', 10, 'matching', 'وزن توافق شريحة التمويل (نقاط)', false],
            ['matching.weight_impact', 15, 'matching', 'وزن التوافق في الأثر: أهداف التنمية والفئات المستفيدة (نقاط)', false],
            ['matching.decline_penalty', 10, 'matching', 'خصم (نقاط مئوية) لكل اعتذار سابق للمستثمر عن فرصة مشابهة لسبب مرتبط (بحد أقصى ضعفه)', false],
            ['matching.engagement_bonus', 5, 'matching', 'إضافة (نقاط مئوية) عندما أبدى المستثمر اهتمامًا سابقًا بفرص في القطاع نفسه', false],
            ['matching.alert_min_score', 50, 'matching', 'أدنى نسبة توافق (0-100) لتنبيه المستثمر بفرصة جديدة تطابق ملفه', false],
            ['investor.profile_reminder_days', 180, 'investor', 'تذكير المستثمر بتحديث ملفه بعد عدد الأيام هذا دون تحديث (0 = معطّل)', false],
            // notifications / reports
            ['notifications.weekly_digest', true, 'notifications', 'إرسال الملخص الأسبوعي بالبريد للمستثمرين المعتمدين (يمكن للمستثمر إيقافه من تفضيلاته)', false],
            ['notifications.sms_sender', 'SCIIS', 'notifications', 'اسم مرسل الرسائل النصية', false],
            ['reports.allowed_export_roles', ['super_admin', 'reports_viewer'], 'reports', 'الأدوار المسموح لها بتصدير التقارير', false],
            // RPT-04..06 export cap / ADM-09 automatic reviewer assignment
            ['reports.max_export_rows', 5000, 'reports', 'الحد الأقصى لعدد الصفوف في تصدير التقارير المخصصة (Excel/PDF)', false],
            ['assignment.auto_assign_reviewer', false, 'assignment', 'إسناد المشاريع الجديدة تلقائيًا إلى مراجع عند تقديمها (true/false)', false],
            ['assignment.reviewer_strategy', 'least_workload', 'assignment', 'طريقة الإسناد التلقائي: least_workload (الأقل عبئًا) | round_robin (بالتناوب)', false],
            // legal versions
            ['legal.privacy_policy_version', '1.0', 'legal', 'إصدار سياسة الخصوصية', true],
            ['legal.terms_version', '1.0', 'legal', 'إصدار الشروط والأحكام', true],
            ['legal.nda_version', '1.0', 'legal', 'إصدار اتفاقية عدم الإفصاح — ارفعه عند كل تعديل على نصها (يُطلب من المستثمر قبول الإصدار الجديد)', true],
            ['legal.nda_text', 'نص اتفاقية عدم الإفصاح — بانتظار الاعتماد القانوني', 'legal', 'غير مستخدم — نص الاتفاقية (عربي/إنجليزي) يُحرَّر من إدارة المحتوى ← الصفحات ← nda', false],
            // public
            ['public.featured_public', false, 'public', 'عرض فرص مختارة في الموقع العام', true],
            // contact routing (CMS-05) — staff user id per category, null = unassigned
            ['contact.routing.investor', null, 'contact', 'معرّف موظف المجلس المسؤول عن رسائل المستثمرين', false],
            ['contact.routing.entrepreneur', null, 'contact', 'معرّف موظف المجلس المسؤول عن رسائل أصحاب المشاريع', false],
            ['contact.routing.partnership', null, 'contact', 'معرّف موظف المجلس المسؤول عن رسائل الشراكات', false],
            ['contact.routing.media', null, 'contact', 'معرّف موظف المجلس المسؤول عن رسائل الإعلام', false],
            ['contact.routing.other', null, 'contact', 'معرّف موظف المجلس المسؤول عن الرسائل الأخرى', false],
        ];

        foreach ($rows as [$key, $value, $group, $description, $isPublic]) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group, 'description' => $description, 'is_public' => $isPublic]);
        }

        app(SettingsService::class)->flush();
    }
}
