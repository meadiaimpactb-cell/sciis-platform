<script setup lang="ts">
/**
 * Live styleguide of design v3 (internal tester page /prototype): the colour tokens of both palettes, the type scale,
 * and the shared components rendered with sample data. Nothing here talks to the API; the sample labels are short
 * Arabic literals on purpose (tester page only).
 */
import { OPPORTUNITY_STRIP_STATUSES } from '~/types/enums'

const { label } = useEnumLabel()

const nav = [
  { id: 'sg-colors', t: 'الألوان' }, { id: 'sg-type', t: 'الخطوط' }, { id: 'sg-buttons', t: 'الأزرار' }, { id: 'sg-badges', t: 'الشارات' },
  { id: 'sg-forms', t: 'النماذج' }, { id: 'sg-chips', t: 'الرقائق والتبويبات' }, { id: 'sg-cards', t: 'البطاقات' }, { id: 'sg-data', t: 'الجداول والرسوم' },
  { id: 'sg-v3', t: 'مكوّنات v3' }, { id: 'sg-brand', t: 'الهوية' },
]

// Colour tokens (assets/css/tokens.css → tailwind.config.ts): swatch class · what to use it for
const swatches = [
  { name: 'bg0', cls: 'bg-bg0', use: 'bg-bg' },
  { name: 'bg1', cls: 'bg-bg1', use: 'bg-surface · .card' },
  { name: 'deep', cls: 'bg-deep', use: 'bg-deep' },
  { name: 'field', cls: 'bg-field', use: '.input' },
  { name: 'fg', cls: 'bg-fg', use: 'text-ink' },
  { name: 't2', cls: 'bg-t2', use: 'text-text' },
  { name: 't3', cls: 'bg-t3', use: 'text-muted' },
  { name: 'line', cls: 'bg-line', use: 'border-border' },
  { name: 'navy-50', cls: 'bg-navy-50', use: 'bg-navy-50' },
  { name: 'navy-100', cls: 'bg-navy-100', use: 'bg-navy-100' },
  { name: 'navy', cls: 'bg-navy', use: 'text-navy' },
  { name: 'accent', cls: 'bg-accent', use: 'bg-accent' },
  { name: 'accent-text', cls: 'bg-accent-text', use: 'text-accent-text' },
  { name: 'accent-hi', cls: 'bg-accent-hi', use: 'hover:bg-accent-hi' },
  { name: 'on-accent', cls: 'bg-on-accent', use: 'text-on-accent' },
  { name: 'success', cls: 'bg-success', use: 'text-success' },
  { name: 'warning', cls: 'bg-warning', use: 'text-warning' },
  { name: 'danger', cls: 'bg-danger', use: 'text-danger' },
  { name: 'on-status', cls: 'bg-on-status', use: 'text-on-status' },
]
const hues = ['slate', 'amber', 'orange', 'green', 'emerald', 'teal', 'blue', 'navy', 'indigo', 'violet', 'red', 'gray', 'charcoal']
const themes = [{ key: 'dark', t: 'اللوحة الداكنة (الافتراضية)' }, { key: 'light', t: 'اللوحة الفاتحة' }]

// Type scale (falak.sa proportions): 50 is the ceiling, 13 the floor
const typeScale = [
  { name: 'display', cls: 'display text-ink', px: '50 / 30', code: '.display' },
  { name: 'title', cls: 'section-title', px: '40 / 30', code: '.section-title' },
  { name: 'subtitle', cls: 'font-display text-3xl font-extrabold text-ink', px: '30', code: 'text-3xl' },
  { name: 'heading', cls: 'section-label', px: '25', code: '.section-label · text-2xl' },
  { name: 'card', cls: 'card-title', px: '20', code: '.card-title · text-xl' },
  { name: 'lead', cls: 'text-lg text-t2', px: '18', code: 'text-lg' },
  { name: 'desc', cls: 'text-desc text-t2', px: '16 (30)', code: '.text-desc · text-base' },
  { name: 'body', cls: 'text-sm text-t2', px: '14', code: 'text-sm' },
  { name: 'small', cls: 'text-xs text-t3', px: '13', code: 'text-xs' },
]

const variants = ['primary', 'accent', 'secondary', 'outline', 'ghost', 'link', 'danger', 'light'] as const
const sizes = [{ key: 'sm', t: 'صغير' }, { key: 'md', t: 'متوسط' }, { key: 'lg', t: 'كبير' }] as const
const oppStatuses = ['draft', 'under_review', 'needs_completion', 'eligible', 'negotiating', 'not_eligible'] as const
const memberStatuses = ['under_review', 'needs_completion', 'approved', 'rejected'] as const

// Form samples (local state only)
const form = reactive({ name: 'سُلّم للتعليم', email: 'team@example.com', city: '', sector: 'edu', note: '', agree: true, kind: 'investor', tags: ['edu'] as string[] })
const sectorOptions = [{ value: 'edu', label: 'التعليم' }, { value: 'health', label: 'الصحة' }, { value: 'env', label: 'البيئة' }]
const kindOptions = [{ value: 'investor', label: 'مستثمر', hint: 'فرد أو جهة' }, { value: 'owner', label: 'صاحب مشروع', hint: 'منشأة اجتماعية' }]

const chip = ref('all')
const chips = [{ key: 'all', t: 'الكل' }, { key: 'edu', t: 'التعليم' }, { key: 'health', t: 'الصحة' }]
const tabA = ref('all')
const tabB = ref('p-open')
const tabsA = [{ key: 'all', label: 'الكل', count: 12 }, { key: 'open', label: 'مفتوحة', count: 5 }, { key: 'closed', label: 'مغلقة' }]
const tabsB = [{ key: 'p-open', label: 'مفتوحة', count: 5 }, { key: 'p-review', label: 'قيد المراجعة' }, { key: 'p-closed', label: 'مغلقة' }]

const columns = [
  { key: 'name', label: 'المشروع', sortable: true },
  { key: 'sector', label: 'القطاع' },
  { key: 'score', label: 'التقييم', sortable: true },
  { key: 'status', label: 'الحالة' },
]
const rows = [
  { id: 1, name: 'سُلّم', sector: 'التعليم', score: 82, status: 'eligible' },
  { id: 2, name: 'رفق', sector: 'الصحة', score: 67, status: 'under_review' },
  { id: 3, name: 'بصيرة', sector: 'البيئة', score: 45, status: 'needs_completion' },
]
const series = [{ label: 'التعليم', value: 12 }, { label: 'الصحة', value: 8 }, { label: 'البيئة', value: 5 }, { label: 'التمكين', value: 3 }]

const stages = OPPORTUNITY_STRIP_STATUSES.map((s) => label('opportunityStatus', s))
type Pair = [{ label: string; done: boolean }, { label: string; done: boolean }]
const consentPending: Pair = [{ label: 'المستثمر', done: true }, { label: 'صاحب المشروع', done: false }]
const consentDone: Pair = [{ label: 'المستثمر', done: true }, { label: 'صاحب المشروع', done: true }]
</script>

<template>
  <section class="mb-14 space-y-6" aria-labelledby="sg-title">
    <!-- intro -->
    <section class="surface-hero rounded-2xl border border-border">
      <OrbitBackdrop />
      <div class="relative flex flex-col items-center px-6 py-12 text-center md:py-14">
        <SectionLabel pill>نظام التصميم v3</SectionLabel>
        <h2 id="sg-title" class="section-title mt-5">دليل الأنماط الحي</h2>
        <p class="section-sub mx-auto">كل ما في هذا الدليل مكوّنات المنصة الفعلية ورموز ألوانها، معروضة ببيانات تجريبية.</p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-sm text-t2">
          <SiteThemeToggle />
          <span>بدّل السمة من الترويسة (أو من هنا) لمراجعة اللوحتين الداكنة والفاتحة.</span>
        </div>
        <nav class="mt-8 flex flex-wrap justify-center gap-2" aria-label="أقسام الدليل">
          <a v-for="n in nav" :key="n.id" :href="`#${n.id}`" class="chip">{{ n.t }}</a>
        </nav>
      </div>
    </section>

    <!-- colours: both palettes side by side (nested data-theme) -->
    <SectionCard id="sg-colors" class="scroll-mt-24" title="الألوان" sub="رموز tokens.css — تتبدّل تلقائيًا مع السمة؛ لا ألوان حرفية في القوالب.">
      <div class="grid gap-4 xl:grid-cols-2">
        <div v-for="th in themes" :key="th.key" :data-theme="th.key" class="rounded-2xl border border-border bg-bg p-4 text-text">
          <p class="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm font-semibold text-ink">{{ th.t }} <code class="ltr text-xs font-normal text-t3">data-theme="{{ th.key }}"</code></p>
          <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5" role="list">
            <li v-for="s in swatches" :key="s.name" class="min-w-0">
              <span class="block h-11 rounded-xl border border-ink/15" :class="s.cls" />
              <span class="mt-1.5 block truncate text-xs font-semibold text-ink ltr text-start">{{ s.name }}</span>
              <span class="block truncate text-xs text-t3 ltr text-start" :title="s.use">{{ s.use }}</span>
            </li>
          </ul>
          <p class="mb-2 mt-5 text-xs font-semibold text-t2">ألوان الحالات <code class="ltr">var(--hue-*)</code></p>
          <ul class="flex flex-wrap gap-2" role="list">
            <li v-for="h in hues" :key="h" class="flex items-center gap-1.5 rounded-full border border-ink/15 py-1 pe-2.5 ps-1.5 text-xs text-t2 ltr">
              <span class="h-4 w-4 rounded-full" :style="{ background: `var(--hue-${h})` }" />{{ h }}
            </li>
          </ul>
        </div>
      </div>
    </SectionCard>

    <!-- type scale -->
    <SectionCard id="sg-type" class="scroll-mt-24" title="مقياس الخطوط" sub="نسب falak.sa: الحد الأعلى 50px والأدنى 13px (المقاس الثاني للجوال، وبين القوسين ارتفاع السطر).">
      <ul class="divide-y divide-border" role="list">
        <li v-for="s in typeScale" :key="s.name" class="grid items-baseline gap-2 py-4 md:grid-cols-[11rem_minmax(0,1fr)] md:gap-6">
          <span class="text-xs text-t3 ltr text-start"><b class="font-semibold text-ink">{{ s.name }}</b> · <span class="num">{{ s.px }}</span> px · {{ s.code }}</span>
          <p class="min-w-0" :class="s.cls">استثمار يصنع أثرًا يُقاس</p>
        </li>
      </ul>
    </SectionCard>

    <!-- buttons -->
    <SectionCard id="sg-buttons" class="scroll-mt-24" title="الأزرار" sub="AppButton: المتغيّرات × المقاسات، والحالات؛ وأزرار الموقع العامة site-btn.">
      <div class="space-y-3">
        <div v-for="v in variants" :key="v" class="flex flex-wrap items-center gap-3">
          <code class="w-20 shrink-0 text-xs text-t3 ltr text-start">{{ v }}</code>
          <AppButton v-for="s in sizes" :key="s.key" :variant="v" :size="s.key">{{ s.t }}</AppButton>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-border pt-4">
          <code class="w-20 shrink-0 text-xs text-t3 ltr text-start">states</code>
          <AppButton loading>جارٍ الحفظ</AppButton>
          <AppButton disabled>معطّل</AppButton>
          <AppButton variant="secondary" disabled>معطّل</AppButton>
          <AppButton variant="secondary" icon aria-label="بحث"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg></AppButton>
          <AppButton block class="sm:w-60">عرض كامل</AppButton>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-border pt-4">
          <code class="w-20 shrink-0 text-xs text-t3 ltr text-start">site-btn</code>
          <button type="button" class="site-btn site-btn--primary">أساسي</button>
          <button type="button" class="site-btn site-btn--secondary">ثانوي</button>
          <button type="button" class="site-btn site-btn--outline">محدّد</button>
          <button type="button" class="site-btn site-btn--primary site-btn--lg site-btn--glow">كبير متوهّج</button>
          <button type="button" class="site-icon-btn" aria-label="قائمة"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg></button>
          <a href="#sg-buttons" class="site-link">رابط بسهم <span class="inline-block rtl:rotate-180" aria-hidden="true">→</span></a>
          <a href="#sg-buttons" class="link">.link</a>
        </div>
      </div>
    </SectionCard>

    <!-- badges -->
    <SectionCard id="sg-badges" class="scroll-mt-24" title="الشارات" sub="AppBadge: ناعمة (soft) وصلبة (solid)، لحالات الفرص والعضوية.">
      <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2"><code class="w-20 shrink-0 text-xs text-t3 ltr text-start">opportunity</code><AppBadge v-for="s in oppStatuses" :key="s" kind="opportunity" :value="s" /></div>
        <div class="flex flex-wrap items-center gap-2"><code class="w-20 shrink-0 text-xs text-t3 ltr text-start">solid</code><AppBadge v-for="s in oppStatuses" :key="s" kind="opportunity" :value="s" :soft="false" /></div>
        <div class="flex flex-wrap items-center gap-2"><code class="w-20 shrink-0 text-xs text-t3 ltr text-start">membership</code><AppBadge v-for="s in memberStatuses" :key="s" kind="membership" :value="s" size="md" /></div>
        <div class="flex flex-wrap items-center gap-2"><code class="w-20 shrink-0 text-xs text-t3 ltr text-start">solid · md</code><AppBadge v-for="s in memberStatuses" :key="s" kind="membership" :value="s" size="md" :soft="false" /></div>
      </div>
    </SectionCard>

    <!-- forms -->
    <SectionCard id="sg-forms" class="scroll-mt-24" title="حقول النماذج" sub="FormField: نص، بريد، قائمة، نص طويل، خانة اختيار، بطاقات اختيار، رقائق؛ مع المساعدة والخطأ.">
      <div class="grid gap-5 md:grid-cols-2">
        <FormField v-model="form.name" name="sg-name" label="اسم المشروع" help="كما في السجل التجاري" required :maxlength="60" />
        <FormField v-model="form.email" name="sg-email" type="email" label="البريد الإلكتروني" ltr />
        <FormField v-model="form.city" name="sg-city" label="المدينة" error="هذا الحقل مطلوب" required />
        <FormField v-model="form.sector" name="sg-sector" type="select" label="القطاع" :options="sectorOptions" />
        <FormField v-model="form.note" name="sg-note" type="textarea" label="نبذة" placeholder="اكتب نبذة قصيرة…" :rows="3" :maxlength="200" class="md:col-span-2" />
        <FormField v-model="form.kind" name="sg-kind" type="radio-cards" label="نوع الحساب" :options="kindOptions" class="md:col-span-2" />
        <FormField v-model="form.tags" name="sg-tags" type="chips" label="الاهتمامات" :options="sectorOptions" />
        <FormField v-model="form.agree" name="sg-agree" type="checkbox" label="أوافق على الشروط" help="خانة اختيار مع نص مساعد" />
        <FormField name="sg-disabled" label="حقل معطّل" model-value="غير قابل للتعديل" disabled />
      </div>
    </SectionCard>

    <!-- chips & tabs -->
    <SectionCard id="sg-chips" class="scroll-mt-24" title="الرقائق والتبويبات" sub=".chip / .chip-active · .tab-pill / .tab-pill-active · Tabs (خط سفلي وأقراص).">
      <div class="space-y-5">
        <div class="flex flex-wrap gap-2">
          <button v-for="c in chips" :key="c.key" type="button" class="chip" :class="chip === c.key && 'chip-active'" :aria-pressed="chip === c.key" @click="chip = c.key">{{ c.t }}</button>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-for="c in chips" :key="c.key" type="button" class="tab-pill" :class="chip === c.key && 'tab-pill-active'" :aria-pressed="chip === c.key" @click="chip = c.key">{{ c.t }}</button>
        </div>
        <Tabs v-model="tabA" :tabs="tabsA" />
        <Tabs v-model="tabB" :tabs="tabsB" variant="pills" class="w-fit max-w-full" />
      </div>
    </SectionCard>

    <!-- cards -->
    <SectionCard id="sg-cards" class="scroll-mt-24" title="البطاقات" sub=".card · SectionCard · GlassCard · StatCard · EmptyState.">
      <div class="grid gap-4 lg:grid-cols-3">
        <div class="card card-pad">
          <p class="card-title">.card</p>
          <p class="mt-2 text-sm text-muted">بطاقة أساسية بحواف 20px وظل خفيف.</p>
        </div>
        <SectionCard title="SectionCard" sub="عنوان وشرح وإجراء">
          <template #actions><AppButton size="sm" variant="secondary">إجراء</AppButton></template>
          <p class="text-sm">محتوى القسم.</p>
        </SectionCard>
        <GlassCard>
          <p class="card-title">GlassCard</p>
          <p class="mt-2 text-sm text-t2">بطاقة زجاجية بحواف 22px للأبطال والإبرازات.</p>
        </GlassCard>
      </div>
      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="الفرص المنشورة" value="128" hint="+12 هذا الشهر" />
        <StatCard label="قيد المراجعة" value="18" tone="warning" />
        <StatCard label="مؤهّلة" value="42" tone="success" />
        <StatCard label="نسبة الإتمام" value="72%" tone="teal" />
      </div>
      <EmptyState class="mt-4" title="لا توجد فرص بعد" text="ستظهر الفرص هنا عند نشرها.">
        <AppButton size="sm" variant="secondary">تحديث</AppButton>
      </EmptyState>
    </SectionCard>

    <!-- table & charts -->
    <SectionCard id="sg-data" class="scroll-mt-24" title="الجداول والرسوم" sub="DataTable · CompletionRing · BarChart · DonutChart.">
      <DataTable :columns="columns" :rows="rows">
        <template #cell-name="{ value }"><span class="font-semibold text-ink">{{ value }}</span></template>
        <template #cell-score="{ value }"><span class="num">{{ value }}%</span></template>
        <template #cell-status="{ value }"><AppBadge kind="opportunity" :value="value" /></template>
      </DataTable>
      <div class="mt-4 grid items-center gap-4 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)]">
        <div class="card card-pad flex justify-center"><CompletionRing :percent="72" :size="120" :stroke="10" label="اكتمال الملف" /></div>
        <div class="card card-pad"><BarChart :series="series" /></div>
        <div class="card card-pad"><DonutChart :series="series" /></div>
      </div>
    </SectionCard>

    <!-- v3 components -->
    <SectionCard id="sg-v3" class="scroll-mt-24" title="مكوّنات v3 الجديدة" sub="SectionLabel · OrbitBackdrop · ImpactMeter · IndicatorCard · StageTrack · DualConsent.">
      <div class="grid gap-5 lg:grid-cols-2">
        <div class="flex flex-col items-start gap-4">
          <SectionLabel>تسمية قسم</SectionLabel>
          <SectionLabel pill>تسمية بإطار (للأبطال)</SectionLabel>
          <div class="surface-hero flex h-48 w-full items-center justify-center rounded-2xl border border-border">
            <OrbitBackdrop />
            <p class="relative font-display text-2xl font-extrabold text-ink">surface-hero + OrbitBackdrop</p>
          </div>
        </div>
        <GlassCard class="space-y-6">
          <ImpactMeter label="نسبة إتمام البرنامج" :value="31" :target="45" :baseline="12" percent />
          <ImpactMeter label="عدد المستفيدين" :value="1200" :target="2000" hint="منذ بداية العام" />
        </GlassCard>
        <IndicatorCard :sample="false" name="نسبة الطلاب المتمّين للبرنامج" badge="التعليم" :baseline="18" :target="60" :current="42" source="تقارير المشروع" frequency="ربع سنوي" :arc="false" />
        <div class="space-y-4">
          <DualConsent title="مشاركة بيانات التواصل" :parties="consentPending" />
          <DualConsent title="عقد اجتماع" :parties="consentDone" />
        </div>
        <div class="card card-pad lg:col-span-2">
          <p class="mb-4 text-sm font-semibold text-ink">StageTrack</p>
          <StageTrack :stages="stages" :current="3" label="مراحل الفرصة" />
        </div>
      </div>
    </SectionCard>

    <!-- brand -->
    <SectionCard id="sg-brand" class="scroll-mt-24" title="الهوية" sub="BrandMark (يتبع السمة) · BrandLogo (أبيض على الداكن، كحلي على الفاتح).">
      <div class="flex flex-wrap items-center gap-8">
        <BrandMark :width="44" />
        <BrandMark :width="44" :framed="false" />
        <BrandLogo />
        <BrandLogo variant="mark" />
        <span class="placeholder-img flex h-16 w-28 items-center justify-center text-xs">gradient-brand</span>
      </div>
    </SectionCard>
  </section>
</template>
