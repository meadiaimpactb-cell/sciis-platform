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
            // opportunity
            ['opportunity.access_policy', 'nomination_only', 'opportunity', 'سياسة وصول المستثمرين للفرص: nomination_only | browse_all | both (معلّق بانتظار الرأي القانوني)', true],
            ['opportunity.funding_display', 'band', 'opportunity', 'طريقة عرض مبلغ التمويل: band | exact | hidden', true],
            ['opportunity.max_nominations_per_opportunity', 5, 'opportunity', 'الحد الأقصى للترشيحات لكل فرصة', false],
            // sla
            ['sla.review_days', 5, 'sla', 'مدة الفرز الأولي (أيام)', false],
            ['sla.completion_days', 14, 'sla', 'مدة استكمال البيانات (أيام)', false],
            ['sla.evaluation_days', 10, 'sla', 'مدة التقييم (أيام)', false],
            ['sla.owner_decision_days', 7, 'sla', 'مدة قرار صاحب المشروع على طلب الاطلاع (أيام)', false],
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
            // notifications / reports
            ['notifications.sms_sender', 'SCIIS', 'notifications', 'اسم مرسل الرسائل النصية', false],
            ['reports.allowed_export_roles', ['super_admin', 'reports_viewer'], 'reports', 'الأدوار المسموح لها بتصدير التقارير', false],
            // legal versions
            ['legal.privacy_policy_version', '1.0', 'legal', 'إصدار سياسة الخصوصية', true],
            ['legal.terms_version', '1.0', 'legal', 'إصدار الشروط والأحكام', true],
            ['legal.nda_version', '1.0', 'legal', 'إصدار اتفاقية عدم الإفصاح', true],
            ['legal.nda_text', 'نص اتفاقية عدم الإفصاح — بانتظار الاعتماد القانوني', 'legal', 'نص اتفاقية عدم الإفصاح المعروض للمستثمر', false],
            // public
            ['public.featured_public', false, 'public', 'عرض فرص مختارة في الموقع العام', true],
        ];

        foreach ($rows as [$key, $value, $group, $description, $isPublic]) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group, 'description' => $description, 'is_public' => $isPublic]);
        }

        app(SettingsService::class)->flush();
    }
}
