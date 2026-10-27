<?php

use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * CMS copy refresh (2026-10-27): sharper page headers for about / impact-investing / for-investors / for-entrepreneurs.
 * A stored value is replaced only while it still equals the previous seeded default, so copy edited by the council's
 * team in admin → content is never touched. Rolling back restores the previous defaults the same way.
 */
return new class extends Migration
{
    /** [slug, section key, locale, previous default, new default] */
    private const CHANGES = [
        ['about', 'title', 'ar', 'عن المجلس السعودي للمستثمرين الاجتماعيين', 'نجمع المستثمرين حول أثرٍ يُقاس'],
        ['about', 'title', 'en', 'About the Saudi Council for Impact Investors', 'Bringing investors together around measurable impact'],
        ['about', 'lead', 'ar', 'كيان يجمع المستثمرين الاجتماعيين في المملكة حول رؤية واحدة: توجيه رأس المال نحو حلول قابلة للقياس لتحديات اجتماعية حقيقية.', 'المجلس السعودي للمستثمرين الاجتماعيين يوحّد جهود المستثمرين في المملكة لتوجيه رأس المال نحو حلول قابلة للقياس لتحديات اجتماعية وبيئية حقيقية.'],
        ['about', 'lead', 'en', 'A body uniting impact investors in the Kingdom around one vision: directing capital toward measurable solutions to real social challenges.', 'The Saudi Council for Impact Investors unites investors across the Kingdom to direct capital toward measurable solutions to real social and environmental challenges.'],
        ['impact-investing', 'title', 'ar', 'ما هو الاستثمار الاجتماعي (المؤثر)؟', 'استثمارٌ يُقاس بعائده وأثره'],
        ['impact-investing', 'title', 'en', 'What is impact investing?', 'Investment measured by its return and its impact'],
        ['impact-investing', 'lead', 'ar', 'استثمار يُقصد به تحقيق أثر اجتماعي أو بيئي إيجابي قابل للقياس إلى جانب عائد مالي.', 'الاستثمار الاجتماعي (المؤثر) استثمارٌ يُقصد به تحقيق أثر اجتماعي أو بيئي إيجابي قابل للقياس، إلى جانب عائد مالي.'],
        ['impact-investing', 'lead', 'en', 'Investment made with the intention to generate positive, measurable social or environmental impact alongside a financial return.', 'Impact investing is investment made with the intention to generate positive, measurable social or environmental impact alongside a financial return.'],
        ['for-investors', 'title', 'ar', 'للمستثمرين الاجتماعيين', 'استثمر في أثرٍ تراه وتقيسه'],
        ['for-investors', 'title', 'en', 'For impact investors', 'Invest in impact you can see and measure'],
        ['for-investors', 'lead', 'ar', 'انضم إلى شبكة من المستثمرين الذين يوجّهون رأس المال نحو أثر قابل للقياس.', 'فرص مُقيَّمة بمعايير معلنة، وترشيحات تطابق اهتماماتك، ومتابعة للأثر بعد الربط — ضمن شبكة من المستثمرين الاجتماعيين.'],
        ['for-investors', 'lead', 'en', 'Join a network of investors directing capital toward measurable impact.', 'Opportunities evaluated against published criteria, nominations matched to your interests and impact follow-up after connection — within a network of impact investors.'],
        ['for-entrepreneurs', 'title', 'ar', 'لأصحاب المشاريع الاجتماعية', 'من مشروعٍ ذي أثر إلى مستثمرٍ يؤمن به'],
        ['for-entrepreneurs', 'title', 'en', 'For social project owners', 'From a project with impact to an investor who believes in it'],
        ['for-entrepreneurs', 'lead', 'ar', 'قدّم مشروعك واحصل على تقييم مهني وفرصة للتواصل مع مستثمرين اجتماعيين مهتمين فعلًا بمجالك.', 'قدّم مشروعك عبر نموذج موحّد، واحصل على تقييم مهني بملاحظات واضحة، وتواصل مع مستثمرين مهتمين فعلًا بمجالك — بموافقتك أولًا.'],
        ['for-entrepreneurs', 'lead', 'en', 'Submit your project, receive a professional evaluation and connect with impact investors genuinely interested in your field.', 'Submit your project through one unified form, receive a professional evaluation with clear feedback and connect with investors genuinely interested in your field — with your consent first.'],
    ];

    public function up(): void
    {
        $this->swap(fn (array $c) => [$c[3], $c[4]]);
    }

    public function down(): void
    {
        $this->swap(fn (array $c) => [$c[4], $c[3]]);
    }

    /** @param  callable(array): array{0: string, 1: string}  $pick  [from, to] */
    private function swap(callable $pick): void
    {
        foreach (collect(self::CHANGES)->groupBy(0) as $slug => $changes) {
            $page = Page::query()->where('slug', $slug)->first();
            $meta = $page && is_array($page->meta) ? $page->meta : null;
            if (! $meta || ! is_array($meta['sections'] ?? null)) {
                continue;
            }
            $dirty = false;
            foreach ($changes as $c) {
                [$from, $to] = $pick($c);
                if (($meta['sections'][$c[1]][$c[2]] ?? null) === $from) {
                    $meta['sections'][$c[1]][$c[2]] = $to;
                    $dirty = true;
                }
            }
            if ($dirty) {
                $page->forceFill(['meta' => $meta])->save();
            }
        }
    }
};
