/**
 * Editable marketing copy of the public pages (CMS → pages.meta.sections).
 * Each section is stored as `{ ar: value, en: value }`; the value shape follows `type`:
 *  - text / textarea → string
 *  - list            → string[]
 *  - items           → flat objects whose fields are listed in `fields` (same shapes as the i18n fallback).
 * `i18nKey` is the locale key used as the fallback when the CMS value is missing or empty.
 */
export type PageSectionType = 'text' | 'textarea' | 'list' | 'items'

export interface PageSectionField {
  key: string
  label: string
  type: 'text' | 'textarea' | 'boolean'
}

export interface PageSectionDef {
  key: string
  label: string
  type: PageSectionType
  i18nKey: string
  fields?: PageSectionField[]
  group?: string
}

export interface PublicPageDef {
  slug: PublicPageSlug
  label: string
  path: string
  sections: PageSectionDef[]
}

export type PublicPageSlug = 'home' | 'about' | 'impact-investing' | 'for-investors' | 'for-entrepreneurs'

export type SectionScalar = string | boolean | number | null
export type SectionItem = Record<string, SectionScalar>
export type SectionValue = string | string[] | SectionItem[] | null
export type PageSections = Record<string, { ar?: SectionValue; en?: SectionValue }>

/** Hard limits mirrored from the API rule (App\Rules\PageSections). */
export const SECTION_LIMITS = { maxString: 2000, maxItems: 20 } as const

const TITLE_TEXT: PageSectionField[] = [
  { key: 'title', label: 'العنوان', type: 'text' },
  { key: 'text', label: 'النص', type: 'textarea' },
]

export const PUBLIC_PAGE_SECTIONS: PublicPageDef[] = [
  {
    slug: 'home',
    label: 'الصفحة الرئيسية',
    path: '/',
    // Design v3 (landing.*). New keys on purpose: copy saved for the previous home design is not shown here.
    sections: [
      { group: 'القسم الرئيسي', key: 'hero_badge', label: 'الشارة أعلى القسم', type: 'text', i18nKey: 'landing.hero.eyebrow' },
      { group: 'القسم الرئيسي', key: 'headline', label: 'العنوان الرئيسي', type: 'textarea', i18nKey: 'landing.hero.title' },
      { group: 'القسم الرئيسي', key: 'headline_lead', label: 'النص التعريفي', type: 'textarea', i18nKey: 'landing.hero.lead' },
      { group: 'عمود المستثمرين', key: 'investors_label', label: 'التسمية', type: 'text', i18nKey: 'landing.hero.aLabel' },
      { group: 'عمود المستثمرين', key: 'investors_title', label: 'العنوان', type: 'text', i18nKey: 'landing.hero.aTitle' },
      { group: 'عمود المستثمرين', key: 'investors_text', label: 'النص', type: 'textarea', i18nKey: 'landing.hero.aText' },
      { group: 'عمود المستثمرين', key: 'investors_cta', label: 'نص الزر', type: 'text', i18nKey: 'landing.hero.aCta' },
      { group: 'عمود أصحاب المشاريع', key: 'projects_label', label: 'التسمية', type: 'text', i18nKey: 'landing.hero.bLabel' },
      { group: 'عمود أصحاب المشاريع', key: 'projects_title', label: 'العنوان', type: 'text', i18nKey: 'landing.hero.bTitle' },
      { group: 'عمود أصحاب المشاريع', key: 'projects_text', label: 'النص', type: 'textarea', i18nKey: 'landing.hero.bText' },
      { group: 'عمود أصحاب المشاريع', key: 'projects_cta', label: 'نص الزر', type: 'text', i18nKey: 'landing.hero.bCta' },
      { group: 'شريط المراحل', key: 'stages_label', label: 'التسمية', type: 'text', i18nKey: 'landing.stages.label' },
      { group: 'شريط المراحل', key: 'stages_list', label: 'المراحل', type: 'list', i18nKey: 'landing.stages.items' },
      { group: 'تعريف الأثر', key: 'impact_label', label: 'التسمية', type: 'text', i18nKey: 'landing.impact.label' },
      { group: 'تعريف الأثر', key: 'impact_heading', label: 'العنوان', type: 'textarea', i18nKey: 'landing.impact.title' },
      { group: 'تعريف الأثر', key: 'impact_body', label: 'النص', type: 'textarea', i18nKey: 'landing.impact.text' },
      { group: 'تعريف الأثر', key: 'impact_criteria', label: 'المعايير', type: 'list', i18nKey: 'landing.impact.criteria' },
      { group: 'مسار المستثمر', key: 'path_investor_title', label: 'العنوان', type: 'text', i18nKey: 'landing.paths.investor.title' },
      { group: 'مسار المستثمر', key: 'path_investor_text', label: 'النص', type: 'textarea', i18nKey: 'landing.paths.investor.text' },
      { group: 'مسار المستثمر', key: 'path_investor_points', label: 'النقاط', type: 'list', i18nKey: 'landing.paths.investor.points' },
      { group: 'مسار المستثمر', key: 'path_investor_cta', label: 'نص الزر', type: 'text', i18nKey: 'landing.paths.investor.cta' },
      { group: 'مسار المستثمر', key: 'path_investor_tool', label: 'رابط أداة الجاهزية', type: 'text', i18nKey: 'landing.paths.investor.tool' },
      { group: 'مسار صاحب المشروع', key: 'path_project_title', label: 'العنوان', type: 'text', i18nKey: 'landing.paths.project.title' },
      { group: 'مسار صاحب المشروع', key: 'path_project_text', label: 'النص', type: 'textarea', i18nKey: 'landing.paths.project.text' },
      { group: 'مسار صاحب المشروع', key: 'path_project_points', label: 'النقاط', type: 'list', i18nKey: 'landing.paths.project.points' },
      { group: 'مسار صاحب المشروع', key: 'path_project_cta', label: 'نص الزر', type: 'text', i18nKey: 'landing.paths.project.cta' },
      { group: 'مسار صاحب المشروع', key: 'path_project_tool', label: 'رابط أداة الجاهزية', type: 'text', i18nKey: 'landing.paths.project.tool' },
      { group: 'كيف تعمل المنصة', key: 'how_label', label: 'التسمية', type: 'text', i18nKey: 'landing.how.label' },
      { group: 'كيف تعمل المنصة', key: 'how_heading', label: 'العنوان', type: 'text', i18nKey: 'landing.how.title' },
      { group: 'كيف تعمل المنصة', key: 'how_steps_list', label: 'الخطوات', type: 'items', i18nKey: 'landing.how.steps', fields: [...TITLE_TEXT, { key: 'meta', label: 'ملاحظة قصيرة', type: 'text' }] },
      { group: 'كيف تعمل المنصة', key: 'how_note', label: 'الملاحظة أسفل القسم', type: 'text', i18nKey: 'landing.how.note' },
      { group: 'مزايا العضوية', key: 'benefits_label', label: 'التسمية', type: 'text', i18nKey: 'landing.benefits.label' },
      { group: 'مزايا العضوية', key: 'benefits_heading', label: 'العنوان', type: 'text', i18nKey: 'landing.benefits.title' },
      { group: 'مزايا العضوية', key: 'benefits_list', label: 'المزايا', type: 'list', i18nKey: 'landing.benefits.items' },
      { group: 'الدعوة للانضمام', key: 'join_title', label: 'العنوان', type: 'text', i18nKey: 'landing.cta.title' },
      { group: 'الدعوة للانضمام', key: 'join_text', label: 'النص', type: 'text', i18nKey: 'landing.cta.text' },
    ],
  },
  {
    slug: 'about',
    label: 'عن المجلس',
    path: '/about',
    sections: [
      { key: 'title', label: 'عنوان الصفحة', type: 'text', i18nKey: 'about.title' },
      { key: 'lead', label: 'المقدمة', type: 'textarea', i18nKey: 'about.lead' },
      { key: 'vision_text', label: 'الرؤية', type: 'textarea', i18nKey: 'about.visionText' },
      { key: 'mission_text', label: 'الرسالة', type: 'textarea', i18nKey: 'about.missionText' },
      { key: 'goals', label: 'الأهداف', type: 'list', i18nKey: 'about.goalsList' },
      { key: 'governance_text', label: 'نص هيكل الحوكمة', type: 'textarea', i18nKey: 'about.governanceText' },
    ],
  },
  {
    slug: 'impact-investing',
    label: 'الاستثمار الاجتماعي',
    path: '/impact-investing',
    sections: [
      { key: 'title', label: 'عنوان الصفحة', type: 'text', i18nKey: 'impactInvesting.title' },
      { key: 'lead', label: 'المقدمة', type: 'textarea', i18nKey: 'impactInvesting.lead' },
      { key: 'definition_text', label: 'التعريف', type: 'textarea', i18nKey: 'impactInvesting.definitionText' },
      { key: 'impact_def_text', label: 'تعريف الأثر', type: 'textarea', i18nKey: 'impactInvesting.impactDefText' },
      { key: 'characteristics', label: 'الخصائص الأربع', type: 'items', i18nKey: 'impactInvesting.chars', fields: TITLE_TEXT },
      { key: 'networks_text', label: 'وصف الشبكات الدولية', type: 'textarea', i18nKey: 'impactInvesting.networksText' },
      {
        key: 'networks', label: 'الشبكات الدولية', type: 'items', i18nKey: 'impactInvesting.networksList',
        fields: [{ key: 'name', label: 'الاسم', type: 'text' }, { key: 'desc', label: 'الوصف', type: 'textarea' }],
      },
    ],
  },
  {
    slug: 'for-investors',
    label: 'للمستثمرين',
    path: '/for-investors',
    sections: [
      { key: 'title', label: 'عنوان الصفحة', type: 'text', i18nKey: 'forInvestors.title' },
      { key: 'lead', label: 'المقدمة', type: 'textarea', i18nKey: 'forInvestors.lead' },
      { key: 'why', label: 'لماذا المنصة؟', type: 'items', i18nKey: 'forInvestors.why', fields: TITLE_TEXT },
      { key: 'journey', label: 'رحلة العضوية', type: 'items', i18nKey: 'forInvestors.journey', fields: TITLE_TEXT },
      { key: 'requirements', label: 'متطلبات العضوية', type: 'list', i18nKey: 'forInvestors.requirements' },
    ],
  },
  {
    slug: 'for-entrepreneurs',
    label: 'لأصحاب المشاريع',
    path: '/for-entrepreneurs',
    sections: [
      { key: 'title', label: 'عنوان الصفحة', type: 'text', i18nKey: 'forEntrepreneurs.title' },
      { key: 'lead', label: 'المقدمة', type: 'textarea', i18nKey: 'forEntrepreneurs.lead' },
      { key: 'criteria', label: 'معايير القبول', type: 'list', i18nKey: 'forEntrepreneurs.criteria' },
      {
        key: 'stages', label: 'مراحل التقييم', type: 'items', i18nKey: 'forEntrepreneurs.stages',
        fields: [...TITLE_TEXT, { key: 'sla', label: 'المدة (اختياري)', type: 'text' }],
      },
      {
        key: 'documents', label: 'المستندات المطلوبة', type: 'items', i18nKey: 'forEntrepreneurs.documents',
        fields: [{ key: 'name', label: 'اسم المستند', type: 'text' }, { key: 'required', label: 'إلزامي', type: 'boolean' }],
      },
    ],
  },
]

export const publicPageDef = (slug: string) => PUBLIC_PAGE_SECTIONS.find((p) => p.slug === slug)
