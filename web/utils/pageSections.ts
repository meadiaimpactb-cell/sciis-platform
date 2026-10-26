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
    sections: [
      { group: 'القسم الرئيسي', key: 'hero_eyebrow', label: 'العنوان التمهيدي', type: 'text', i18nKey: 'home.heroEyebrow' },
      { group: 'القسم الرئيسي', key: 'hero_title', label: 'العنوان الرئيسي', type: 'textarea', i18nKey: 'home.heroTitle' },
      { group: 'القسم الرئيسي', key: 'hero_text', label: 'النص التعريفي', type: 'textarea', i18nKey: 'home.heroText' },
      { group: 'المساران', key: 'paths_title', label: 'عنوان القسم', type: 'text', i18nKey: 'home.pathsTitle' },
      { group: 'المساران', key: 'paths_sub', label: 'الوصف', type: 'text', i18nKey: 'home.pathsSub' },
      { group: 'مسار المستثمر', key: 'investor_path_title', label: 'العنوان', type: 'text', i18nKey: 'home.investorPath.title' },
      { group: 'مسار المستثمر', key: 'investor_path_text', label: 'النص', type: 'textarea', i18nKey: 'home.investorPath.text' },
      { group: 'مسار المستثمر', key: 'investor_path_points', label: 'النقاط', type: 'list', i18nKey: 'home.investorPath.points' },
      { group: 'مسار صاحب المشروع', key: 'entrepreneur_path_title', label: 'العنوان', type: 'text', i18nKey: 'home.entrepreneurPath.title' },
      { group: 'مسار صاحب المشروع', key: 'entrepreneur_path_text', label: 'النص', type: 'textarea', i18nKey: 'home.entrepreneurPath.text' },
      { group: 'مسار صاحب المشروع', key: 'entrepreneur_path_points', label: 'النقاط', type: 'list', i18nKey: 'home.entrepreneurPath.points' },
      { group: 'كيف تعمل المنصة', key: 'how_title', label: 'عنوان القسم', type: 'text', i18nKey: 'home.howTitle' },
      { group: 'كيف تعمل المنصة', key: 'how_sub', label: 'الوصف', type: 'text', i18nKey: 'home.howSub' },
      { group: 'كيف تعمل المنصة', key: 'how_steps', label: 'الخطوات', type: 'items', i18nKey: 'home.how', fields: TITLE_TEXT },
      { group: 'تعريف الأثر', key: 'impact_title', label: 'العنوان', type: 'text', i18nKey: 'home.impactTitle' },
      { group: 'تعريف الأثر', key: 'impact_text', label: 'التعريف', type: 'textarea', i18nKey: 'home.impactText' },
      { group: 'تعريف الأثر', key: 'impact_points', label: 'النقاط', type: 'list', i18nKey: 'home.impactPoints' },
      { group: 'مزايا العضوية', key: 'benefits_title', label: 'العنوان', type: 'text', i18nKey: 'home.benefitsTitle' },
      { group: 'مزايا العضوية', key: 'benefits_sub', label: 'الوصف', type: 'text', i18nKey: 'home.benefitsSub' },
      { group: 'مزايا العضوية', key: 'benefits', label: 'المزايا', type: 'list', i18nKey: 'home.benefits' },
      { group: 'شريط الدعوة', key: 'cta_band_title', label: 'العنوان', type: 'text', i18nKey: 'home.ctaBandTitle' },
      { group: 'شريط الدعوة', key: 'cta_band_text', label: 'النص', type: 'text', i18nKey: 'home.ctaBandText' },
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
