/**
 * Mock database — synthetic data per SPEC §11. Everything here is labelled "(افتراضي)".
 * Lives in memory for the lifetime of the dev server (resets on restart).
 */
import type {
  AuditLog,
  Candidate,
  CompletionRequest,
  ContactMessage,
  ContentItem,
  DocumentResource,
  EvaluationAssignment,
  EvaluationCriterion,
  ImpactIndicator,
  InfoRequest,
  Interest,
  InternalNote,
  Meeting,
  Membership,
  Nomination,
  OpportunityFull,
  PublicFaq,
  SettingItem,
  StatusTransition,
  TaxonomyTerm,
  Teaser,
} from '~/types/api'
import type { OpportunityStatus, StaffRole, TaxonomyGroup, UserType } from '~/types/enums'

export const DEMO = '(افتراضي)'

const now = Date.now()
export const daysAgo = (n: number) => new Date(now - n * 86400000).toISOString()
export const daysAhead = (n: number) => new Date(now + n * 86400000).toISOString()

// ---------------------------------------------------------------- taxonomies
let termId = 1
const term = (group: TaxonomyGroup, key: string, label_ar: string, label_en: string, sort: number): TaxonomyTerm => ({
  id: termId++,
  group,
  key,
  label_ar,
  label_en,
  sort,
  is_active: true,
})

const sectorsRaw: [string, string, string][] = [
  ['education', 'التعليم', 'Education'],
  ['health', 'الصحة', 'Health'],
  ['employment', 'التوظيف والتمكين', 'Employment & empowerment'],
  ['housing', 'الإسكان', 'Housing'],
  ['food', 'الأمن الغذائي', 'Food security'],
  ['environment', 'البيئة', 'Environment'],
  ['water', 'المياه', 'Water'],
  ['energy', 'الطاقة النظيفة', 'Clean energy'],
  ['fintech', 'التمويل الشامل', 'Inclusive finance'],
  ['disability', 'ذوو الإعاقة', 'Disability'],
  ['elderly', 'كبار السن', 'Elderly care'],
  ['youth', 'الشباب', 'Youth'],
  ['women', 'تمكين المرأة', 'Women empowerment'],
  ['culture', 'الثقافة والتراث', 'Culture & heritage'],
  ['sports', 'الرياضة', 'Sports'],
  ['tourism', 'السياحة المجتمعية', 'Community tourism'],
  ['agriculture', 'الزراعة', 'Agriculture'],
  ['digital', 'التحول الرقمي', 'Digital inclusion'],
  ['nonprofit_capacity', 'بناء قدرات القطاع غير الربحي', 'Nonprofit capacity'],
]
const regionsRaw: [string, string, string][] = [
  ['riyadh', 'الرياض', 'Riyadh'],
  ['makkah', 'مكة المكرمة', 'Makkah'],
  ['madinah', 'المدينة المنورة', 'Madinah'],
  ['qassim', 'القصيم', 'Qassim'],
  ['eastern', 'الشرقية', 'Eastern'],
  ['asir', 'عسير', 'Asir'],
  ['tabuk', 'تبوك', 'Tabuk'],
  ['hail', 'حائل', 'Hail'],
  ['northern', 'الحدود الشمالية', 'Northern Borders'],
  ['jazan', 'جازان', 'Jazan'],
  ['najran', 'نجران', 'Najran'],
  ['baha', 'الباحة', 'Al Baha'],
  ['jouf', 'الجوف', 'Al Jouf'],
  ['abroad', 'خارج المملكة', 'Outside KSA'],
]
const investmentTypesRaw: [string, string, string][] = [
  ['equity', 'ملكية', 'Equity'],
  ['debt', 'دين', 'Debt'],
  ['pay_for_results', 'الدفع مقابل النتائج', 'Pay for results'],
  ['grant', 'منحة', 'Grant'],
  ['guarantee', 'ضمان', 'Guarantee'],
  ['patient_capital', 'رأس مال صبور', 'Patient capital'],
  ['convertible', 'قابل للتحويل', 'Convertible'],
  ['revenue_share', 'مشاركة الإيرادات', 'Revenue share'],
  ['blended', 'تمويل مختلط', 'Blended'],
]
const beneficiaryRaw: [string, string, string][] = [
  ['youth', 'الشباب', 'Youth'],
  ['women', 'النساء', 'Women'],
  ['pwd', 'ذوو الإعاقة', 'Persons with disabilities'],
  ['elderly', 'كبار السن', 'Elderly'],
  ['low_income', 'الأسر محدودة الدخل', 'Low-income families'],
  ['orphans', 'الأيتام', 'Orphans'],
  ['rural', 'المجتمعات الريفية', 'Rural communities'],
  ['smes', 'المنشآت الصغيرة', 'Small enterprises'],
  ['students', 'الطلاب', 'Students'],
  ['nonprofits', 'المنظمات غير الربحية', 'Nonprofits'],
]
const sdgsRaw: [string, string][] = [
  ['القضاء على الفقر', 'No poverty'],
  ['القضاء التام على الجوع', 'Zero hunger'],
  ['الصحة الجيدة والرفاه', 'Good health'],
  ['التعليم الجيد', 'Quality education'],
  ['المساواة بين الجنسين', 'Gender equality'],
  ['المياه النظيفة', 'Clean water'],
  ['طاقة نظيفة بأسعار معقولة', 'Affordable clean energy'],
  ['العمل اللائق ونمو الاقتصاد', 'Decent work'],
  ['الصناعة والابتكار', 'Industry & innovation'],
  ['الحد من أوجه عدم المساواة', 'Reduced inequalities'],
  ['مدن ومجتمعات مستدامة', 'Sustainable cities'],
  ['الاستهلاك والإنتاج المسؤولان', 'Responsible consumption'],
  ['العمل المناخي', 'Climate action'],
  ['الحياة تحت الماء', 'Life below water'],
  ['الحياة في البر', 'Life on land'],
  ['السلام والعدل', 'Peace & justice'],
  ['عقد الشراكات', 'Partnerships'],
]
const stagesRaw: [string, string, string][] = [
  ['idea', 'فكرة', 'Idea'],
  ['prototype', 'نموذج أولي', 'Prototype'],
  ['pre_seed', 'ما قبل البذرة', 'Pre-seed'],
  ['seed', 'بذرة', 'Seed'],
  ['early_stage', 'مرحلة مبكرة', 'Early stage'],
  ['later_stage', 'مرحلة متقدمة', 'Later stage'],
]
const rangesRaw: [string, string, string][] = [
  ['lt_250k', 'أقل من 250 ألف ر.س', '< SAR 250k'],
  ['250k_1m', '250 ألف – مليون ر.س', 'SAR 250k – 1M'],
  ['1m_3m', '1 – 3 مليون ر.س', 'SAR 1M – 3M'],
  ['3m_10m', '3 – 10 مليون ر.س', 'SAR 3M – 10M'],
  ['gt_10m', 'أكثر من 10 مليون ر.س', '> SAR 10M'],
]
const declineRaw: [string, string, string][] = [
  ['sector_fit', 'خارج القطاعات المستهدفة', 'Outside target sectors'],
  ['stage_fit', 'المرحلة غير مناسبة', 'Stage mismatch'],
  ['ticket_size', 'حجم التمويل غير مناسب', 'Ticket size mismatch'],
  ['timing', 'التوقيت غير مناسب', 'Timing'],
  ['capacity', 'لا توجد سعة حاليًا', 'No capacity now'],
  ['other', 'أخرى', 'Other'],
]
const howHeardRaw: [string, string, string][] = [
  ['referral', 'إحالة من عضو', 'Member referral'],
  ['event', 'فعالية', 'Event'],
  ['social', 'وسائل التواصل', 'Social media'],
  ['search', 'بحث على الإنترنت', 'Web search'],
  ['media', 'الإعلام', 'Media'],
  ['other', 'أخرى', 'Other'],
]

export const taxonomies: Record<TaxonomyGroup, TaxonomyTerm[]> = {
  sectors: sectorsRaw.map((r, i) => term('sectors', r[0], r[1], r[2], i + 1)),
  regions: regionsRaw.map((r, i) => term('regions', r[0], r[1], r[2], i + 1)),
  investment_types: investmentTypesRaw.map((r, i) => term('investment_types', r[0], r[1], r[2], i + 1)),
  beneficiary_groups: beneficiaryRaw.map((r, i) => term('beneficiary_groups', r[0], r[1], r[2], i + 1)),
  sdgs: sdgsRaw.map((r, i) => term('sdgs', `sdg_${i + 1}`, `${i + 1}. ${r[0]}`, `${i + 1}. ${r[1]}`, i + 1)),
  project_stages: stagesRaw.map((r, i) => term('project_stages', r[0], r[1], r[2], i + 1)),
  investment_ranges: rangesRaw.map((r, i) => term('investment_ranges', r[0], r[1], r[2], i + 1)),
  decline_reasons: declineRaw.map((r, i) => term('decline_reasons', r[0], r[1], r[2], i + 1)),
  how_heard: howHeardRaw.map((r, i) => term('how_heard', r[0], r[1], r[2], i + 1)),
}
export const nextTermId = () => termId++

export const tx = (group: TaxonomyGroup, key: string): TaxonomyTerm => {
  const t = taxonomies[group].find((x) => x.key === key)
  if (!t) throw new Error(`missing taxonomy ${group}.${key}`)
  return t
}
export const txById = (group: TaxonomyGroup, id: number) => taxonomies[group].find((x) => x.id === id)

// ---------------------------------------------------------------- users
export interface MockUser {
  id: number
  uuid: string
  type: UserType
  name: string
  email: string
  phone: string
  password: string
  locale: 'ar' | 'en'
  roles: StaffRole[]
  status: 'active' | 'suspended'
  email_verified_at: string | null
  phone_verified_at: string | null
  two_factor_enabled: boolean
  organization?: { uuid: string; name: string; institution_type?: string; legal_form?: string; city?: string; cr_number?: string } | null
  last_login_at: string | null
  created_at: string
  // investor
  profile?: InvestorProfileRow
  membership?: Membership | null
  preferred_contact?: 'email' | 'phone' | 'whatsapp'
  how_heard_key?: string
}

export interface InvestorProfileRow {
  country: string
  city: string
  employer: string
  job_title: string
  linkedin_url: string
  experience_years: number | null
  experience_desc: string
  prior_investments_count: number | null
  prior_investments_desc: string
  has_social_investments: boolean
  social_investments_desc: string
  risk_appetite: 'high' | 'medium' | 'low' | null
  mentoring_available: boolean
  mentoring_areas: string
  investment_range_id: number | null
  annual_investment_band_id: number | null
  preferred_contact: 'email' | 'phone' | 'whatsapp'
  how_heard_id: number | null
  conflicts_disclosure: string
  commit_meetings: boolean
  sectors: number[]
  stages: string[]
  investment_types: string[]
  regions: number[]
  causes: number[]
}

let userId = 1
const mkUser = (u: Omit<MockUser, 'id' | 'uuid' | 'password' | 'locale' | 'status' | 'created_at'> & Partial<MockUser>): MockUser => ({
  id: userId++,
  uuid: `u-${String(userId).padStart(4, '0')}-${u.email.split('@')[0]}`,
  password: 'password',
  locale: 'ar',
  status: 'active',
  created_at: daysAgo(90),
  ...u,
})

const emptyProfile = (): InvestorProfileRow => ({
  country: 'السعودية',
  city: '',
  employer: '',
  job_title: '',
  linkedin_url: '',
  experience_years: null,
  experience_desc: '',
  prior_investments_count: null,
  prior_investments_desc: '',
  has_social_investments: false,
  social_investments_desc: '',
  risk_appetite: null,
  mentoring_available: false,
  mentoring_areas: '',
  investment_range_id: null,
  annual_investment_band_id: null,
  preferred_contact: 'email',
  how_heard_id: null,
  conflicts_disclosure: '',
  commit_meetings: false,
  sectors: [],
  stages: [],
  investment_types: [],
  regions: [],
  causes: [],
})

export const users: MockUser[] = [
  // ---- investors (3)
  mkUser({
    type: 'investor_individual',
    name: `عبدالله الحربي ${DEMO}`,
    email: 'investor@demo.sciis.sa',
    phone: '+966500000001',
    roles: [],
    email_verified_at: daysAgo(80),
    phone_verified_at: daysAgo(80),
    two_factor_enabled: false,
    last_login_at: daysAgo(0),
    preferred_contact: 'email',
    profile: {
      ...emptyProfile(),
      city: 'الرياض',
      employer: 'مجموعة الحربي القابضة (افتراضي)',
      job_title: 'شريك إداري',
      linkedin_url: 'https://linkedin.com/in/demo-abdullah',
      experience_years: 12,
      experience_desc: 'استثمارات ملائكية في التعليم والتقنية الصحية منذ 2014.',
      prior_investments_count: 7,
      prior_investments_desc: 'سبعة استثمارات في مراحل البذرة والمبكرة، اثنان منها في مشاريع ذات أثر.',
      has_social_investments: true,
      social_investments_desc: 'استثمار في منصة تعليم مهني وصندوق قروض صغيرة.',
      risk_appetite: 'medium',
      mentoring_available: true,
      mentoring_areas: 'الحوكمة، التوسع، جمع التمويل',
      investment_range_id: tx('investment_ranges', '250k_1m').id,
      annual_investment_band_id: tx('investment_ranges', '1m_3m').id,
      how_heard_id: tx('how_heard', 'referral').id,
      conflicts_disclosure: 'لا يوجد',
      commit_meetings: true,
      sectors: [tx('sectors', 'education').id, tx('sectors', 'employment').id, tx('sectors', 'health').id],
      stages: ['seed', 'early_stage'],
      investment_types: ['equity', 'convertible', 'patient_capital'],
      regions: [tx('regions', 'riyadh').id, tx('regions', 'eastern').id],
      causes: [tx('beneficiary_groups', 'youth').id, tx('beneficiary_groups', 'women').id],
    },
    membership: { id: 1, status: 'approved', submitted_at: daysAgo(75), reviewed_at: daysAgo(72), approved_at: daysAgo(70), terms_accepted_at: daysAgo(70) },
  }),
  mkUser({
    type: 'investor_institutional',
    name: `مكتب آل سالم العائلي ${DEMO}`,
    email: 'familyoffice@demo.sciis.sa',
    phone: '+966500000002',
    roles: [],
    email_verified_at: daysAgo(60),
    phone_verified_at: daysAgo(60),
    two_factor_enabled: true,
    last_login_at: daysAgo(2),
    organization: { uuid: 'org-family-salem', name: `مكتب آل سالم العائلي ${DEMO}`, institution_type: 'family_office', city: 'جدة' },
    profile: {
      ...emptyProfile(),
      city: 'جدة',
      employer: 'مكتب آل سالم العائلي',
      job_title: 'مدير الاستثمارات',
      experience_years: 20,
      experience_desc: 'محفظة متنوعة تشمل العقار والصناعة والمشاريع الاجتماعية.',
      prior_investments_count: 25,
      prior_investments_desc: 'استثمارات مباشرة وعبر صناديق.',
      has_social_investments: true,
      social_investments_desc: 'وقف تعليمي ومشروع إسكان ميسر.',
      risk_appetite: 'low',
      mentoring_available: false,
      investment_range_id: tx('investment_ranges', '3m_10m').id,
      annual_investment_band_id: tx('investment_ranges', 'gt_10m').id,
      how_heard_id: tx('how_heard', 'event').id,
      conflicts_disclosure: 'عضوية مجلس إدارة في جمعية خيرية بجدة.',
      commit_meetings: true,
      sectors: [tx('sectors', 'housing').id, tx('sectors', 'health').id, tx('sectors', 'elderly').id, tx('sectors', 'food').id],
      stages: ['early_stage', 'later_stage'],
      investment_types: ['debt', 'patient_capital', 'blended', 'pay_for_results'],
      regions: [tx('regions', 'makkah').id, tx('regions', 'madinah').id],
      causes: [tx('beneficiary_groups', 'low_income').id, tx('beneficiary_groups', 'elderly').id],
    },
    membership: { id: 2, status: 'approved', submitted_at: daysAgo(58), reviewed_at: daysAgo(55), approved_at: daysAgo(54), terms_accepted_at: daysAgo(54) },
  }),
  mkUser({
    type: 'investor_institutional',
    name: `صندوق نماء للأثر ${DEMO}`,
    email: 'fund@demo.sciis.sa',
    phone: '+966500000003',
    roles: [],
    email_verified_at: daysAgo(5),
    phone_verified_at: daysAgo(5),
    two_factor_enabled: false,
    last_login_at: daysAgo(1),
    organization: { uuid: 'org-fund-namaa', name: `صندوق نماء للأثر ${DEMO}`, institution_type: 'fund', city: 'الرياض' },
    profile: {
      ...emptyProfile(),
      city: 'الرياض',
      employer: 'صندوق نماء للأثر',
      job_title: 'مدير المحفظة',
      experience_years: 8,
      experience_desc: 'صندوق متخصص في الاستثمار المؤثر بالمملكة.',
      prior_investments_count: 4,
      has_social_investments: true,
      social_investments_desc: 'أربع صفقات في التعليم والبيئة.',
      risk_appetite: 'high',
      mentoring_available: true,
      mentoring_areas: 'قياس الأثر',
      investment_range_id: tx('investment_ranges', '1m_3m').id,
      how_heard_id: tx('how_heard', 'social').id,
      commit_meetings: false,
      sectors: [tx('sectors', 'environment').id, tx('sectors', 'education').id, tx('sectors', 'agriculture').id],
      stages: ['seed', 'early_stage'],
      investment_types: ['equity', 'revenue_share'],
      regions: [tx('regions', 'riyadh').id, tx('regions', 'qassim').id, tx('regions', 'asir').id],
      causes: [tx('beneficiary_groups', 'rural').id],
    },
    membership: { id: 3, status: 'under_review', submitted_at: daysAgo(4), terms_accepted_at: null },
  }),
  mkUser({
    type: 'investor_individual',
    name: `نورة القحطاني ${DEMO}`,
    email: 'noura@demo.sciis.sa',
    phone: '+966500000004',
    roles: [],
    email_verified_at: daysAgo(2),
    phone_verified_at: daysAgo(2),
    two_factor_enabled: false,
    last_login_at: daysAgo(1),
    profile: { ...emptyProfile(), city: 'الدمام', experience_years: 3, sectors: [tx('sectors', 'women').id, tx('sectors', 'education').id], stages: ['pre_seed', 'seed'], investment_types: ['grant', 'equity'], regions: [tx('regions', 'eastern').id], investment_range_id: tx('investment_ranges', 'lt_250k').id, commit_meetings: true },
    membership: { id: 4, status: 'needs_completion', submitted_at: daysAgo(2), decision_note: 'بانتظار وصف الخبرة وإقرار تعارض المصالح', missing_items: ['experience_desc', 'conflicts_disclosure'] },
  }),
  // ---- entrepreneurs (3)
  mkUser({
    type: 'entrepreneur_entity',
    name: `سارة العتيبي ${DEMO}`,
    email: 'entrepreneur@demo.sciis.sa',
    phone: '+966500000011',
    roles: [],
    email_verified_at: daysAgo(40),
    phone_verified_at: daysAgo(40),
    two_factor_enabled: false,
    last_login_at: daysAgo(0),
    organization: { uuid: 'org-sullam', name: `شركة سُلّم للتعليم المهني ${DEMO}`, legal_form: 'company', city: 'الرياض', cr_number: '1010XXXXXX' },
  }),
  mkUser({
    type: 'entrepreneur_entity',
    name: `خالد الشهري ${DEMO}`,
    email: 'khalid@demo.sciis.sa',
    phone: '+966500000012',
    roles: [],
    email_verified_at: daysAgo(30),
    phone_verified_at: daysAgo(30),
    two_factor_enabled: false,
    last_login_at: daysAgo(3),
    organization: { uuid: 'org-rifq', name: `جمعية رفق لرعاية كبار السن ${DEMO}`, legal_form: 'nonprofit', city: 'مكة المكرمة' },
  }),
  mkUser({
    type: 'entrepreneur_individual',
    name: `ريم الدوسري ${DEMO}`,
    email: 'reem@demo.sciis.sa',
    phone: '+966500000013',
    roles: [],
    email_verified_at: daysAgo(12),
    phone_verified_at: daysAgo(12),
    two_factor_enabled: false,
    last_login_at: daysAgo(1),
  }),
  // ---- staff (full set)
  mkUser({ type: 'staff', name: `م. فهد السبيعي ${DEMO}`, email: 'admin@demo.sciis.sa', phone: '+966500000021', roles: ['super_admin'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(0) }),
  mkUser({ type: 'staff', name: `هند المطيري ${DEMO}`, email: 'membership@demo.sciis.sa', phone: '+966500000022', roles: ['membership_officer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(0) }),
  mkUser({ type: 'staff', name: `عمر الزهراني ${DEMO}`, email: 'reviewer@demo.sciis.sa', phone: '+966500000023', roles: ['reviewer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(1) }),
  mkUser({ type: 'staff', name: `د. لمى الغامدي ${DEMO}`, email: 'evaluator1@demo.sciis.sa', phone: '+966500000024', roles: ['evaluator'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(2) }),
  mkUser({ type: 'staff', name: `م. يوسف العنزي ${DEMO}`, email: 'evaluator2@demo.sciis.sa', phone: '+966500000025', roles: ['evaluator'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(2) }),
  mkUser({ type: 'staff', name: `منال الشمري ${DEMO}`, email: 'matching@demo.sciis.sa', phone: '+966500000026', roles: ['matching_officer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(0) }),
  mkUser({ type: 'staff', name: `أ. طارق البقمي ${DEMO}`, email: 'agreements@demo.sciis.sa', phone: '+966500000027', roles: ['agreements_officer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(7) }),
  mkUser({ type: 'staff', name: `أمل الجهني ${DEMO}`, email: 'impact@demo.sciis.sa', phone: '+966500000028', roles: ['impact_officer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(4) }),
  mkUser({ type: 'staff', name: `د. ناصر الفيصل ${DEMO}`, email: 'advisory@demo.sciis.sa', phone: '+966500000029', roles: ['advisory_member'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(20) }),
  mkUser({ type: 'staff', name: `سلمان الراشد ${DEMO}`, email: 'reports@demo.sciis.sa', phone: '+966500000030', roles: ['reports_viewer'], email_verified_at: daysAgo(100), phone_verified_at: daysAgo(100), two_factor_enabled: true, last_login_at: daysAgo(9) }),
]

export const userByEmail = (email: string) => users.find((u) => u.email.toLowerCase() === email.toLowerCase())
export const userByUuid = (uuid: string) => users.find((u) => u.uuid === uuid)
export const userById = (id: number) => users.find((u) => u.id === id)
export const nextUserId = () => userId++

const investor1 = users[0]
const investor2 = users[1]
const investor3 = users[2]
const investor4 = users[3]
const ent1 = users[4]
const ent2 = users[5]
const ent3 = users[6]
const admin = users[7]
const reviewer = users[9]
const evaluator1 = users[10]
const evaluator2 = users[11]
const matching = users[12]

// ---------------------------------------------------------------- opportunities
const ind = (p: Partial<ImpactIndicator> & Pick<ImpactIndicator, 'name' | 'unit' | 'baseline' | 'target'>): ImpactIndicator => ({
  id: Math.floor(Math.random() * 1e6),
  definition: '',
  data_source: 'سجلات المشروع',
  frequency: 'quarterly',
  verification_method: 'مراجعة سجلات + عينة',
  sdg_id: null,
  is_primary: false,
  is_disclosed: true,
  latest_value: null,
  ...p,
})

let docSeq = 1
const doc = (kind: DocumentResource['kind'], title: string, status: DocumentResource['status'] = 'accepted', sensitivity: DocumentResource['sensitivity'] = 'confidential'): DocumentResource => ({
  uuid: `doc-${String(docSeq++).padStart(3, '0')}`,
  kind,
  title: `${title} ${DEMO}`,
  mime: 'application/pdf',
  size_bytes: 1_200_000 + docSeq * 37_000,
  sensitivity,
  status,
  reviewer_note: status === 'rejected' ? 'الملف غير مقروء — يرجى رفع نسخة واضحة' : null,
  version: 1,
  created_at: daysAgo(10 + docSeq),
  scan_result: 'clean',
})

export interface OpportunityRow extends OpportunityFull {
  owner_user_id: number
  sector_key: string
  impact_score: number
  teaser: Teaser | null
  transitions: StatusTransition[]
  notes: InternalNote[]
  completion_requests: CompletionRequest[]
  assigned_reviewer_id: number | null
  evaluation_assignments: EvaluationAssignment[]
  evaluation_decision: {
    result: OpportunityFull['evaluation_result']
    conditions?: string | null
    reasons?: string | null
    decided_by: { name: string }
    decided_at: string
  } | null
  is_demo: true
}

const baseOpp = (o: Partial<OpportunityRow> & Pick<OpportunityRow, 'uuid' | 'name' | 'short_description' | 'sector_key' | 'stage' | 'status' | 'owner_user_id'>): OpportunityRow => {
  const sector = tx('sectors', o.sector_key)
  return {
    reference_no: null,
    is_listed: true,
    sector_id: sector.id,
    website: null,
    social_links: {},
    region_ids: [tx('regions', 'riyadh').id],
    investment_type_keys: ['equity'],
    beneficiary_group_ids: [],
    sdg_ids: [],
    organization: undefined,
    problem_statement: '',
    solution: '',
    expected_change: '',
    beneficiaries_desc: '',
    beneficiaries_current: null,
    beneficiaries_target: null,
    revenue_model_type: null,
    revenue_model_desc: null,
    revenue_state: null,
    last_year_revenue_band_id: null,
    funding_amount: null,
    use_of_funds: [],
    prior_funding: false,
    prior_funding_desc: null,
    legal_disputes: false,
    legal_disputes_desc: null,
    license_required: false,
    license_status: null,
    operational_risks: null,
    council_relationship: false,
    council_relationship_desc: null,
    data_accuracy_declared_at: null,
    teaser_publication_consent: false,
    indicators: [],
    documents: [],
    submitted_at: null,
    review_due_at: null,
    completion_due_at: null,
    evaluation_due_at: null,
    evaluation_result: null,
    execution_status: null,
    created_at: daysAgo(40),
    updated_at: daysAgo(1),
    locked_sections: [],
    impact_score: 70,
    teaser: null,
    transitions: [],
    notes: [],
    completion_requests: [],
    assigned_reviewer_id: null,
    evaluation_assignments: [],
    evaluation_decision: null,
    is_demo: true,
    ...o,
  }
}

const teaser = (summary_ar: string, highlights: string[], approved = true): Teaser => ({
  summary_ar,
  summary_en: null,
  highlights,
  version: 1,
  approved_by_owner_at: approved ? daysAgo(15) : null,
  approved_by_council_at: approved ? daysAgo(14) : null,
})

const transition = (id: number, from: OpportunityStatus | null, to: OpportunityStatus, actor: string, days: number, reason?: string): StatusTransition => ({
  id,
  from_status: from,
  to_status: to,
  actor: { name: actor },
  reason: reason ?? null,
  created_at: daysAgo(days),
})

export const opportunities: OpportunityRow[] = [
  baseOpp({
    uuid: 'opp-sullam',
    reference_no: 'A-2026-0007',
    name: `سُلّم ${DEMO}`,
    short_description: 'منصة تدريب مهني مكثّف تربط الشباب الباحثين عن عمل بوظائف في قطاع الخدمات اللوجستية خلال 12 أسبوعًا.',
    sector_key: 'employment',
    stage: 'seed',
    status: 'eligible',
    owner_user_id: ent1.id,
    organization: { name: ent1.organization!.name, legal_form: 'company', cr_number: '1010XXXXXX', founded_on: '2023-03-01', employees_count: 14, city: 'الرياض' },
    website: 'https://sullam.example',
    region_ids: [tx('regions', 'riyadh').id, tx('regions', 'eastern').id],
    investment_type_keys: ['equity', 'convertible'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'youth').id, tx('beneficiary_groups', 'low_income').id],
    sdg_ids: [tx('sdgs', 'sdg_8').id, tx('sdgs', 'sdg_4').id],
    problem_statement: 'نسبة بطالة مرتفعة بين الشباب من 20 إلى 29 عامًا في المدن الثانوية، مع فجوة مهارات واضحة في القطاع اللوجستي الذي ينمو بسرعة.',
    solution: 'معسكرات تدريب مدفوعة بالتوظيف: شراكات مع 11 شركة لوجستية تضمن مقابلات لكل خريج، ونموذج دفع جزئي من صاحب العمل عند التوظيف.',
    expected_change: 'رفع معدل التوظيف خلال 90 يومًا من التخرج من 31% إلى 70%، ورفع متوسط الدخل الأول بنسبة 25%.',
    beneficiaries_desc: 'شباب سعوديون من الأسر محدودة الدخل، 60% منهم بلا خبرة سابقة.',
    beneficiaries_current: 420,
    beneficiaries_target: 2500,
    revenue_model_type: 'رسوم من صاحب العمل عند التوظيف + رسوم مخفضة من المتدرب',
    revenue_model_desc: 'يدفع صاحب العمل 4,500 ر.س لكل موظف يُثبّت بعد 90 يومًا.',
    revenue_state: 'early',
    last_year_revenue_band_id: tx('investment_ranges', '250k_1m').id,
    funding_amount: 2_400_000,
    use_of_funds: [
      { item: 'توسيع المعسكرات إلى 3 مدن', percent: 45 },
      { item: 'منصة التعلم والمتابعة', percent: 25 },
      { item: 'فريق التوظيف والشراكات', percent: 20 },
      { item: 'رأس مال عامل', percent: 10 },
    ],
    prior_funding: true,
    prior_funding_desc: 'منحة 300 ألف ر.س من برنامج حكومي في 2024.',
    legal_disputes: false,
    license_required: true,
    license_status: 'ترخيص مركز تدريب ساري حتى 2027',
    operational_risks: 'اعتماد على 3 شركاء توظيف كبار يمثلون 55% من الطلب. مخاطر تذبذب التوظيف الموسمي.',
    data_accuracy_declared_at: daysAgo(30),
    teaser_publication_consent: true,
    // team & founders (س) / market & product / motivation (د)
    founders_count: 2,
    team_size: 14,
    founders_commitment: 'full_time',
    prior_startup_experience: true,
    prior_startup_experience_desc: 'أسست الرئيسة التنفيذية مركز تدريب مهني صغير (2017–2021) ثم بيع لجهة تعليمية.',
    industry_experience: true,
    industry_experience_desc: 'عشر سنوات في الموارد البشرية والتوظيف بقطاع الخدمات اللوجستية.',
    founders_known_years: '5_plus',
    has_advisory_board: true,
    advisory_board_desc: 'مستشاران من شركات لوجستية كبرى ومستشارة في قياس الأثر.',
    dev_team_structure: 'hybrid',
    founders_agreement_signed: true,
    founders: [
      { name: `هيفاء العتيبي ${DEMO}`, role: 'الرئيسة التنفيذية', linkedin_url: null, is_full_time: true },
      { name: `ماجد الشهري ${DEMO}`, role: 'مدير الشراكات والتوظيف', linkedin_url: null, is_full_time: true },
    ],
    product_stage: 'launched',
    target_customers: 'الشباب الباحثون عن عمل (20–29 عامًا) في المدن الثانوية، وشركات الخدمات اللوجستية التي تحتاج كوادر مؤهلة.',
    market_size_desc: 'نحو 180 ألف وظيفة لوجستية جديدة متوقعة حتى 2030 (SAM)؛ نستهدف 2% منها خلال 5 سنوات (SOM).',
    competitors: 'معاهد التدريب التقليدية وبرامج التوظيف الحكومية العامة.',
    competitive_advantage: 'تدريب مرتبط بمقابلات مضمونة لدى 11 شركة شريكة ونموذج دفع عند التوظيف.',
    go_to_market: 'شراكات مع صندوق الموارد البشرية والجمعيات الأهلية، وحملات رقمية في المدن الثانوية.',
    technology_desc: 'منصة تعلم ومتابعة خاصة مع لوحة لأصحاب العمل.',
    traction_desc: '610 خريجين، معدل توظيف 54% خلال 90 يومًا، و11 شركة شريكة.',
    scalability_desc: 'نموذج معسكر قابل للتكرار في 3 مدن جديدة بتكلفة إطلاق محدودة لكل مدينة.',
    why_now: 'نمو القطاع اللوجستي ضمن رؤية 2030 وتوفر شركاء التوظيف يجعل التوسع الآن مجديًا.',
    support_needed: ['investor_intros', 'market_access', 'impact_measurement'],
    program_expectations: 'إغلاق جولة التمويل خلال 6 أشهر وبناء إطار قياس أثر معتمد.',
    how_heard_id: tx('how_heard', 'event').id,
    indicators: [
      ind({ name: 'معدل التوظيف خلال 90 يومًا', unit: '%', baseline: 31, target: 70, is_primary: true, data_source: 'تأكيدات أصحاب العمل + التأمينات', latest_value: 54, sdg_id: tx('sdgs', 'sdg_8').id }),
      ind({ name: 'عدد الخريجين السنوي', unit: 'خريج', baseline: 420, target: 1200, data_source: 'سجل المنصة', latest_value: 610 }),
      ind({ name: 'متوسط الراتب الأول', unit: 'ر.س', baseline: 4200, target: 5250, data_source: 'استبيان بعد 3 أشهر', is_disclosed: false }),
    ],
    documents: [
      doc('pitch_deck', 'العرض التعريفي لسُلّم'),
      doc('commercial_register', 'السجل التجاري'),
      doc('financial_statements', 'القوائم المالية 2025'),
      doc('team_cv', 'السير الذاتية للفريق'),
      doc('impact_evidence', 'تقرير أثر الدفعة الثالثة'),
    ],
    submitted_at: daysAgo(30),
    evaluation_result: 'eligible',
    created_at: daysAgo(45),
    updated_at: daysAgo(2),
    impact_score: 88,
    teaser: teaser('سُلّم تحوّل الشباب الباحثين عن عمل إلى موظفين في القطاع اللوجستي خلال 12 أسبوعًا، بنموذج يدفع فيه صاحب العمل عند التوظيف الفعلي. 420 خريجًا حتى الآن و11 شريك توظيف.', ['11 شريك توظيف يضمنون المقابلات', 'نموذج دفع مرتبط بالنتائج', 'ترخيص تدريب ساري']),
    transitions: [
      transition(1, null, 'draft', ent1.name, 45),
      transition(2, 'draft', 'new', ent1.name, 30),
      transition(3, 'new', 'under_review', reviewer.name, 27),
      transition(4, 'under_review', 'eligible', admin.name, 14, 'تقييم مكتمل من مقيّمَين — مؤهّل'),
    ],
    notes: [
      { id: 1, author: { name: reviewer.name }, body: 'الشراكات موثقة بخطابات. نقطة قوة: نموذج الدفع عند التوظيف.', created_at: daysAgo(26) },
      { id: 2, author: { name: matching.name }, body: 'مرشّح جيد لعبدالله الحربي (قطاع + مرحلة + منطقة).', created_at: daysAgo(12) },
    ],
    assigned_reviewer_id: reviewer.id,
    locked_sections: ['basics', 'organization', 'project', 'impact', 'financials'],
  }),
  baseOpp({
    uuid: 'opp-mahara',
    reference_no: 'A-2026-0009',
    name: `مهارة ${DEMO}`,
    short_description: 'ورش إنتاج حرفي للنساء في المناطق الريفية مع قناة بيع رقمية وضمان شراء من شركاء تجزئة.',
    sector_key: 'women',
    stage: 'early_stage',
    status: 'eligible',
    owner_user_id: ent3.id,
    region_ids: [tx('regions', 'asir').id, tx('regions', 'baha').id],
    investment_type_keys: ['revenue_share', 'patient_capital'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'women').id, tx('beneficiary_groups', 'rural').id],
    sdg_ids: [tx('sdgs', 'sdg_5').id, tx('sdgs', 'sdg_8').id],
    problem_statement: 'نساء ريفيات يمتلكن مهارات حرفية لكن بلا وصول للأسواق أو تسعير عادل.',
    solution: 'ورش مجهزة في 4 قرى، ومنصة بيع رقمية، وعقود شراء مسبق مع 3 سلاسل تجزئة.',
    expected_change: 'دخل شهري ثابت لا يقل عن 2,000 ر.س لـ 300 حرفية.',
    beneficiaries_desc: 'نساء ريفيات في عسير والباحة، 70% منهن المعيل الأساسي.',
    beneficiaries_current: 85,
    beneficiaries_target: 300,
    revenue_model_type: 'هامش على المبيعات',
    revenue_model_desc: 'هامش 30% على المنتجات المباعة عبر المنصة وقنوات التجزئة.',
    revenue_state: 'regular',
    funding_amount: 1_100_000,
    use_of_funds: [
      { item: 'تجهيز ورشتين جديدتين', percent: 40 },
      { item: 'المخزون والمواد الخام', percent: 30 },
      { item: 'التسويق والمنصة', percent: 30 },
    ],
    operational_risks: 'تقلب الطلب الموسمي؛ اعتماد على لوجستيات الطرف الثالث.',
    data_accuracy_declared_at: daysAgo(35),
    teaser_publication_consent: true,
    indicators: [
      ind({ name: 'متوسط الدخل الشهري للحرفية', unit: 'ر.س', baseline: 650, target: 2000, is_primary: true, latest_value: 1350, data_source: 'كشوف المدفوعات' }),
      ind({ name: 'عدد الحرفيات النشطات', unit: 'حرفية', baseline: 85, target: 300, latest_value: 140 }),
    ],
    documents: [doc('pitch_deck', 'عرض مهارة'), doc('license', 'ترخيص المؤسسة'), doc('impact_evidence', 'تقرير الدخل الربعي')],
    submitted_at: daysAgo(35),
    evaluation_result: 'eligible_with_conditions',
    impact_score: 82,
    teaser: teaser('مهارة تمنح النساء الريفيات في عسير والباحة دخلًا ثابتًا عبر ورش حرفية مجهزة وقناة بيع رقمية وعقود شراء مسبق مع 3 سلاسل تجزئة.', ['عقود شراء مسبق موقّعة', 'إيرادات منتظمة', '85 حرفية نشطة']),
    transitions: [transition(5, 'draft', 'new', ent3.name, 35), transition(6, 'new', 'under_review', reviewer.name, 33), transition(7, 'under_review', 'eligible', admin.name, 18, 'مؤهّل بشروط: تدقيق مالي خارجي خلال 6 أشهر')],
    assigned_reviewer_id: reviewer.id,
    locked_sections: ['basics', 'project', 'impact', 'financials'],
  }),
  baseOpp({
    uuid: 'opp-rifq',
    reference_no: 'A-2026-0004',
    name: `رفق ${DEMO}`,
    short_description: 'رعاية منزلية لكبار السن بنموذج اشتراك ميسّر مع فريق تمريض مدرّب ومنصة متابعة للأسر.',
    sector_key: 'elderly',
    stage: 'early_stage',
    status: 'connected',
    owner_user_id: ent2.id,
    organization: { name: ent2.organization!.name, legal_form: 'nonprofit', founded_on: '2019-06-01', employees_count: 32, city: 'مكة المكرمة' },
    region_ids: [tx('regions', 'makkah').id],
    investment_type_keys: ['pay_for_results', 'debt'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'elderly').id, tx('beneficiary_groups', 'low_income').id],
    sdg_ids: [tx('sdgs', 'sdg_3').id],
    problem_statement: 'نقص خدمات الرعاية المنزلية الميسّرة لكبار السن في الأحياء الشعبية بمكة، وضغط على الأسر.',
    solution: 'اشتراك شهري متدرج حسب الدخل، فريق تمريض منزلي، ومنصة تتابع فيها الأسرة الزيارات والمؤشرات الصحية.',
    expected_change: 'خفض زيارات الطوارئ لكبار السن المشتركين بنسبة 40% خلال عام.',
    beneficiaries_desc: 'كبار السن فوق 65 عامًا من الأسر محدودة الدخل.',
    beneficiaries_current: 210,
    beneficiaries_target: 800,
    revenue_model_type: 'اشتراكات + عقود دفع مقابل النتائج',
    revenue_state: 'regular',
    funding_amount: 3_500_000,
    use_of_funds: [
      { item: 'توظيف وتدريب 20 ممرضًا', percent: 50 },
      { item: 'المنصة والأجهزة', percent: 20 },
      { item: 'مركبات التنقل', percent: 20 },
      { item: 'تشغيل', percent: 10 },
    ],
    prior_funding: true,
    prior_funding_desc: 'دعم من مؤسسة مانحة 800 ألف ر.س (2023).',
    license_required: true,
    license_status: 'ترخيص رعاية منزلية ساري',
    operational_risks: 'صعوبة استقطاب الكوادر التمريضية؛ اعتماد على عقد واحد مع جهة حكومية.',
    data_accuracy_declared_at: daysAgo(60),
    teaser_publication_consent: true,
    indicators: [
      ind({ name: 'انخفاض زيارات الطوارئ', unit: '%', baseline: 0, target: 40, is_primary: true, latest_value: 22, data_source: 'سجلات المستشفيات الشريكة' }),
      ind({ name: 'المشتركون النشطون', unit: 'مشترك', baseline: 210, target: 800, latest_value: 330 }),
    ],
    documents: [doc('pitch_deck', 'عرض رفق'), doc('license', 'ترخيص الرعاية المنزلية'), doc('financial_statements', 'القوائم المالية 2025'), doc('authorization_letter', 'خطاب تفويض')],
    submitted_at: daysAgo(60),
    evaluation_result: 'eligible',
    impact_score: 91,
    teaser: teaser('رفق تقدم رعاية منزلية ميسّرة لكبار السن في مكة عبر اشتراك متدرج وفريق تمريض ومنصة متابعة للأسر، مع عقد دفع مقابل النتائج يستهدف خفض زيارات الطوارئ 40%.', ['عقد دفع مقابل النتائج قائم', '210 مشتركًا نشطًا', 'ترخيص ساري']),
    transitions: [transition(8, 'draft', 'new', ent2.name, 60), transition(9, 'new', 'under_review', reviewer.name, 57), transition(10, 'under_review', 'eligible', admin.name, 40), transition(11, 'eligible', 'connected', matching.name, 10, 'موافقة مزدوجة مع مكتب آل سالم')],
    assigned_reviewer_id: reviewer.id,
    locked_sections: ['basics', 'organization', 'project', 'impact', 'financials', 'disclosures'],
  }),
  baseOpp({
    uuid: 'opp-shakkin',
    reference_no: 'A-2026-0012',
    name: `شكّن ${DEMO}`,
    short_description: 'نموذج إسكان إيجاري ميسّر للأسر الشابة عبر إعادة تأهيل مبانٍ قائمة بشراكة مع الملاك.',
    sector_key: 'housing',
    stage: 'later_stage',
    status: 'under_review',
    owner_user_id: ent2.id,
    region_ids: [tx('regions', 'makkah').id, tx('regions', 'madinah').id],
    investment_type_keys: ['debt', 'blended'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'low_income').id, tx('beneficiary_groups', 'youth').id],
    sdg_ids: [tx('sdgs', 'sdg_11').id],
    problem_statement: 'ارتفاع الإيجارات في مكة والمدينة يستنزف أكثر من 45% من دخل الأسر الشابة.',
    solution: 'إعادة تأهيل مبانٍ قديمة بعقود طويلة مع الملاك، وتأجيرها بأقل من السوق بنسبة 30%.',
    expected_change: 'خفض نسبة الإيجار من الدخل إلى 30% لـ 400 أسرة.',
    beneficiaries_desc: 'أسر شابة بدخل أقل من 9,000 ر.س شهريًا.',
    beneficiaries_current: 60,
    beneficiaries_target: 400,
    revenue_state: 'regular',
    funding_amount: 8_000_000,
    use_of_funds: [
      { item: 'إعادة تأهيل 3 مبانٍ', percent: 70 },
      { item: 'ضمانات وتأمين', percent: 15 },
      { item: 'تشغيل', percent: 15 },
    ],
    data_accuracy_declared_at: daysAgo(6),
    teaser_publication_consent: true,
    indicators: [ind({ name: 'نسبة الإيجار من الدخل', unit: '%', baseline: 45, target: 30, is_primary: true })],
    documents: [doc('pitch_deck', 'عرض شكّن', 'accepted'), doc('financial_projections', 'التوقعات المالية', 'pending_review'), doc('commercial_register', 'السجل التجاري', 'pending_review')],
    submitted_at: daysAgo(6),
    review_due_at: daysAhead(-1),
    evaluation_due_at: daysAhead(6),
    impact_score: 76,
    transitions: [transition(12, 'draft', 'new', ent2.name, 6), transition(13, 'new', 'under_review', reviewer.name, 4)],
    assigned_reviewer_id: reviewer.id,
    evaluation_assignments: [
      { id: 1, evaluator: { id: evaluator1.id, name: evaluator1.name }, status: 'scored', coi_declared_at: daysAgo(3), coi_has_conflict: false, evaluation: { id: 1, assignment_id: 1, criteria_version: 1, scores: { r_team: { score: 4 }, r_docs: { score: 3, note: 'التوقعات المالية تحتاج تفصيلًا' }, r_legal: { score: 4 }, f_market: { score: 4 }, f_model: { score: 3 }, f_financials: { score: 3 }, i_theory: { score: 4 }, i_indicators: { score: 3 }, i_scale: { score: 4 } }, axis_totals: { readiness: 3.7, feasibility: 3.3, impact: 3.7 }, overall: 3.6, recommendation: 'eligible_with_conditions', comment: 'مؤهّل بشرط تقديم توقعات مالية مفصلة لثلاث سنوات.', submitted_at: daysAgo(1) } },
      { id: 2, evaluator: { id: evaluator2.id, name: evaluator2.name }, status: 'scored', coi_declared_at: daysAgo(3), coi_has_conflict: false, evaluation: { id: 2, assignment_id: 2, criteria_version: 1, scores: { r_team: { score: 4 }, r_docs: { score: 2, note: 'السجل التجاري غير مرفق بوضوح' }, r_legal: { score: 3 }, f_market: { score: 5 }, f_model: { score: 4 }, f_financials: { score: 2 }, i_theory: { score: 4 }, i_indicators: { score: 4 }, i_scale: { score: 5 } }, axis_totals: { readiness: 3.0, feasibility: 3.7, impact: 4.3 }, overall: 3.7, recommendation: 'eligible_with_conditions', comment: 'أثر قوي وقابلية توسع؛ الجاهزية المستندية ضعيفة.', submitted_at: daysAgo(1) } },
    ],
    locked_sections: ['basics', 'project', 'impact', 'financials'],
  }),
  baseOpp({
    uuid: 'opp-hasad',
    reference_no: 'A-2026-0013',
    name: `حصاد ${DEMO}`,
    short_description: 'زراعة مائية في حاويات للمزارعين الصغار بالقصيم مع تعاقد شراء من المطاعم.',
    sector_key: 'agriculture',
    stage: 'seed',
    status: 'needs_completion',
    owner_user_id: ent3.id,
    region_ids: [tx('regions', 'qassim').id],
    investment_type_keys: ['equity', 'grant'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'rural').id, tx('beneficiary_groups', 'smes').id],
    sdg_ids: [tx('sdgs', 'sdg_2').id, tx('sdgs', 'sdg_12').id],
    problem_statement: 'استهلاك مياه مرتفع وهوامش ضعيفة لصغار المزارعين.',
    solution: 'حاويات زراعة مائية بتمويل تشغيلي، وعقود شراء من 20 مطعمًا.',
    expected_change: 'خفض استهلاك المياه 85% ورفع هامش المزارع 2x.',
    beneficiaries_desc: 'مزارعون صغار في القصيم.',
    beneficiaries_target: 150,
    revenue_state: 'early',
    funding_amount: 900_000,
    use_of_funds: [{ item: 'حاويات', percent: 60 }, { item: 'تشغيل', percent: 40 }],
    data_accuracy_declared_at: daysAgo(9),
    teaser_publication_consent: true,
    indicators: [ind({ name: 'استهلاك المياه لكل كجم', unit: 'لتر', baseline: 250, target: 40, is_primary: true })],
    documents: [doc('pitch_deck', 'عرض حصاد', 'accepted'), doc('team_cv', 'السير الذاتية', 'rejected'), doc('license', 'ترخيص زراعي', 'required')],
    submitted_at: daysAgo(9),
    completion_due_at: daysAhead(9),
    impact_score: 71,
    transitions: [transition(14, 'draft', 'new', ent3.name, 9), transition(15, 'new', 'needs_completion', reviewer.name, 5, 'نواقص: السير الذاتية غير مقروءة، الترخيص الزراعي مفقود، مؤشر ثانٍ مطلوب')],
    completion_requests: [
      { id: 1, due_at: daysAhead(9), items: [{ document_kind: 'team_cv', note: 'الملف المرفوع غير مقروء — ارفع نسخة PDF واضحة', done: false }, { document_kind: 'license', note: 'الترخيص الزراعي مطلوب', done: false }, { field: 'indicators', note: 'أضف مؤشرًا للدخل أو الهامش', done: false }], resolved_at: null, created_at: daysAgo(5), requested_by: { name: reviewer.name } },
    ],
    assigned_reviewer_id: reviewer.id,
  }),
  baseOpp({
    uuid: 'opp-namaa',
    reference_no: 'A-2026-0002',
    name: `نماء ${DEMO}`,
    short_description: 'قروض صغيرة بدون فوائد للمنشآت متناهية الصغر المملوكة لنساء مع مرافقة تدريبية.',
    sector_key: 'fintech',
    stage: 'later_stage',
    status: 'in_execution',
    owner_user_id: ent1.id,
    organization: { name: `مؤسسة نماء للتمويل الاجتماعي ${DEMO}`, legal_form: 'nonprofit', founded_on: '2018-01-01', employees_count: 40, city: 'الرياض' },
    region_ids: [tx('regions', 'riyadh').id, tx('regions', 'qassim').id, tx('regions', 'hail').id],
    investment_type_keys: ['debt', 'guarantee'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'women').id, tx('beneficiary_groups', 'smes').id],
    sdg_ids: [tx('sdgs', 'sdg_1').id, tx('sdgs', 'sdg_5').id],
    problem_statement: 'نقص التمويل الميسّر للمنشآت النسائية متناهية الصغر.',
    solution: 'محفظة قروض بدون فوائد مع مرافقة تدريبية ونسبة سداد 96%.',
    expected_change: 'تمويل 1,200 منشأة وخلق 2,000 فرصة عمل.',
    beneficiaries_desc: 'سيدات أعمال في المنشآت متناهية الصغر.',
    beneficiaries_current: 640,
    beneficiaries_target: 1200,
    revenue_state: 'regular',
    funding_amount: 12_000_000,
    use_of_funds: [{ item: 'محفظة القروض', percent: 85 }, { item: 'التدريب والمرافقة', percent: 10 }, { item: 'تشغيل', percent: 5 }],
    data_accuracy_declared_at: daysAgo(120),
    teaser_publication_consent: true,
    indicators: [ind({ name: 'عدد المنشآت الممولة', unit: 'منشأة', baseline: 640, target: 1200, is_primary: true, latest_value: 790 }), ind({ name: 'نسبة السداد', unit: '%', baseline: 96, target: 96, latest_value: 95.4 })],
    documents: [doc('pitch_deck', 'عرض نماء'), doc('financial_statements', 'القوائم المالية'), doc('agreement', 'اتفاقية التمويل الموقعة')],
    submitted_at: daysAgo(120),
    evaluation_result: 'eligible',
    execution_status: 'on_track',
    impact_score: 93,
    teaser: teaser('نماء تمنح قروضًا صغيرة بدون فوائد للمنشآت النسائية متناهية الصغر مع مرافقة تدريبية ونسبة سداد 96%؛ 640 منشأة ممولة حتى اليوم.', ['نسبة سداد 96%', '640 منشأة ممولة', 'تغطية 3 مناطق']),
    transitions: [transition(16, 'draft', 'new', ent1.name, 120), transition(17, 'new', 'under_review', reviewer.name, 117), transition(18, 'under_review', 'eligible', admin.name, 100), transition(19, 'eligible', 'connected', matching.name, 80), transition(20, 'connected', 'negotiating', matching.name, 60), transition(21, 'negotiating', 'in_execution', admin.name, 30, 'توقيع اتفاقية تمويل')],
    locked_sections: ['basics', 'organization', 'project', 'impact', 'financials', 'disclosures'],
  }),
  baseOpp({
    uuid: 'opp-dawwir',
    reference_no: 'A-2026-0014',
    name: `دوّر ${DEMO}`,
    short_description: 'شبكة نقاط لإعادة تدوير البلاستيك في الأحياء مع حوافز نقدية للأسر وبيع المواد للمصانع.',
    sector_key: 'environment',
    stage: 'prototype',
    status: 'new',
    owner_user_id: ent3.id,
    region_ids: [tx('regions', 'riyadh').id],
    investment_type_keys: ['grant', 'equity'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'low_income').id],
    sdg_ids: [tx('sdgs', 'sdg_12').id, tx('sdgs', 'sdg_13').id],
    problem_statement: 'أقل من 5% من البلاستيك المنزلي يُعاد تدويره في الرياض.',
    solution: 'نقاط تجميع ذكية في الأحياء + تطبيق حوافز + تعاقد مع مصنعين.',
    expected_change: 'تدوير 500 طن سنويًا وخلق 40 فرصة عمل.',
    beneficiaries_desc: 'أسر في 12 حيًا.',
    beneficiaries_target: 5000,
    revenue_state: 'none',
    funding_amount: 650_000,
    use_of_funds: [{ item: 'نقاط التجميع', percent: 55 }, { item: 'التطبيق', percent: 25 }, { item: 'تشغيل', percent: 20 }],
    data_accuracy_declared_at: daysAgo(2),
    teaser_publication_consent: true,
    indicators: [ind({ name: 'الأطنان المعاد تدويرها', unit: 'طن', baseline: 0, target: 500, is_primary: true })],
    documents: [doc('pitch_deck', 'عرض دوّر', 'pending_review'), doc('team_cv', 'السير الذاتية', 'pending_review')],
    submitted_at: daysAgo(2),
    review_due_at: daysAhead(3),
    impact_score: 64,
    transitions: [transition(22, 'draft', 'new', ent3.name, 2)],
  }),
  baseOpp({
    uuid: 'opp-baseera',
    name: `بصيرة ${DEMO}`,
    short_description: 'أجهزة قراءة مساعدة منخفضة التكلفة لضعاف البصر مع برنامج تدريب في المدارس.',
    sector_key: 'disability',
    stage: 'idea',
    status: 'draft',
    owner_user_id: ent1.id,
    region_ids: [tx('regions', 'eastern').id],
    investment_type_keys: ['grant'],
    beneficiary_group_ids: [tx('beneficiary_groups', 'pwd').id, tx('beneficiary_groups', 'students').id],
    sdg_ids: [tx('sdgs', 'sdg_4').id, tx('sdgs', 'sdg_10').id],
    problem_statement: 'تكلفة أجهزة القراءة المساعدة تتجاوز 8,000 ر.س.',
    solution: '',
    expected_change: '',
    beneficiaries_desc: '',
    beneficiaries_target: 1000,
    revenue_state: 'none',
    funding_amount: null,
    indicators: [],
    documents: [],
    impact_score: 50,
    created_at: daysAgo(3),
    updated_at: daysAgo(0),
    transitions: [transition(23, null, 'draft', ent1.name, 3)],
  }),
]

export const oppByUuid = (uuid: string) => opportunities.find((o) => o.uuid === uuid)
let transitionSeq = 100
export const nextTransitionId = () => transitionSeq++
let noteSeq = 100
export const nextNoteId = () => noteSeq++
let completionSeq = 100
export const nextCompletionId = () => completionSeq++
export const nextDocUuid = () => `doc-${String(docSeq++).padStart(3, '0')}`

// ---------------------------------------------------------------- matching
export const nominations: (Nomination & { investor_user_id: number; opportunity_uuid: string })[] = [
  {
    id: 1,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-sullam',
    opportunity: null as unknown as Nomination['opportunity'],
    matched_criteria: { sector: true, stage: true, region: true, type: true, range: false },
    match_score: 4,
    rationale: 'تطابق في القطاع (التوظيف) والمرحلة (بذرة) والمنطقة (الرياض) ونوع الاستثمار (ملكية/قابل للتحويل).',
    teaser_version: 1,
    sent_at: daysAgo(12),
    response: 'interested',
    responded_at: daysAgo(11),
  },
  {
    id: 2,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-mahara',
    opportunity: null as unknown as Nomination['opportunity'],
    matched_criteria: { sector: false, stage: true, region: false, type: false, range: true },
    match_score: 2,
    rationale: 'تطابق في المرحلة والنطاق التمويلي؛ قطاع تمكين المرأة قريب من اهتمامك بالتوظيف.',
    teaser_version: 1,
    sent_at: daysAgo(4),
    response: null,
    responded_at: null,
  },
  {
    id: 3,
    investor_user_id: investor2.id,
    opportunity_uuid: 'opp-rifq',
    opportunity: null as unknown as Nomination['opportunity'],
    matched_criteria: { sector: true, stage: true, region: true, type: true, range: true },
    match_score: 5,
    rationale: 'تطابق كامل: كبار السن، مرحلة مبكرة، مكة، دفع مقابل النتائج، 3–10 مليون.',
    teaser_version: 1,
    sent_at: daysAgo(20),
    response: 'interested',
    responded_at: daysAgo(19),
  },
]
let nominationSeq = 10
export const nextNominationId = () => nominationSeq++

export const interests: (Interest & { investor_user_id: number; opportunity_uuid: string })[] = [
  {
    id: 1,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-sullam',
    opportunity: null as unknown as Interest['opportunity'],
    status: 'awaiting_owner_approval',
    owner_decision: null,
    note: 'مهتم بالتوسع إلى المنطقة الشرقية.',
    created_at: daysAgo(11),
    next_step: 'بانتظار موافقة صاحب المشروع على مشاركة الملف الكامل (مهلة 7 أيام)',
  },
  {
    id: 2,
    investor_user_id: investor2.id,
    opportunity_uuid: 'opp-rifq',
    opportunity: null as unknown as Interest['opportunity'],
    status: 'meeting',
    owner_decision: 'approved',
    owner_decided_at: daysAgo(17),
    created_at: daysAgo(19),
    next_step: 'اجتماع التعارف مجدول',
    nda_accepted_at: daysAgo(16),
  },
  {
    id: 3,
    investor_user_id: investor3.id,
    opportunity_uuid: 'opp-sullam',
    opportunity: null as unknown as Interest['opportunity'],
    status: 'sent',
    owner_decision: null,
    created_at: daysAgo(1),
    next_step: 'فريق المجلس يراجع الاهتمام',
  },
  {
    id: 4,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-namaa',
    opportunity: null as unknown as Interest['opportunity'],
    status: 'closed',
    owner_decision: 'declined',
    owner_decided_at: daysAgo(70),
    closed_reason: 'اكتمل التمويل مع مستثمر آخر',
    closed_at: daysAgo(70),
    created_at: daysAgo(78),
    next_step: null,
  },
]
let interestSeq = 10
export const nextInterestId = () => interestSeq++

export const infoRequests: (InfoRequest & { investor_user_id: number; opportunity_uuid: string })[] = [
  {
    id: 1,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-sullam',
    opportunity: null as unknown as InfoRequest['opportunity'],
    subject: 'تفاصيل اتفاقيات شركاء التوظيف',
    body: 'هل الاتفاقيات مع الشركات اللوجستية ملزمة؟ وما مدة كل اتفاقية؟',
    status: 'answered',
    owner_answer: 'ثلاث اتفاقيات ملزمة لمدة سنتين، والباقي مذكرات تفاهم قابلة للتجديد سنويًا.',
    answered_at: daysAgo(6),
    forwarded_to_owner_at: daysAgo(8),
    created_at: daysAgo(9),
  },
  {
    id: 2,
    investor_user_id: investor1.id,
    opportunity_uuid: 'opp-mahara',
    opportunity: null as unknown as InfoRequest['opportunity'],
    subject: 'هيكل الهامش',
    body: 'كيف يُوزع هامش 30% بين الحرفية والمنصة والتجزئة؟',
    status: 'processing',
    forwarded_to_owner_at: daysAgo(1),
    created_at: daysAgo(2),
  },
  {
    id: 3,
    investor_user_id: investor2.id,
    opportunity_uuid: 'opp-rifq',
    opportunity: null as unknown as InfoRequest['opportunity'],
    subject: 'خطة استقطاب الكوادر',
    body: 'ما خطة توظيف 20 ممرضًا خلال 12 شهرًا؟',
    status: 'sent',
    created_at: daysAgo(0),
  },
]
let infoSeq = 10
export const nextInfoId = () => infoSeq++

export const meetings: (Meeting & { participant_user_ids: number[]; opportunity_uuid: string; interest_id: number | null })[] = [
  {
    id: 1,
    opportunity_uuid: 'opp-rifq',
    interest_id: 2,
    opportunity: null as unknown as Meeting['opportunity'],
    title: 'اجتماع تعارف — رفق × مكتب آل سالم',
    starts_at: daysAhead(3),
    ends_at: daysAhead(3),
    location: null,
    video_url: 'https://meet.example/sciis-rifq',
    status: 'scheduled',
    participants: [{ name: investor2.name, role: 'investor' }, { name: ent2.name, role: 'owner' }, { name: matching.name, role: 'council' }],
    participant_user_ids: [investor2.id, ent2.id, matching.id],
    actions: [],
  },
  {
    id: 2,
    opportunity_uuid: 'opp-namaa',
    interest_id: null,
    opportunity: null as unknown as Meeting['opportunity'],
    title: 'متابعة ربع سنوية — نماء',
    starts_at: daysAgo(20),
    ends_at: daysAgo(20),
    location: 'مقر المجلس — الرياض',
    video_url: null,
    status: 'held',
    minutes: 'استعراض محفظة القروض، نسبة السداد 95.4%. الاتفاق على تقرير أثر نصف سنوي.',
    participants: [{ name: investor1.name, role: 'investor', attended: true }, { name: ent1.name, role: 'owner', attended: true }, { name: users[14].name, role: 'council', attended: true }],
    participant_user_ids: [investor1.id, ent1.id, users[14].id],
    actions: [{ description: 'إرسال تقرير الأثر نصف السنوي', owner: ent1.name, due_at: daysAhead(25), done_at: null }],
  },
  {
    id: 3,
    opportunity_uuid: 'opp-sullam',
    interest_id: 1,
    opportunity: null as unknown as Meeting['opportunity'],
    title: 'اجتماع تعارف — سُلّم × عبدالله الحربي',
    starts_at: daysAhead(8),
    ends_at: daysAhead(8),
    location: null,
    video_url: 'https://meet.example/sciis-sullam',
    status: 'scheduled',
    participants: [{ name: investor1.name, role: 'investor' }, { name: ent1.name, role: 'owner' }, { name: matching.name, role: 'council' }],
    participant_user_ids: [investor1.id, ent1.id, matching.id],
    actions: [],
  },
]
let meetingSeq = 10
export const nextMeetingId = () => meetingSeq++

export const savedByUser: Record<number, Set<string>> = {
  [investor1.id]: new Set(['opp-rifq', 'opp-mahara']),
  [investor2.id]: new Set(['opp-sullam']),
}
export const ndaAccepted: Record<number, Set<string>> = { [investor2.id]: new Set(['opp-rifq']) }
export const fileGrants: { opportunity_uuid: string; investor_user_id: number; granted_at: string; revoked_at: string | null }[] = [
  { opportunity_uuid: 'opp-rifq', investor_user_id: investor2.id, granted_at: daysAgo(17), revoked_at: null },
]

// ---------------------------------------------------------------- evaluation criteria (doc §12.5)
export const criteria: EvaluationCriterion[] = [
  { id: 1, axis: 'readiness', key: 'r_team', label_ar: 'الفريق المؤسس والتفرغ', label_en: 'Founding team & commitment', weight: 0.12, scale_descriptions: { '1': 'فرد واحد غير متفرغ', '2': 'فريق جزئي', '3': 'فريق متفرغ بخبرة محدودة', '4': 'فريق متفرغ بخبرة مناسبة', '5': 'فريق متكامل بسجل إنجاز' }, version: 1, is_active: true },
  { id: 2, axis: 'readiness', key: 'r_docs', label_ar: 'اكتمال المستندات وجودتها', label_en: 'Document completeness & quality', weight: 0.1, scale_descriptions: { '1': 'مستندات ناقصة', '2': 'ناقصة جزئيًا', '3': 'مكتملة بجودة متوسطة', '4': 'مكتملة وواضحة', '5': 'مكتملة ومدققة' }, version: 1, is_active: true },
  { id: 3, axis: 'readiness', key: 'r_legal', label_ar: 'الوضع النظامي والتراخيص', label_en: 'Legal status & licences', weight: 0.1, scale_descriptions: { '1': 'غير مسجّل', '2': 'مسجّل بدون تراخيص', '3': 'تراخيص قيد الاستخراج', '4': 'تراخيص سارية', '5': 'امتثال كامل' }, version: 1, is_active: true },
  { id: 4, axis: 'feasibility', key: 'f_market', label_ar: 'وضوح السوق والطلب', label_en: 'Market & demand clarity', weight: 0.12, scale_descriptions: { '1': 'افتراضات فقط', '2': 'مؤشرات أولية', '3': 'طلب مثبت محدود', '4': 'طلب مثبت بعقود', '5': 'طلب متكرر ومتنامٍ' }, version: 1, is_active: true },
  { id: 5, axis: 'feasibility', key: 'f_model', label_ar: 'نموذج الإيرادات والاستدامة', label_en: 'Revenue model & sustainability', weight: 0.12, scale_descriptions: { '1': 'لا نموذج', '2': 'نموذج نظري', '3': 'إيرادات مبكرة', '4': 'إيرادات منتظمة', '5': 'استدامة مالية' }, version: 1, is_active: true },
  { id: 6, axis: 'feasibility', key: 'f_financials', label_ar: 'الواقعية المالية والتوقعات', label_en: 'Financial realism & projections', weight: 0.1, scale_descriptions: { '1': 'غير واقعية', '2': 'متفائلة جدًا', '3': 'مقبولة', '4': 'واقعية ومبررة', '5': 'واقعية ومدققة' }, version: 1, is_active: true },
  { id: 7, axis: 'impact', key: 'i_theory', label_ar: 'نظرية التغيير ووضوح الأثر', label_en: 'Theory of change & impact clarity', weight: 0.12, scale_descriptions: { '1': 'أثر غير محدد', '2': 'أثر عام', '3': 'أثر محدد', '4': 'أثر محدد بمنطق واضح', '5': 'أثر محدد ومثبت' }, version: 1, is_active: true },
  { id: 8, axis: 'impact', key: 'i_indicators', label_ar: 'المؤشرات وقابلية القياس', label_en: 'Indicators & measurability', weight: 0.12, scale_descriptions: { '1': 'لا مؤشرات', '2': 'مؤشرات بلا خط أساس', '3': 'مؤشرات بخط أساس', '4': 'مؤشرات بخط أساس ومصدر', '5': 'مؤشرات متحقق منها' }, version: 1, is_active: true },
  { id: 9, axis: 'impact', key: 'i_scale', label_ar: 'حجم الأثر وقابلية التوسع', label_en: 'Scale & scalability of impact', weight: 0.1, scale_descriptions: { '1': 'محلي محدود', '2': 'محلي', '3': 'إقليمي', '4': 'وطني محتمل', '5': 'وطني مثبت' }, version: 1, is_active: true },
]
let criterionSeq = 20
export const nextCriterionId = () => criterionSeq++

// ---------------------------------------------------------------- settings (SPEC §7)
export const settings: SettingItem[] = [
  { key: 'platform.name_ar', value: 'منصة المجلس السعودي للمستثمرين الاجتماعيين', group: 'platform', description: 'الاسم الرسمي بالعربية' },
  { key: 'platform.name_en', value: 'Saudi Council for Impact Investors Platform', group: 'platform', description: 'الاسم الرسمي بالإنجليزية' },
  { key: 'platform.short_name', value: 'SCII', group: 'platform', description: 'الاسم المختصر (SCII | SCIIS — القرار معلّق)', pending_legal: true },
  { key: 'platform.official_email', value: 'info@sciis.sa', group: 'platform', description: 'البريد الرسمي الموحّد' },
  { key: 'platform.official_phone', value: '+966 11 000 0000', group: 'platform', description: 'الهاتف الرسمي' },
  { key: 'platform.whatsapp', value: null, group: 'platform', description: 'رقم واتساب (اختياري)' },
  { key: 'opportunity.access_policy', value: 'nomination_only', group: 'opportunity', description: 'nomination_only | browse_all | both', pending_legal: true },
  { key: 'opportunity.funding_display', value: 'band', group: 'opportunity', description: 'band | exact | hidden' },
  { key: 'opportunity.max_nominations_per_opportunity', value: 5, group: 'opportunity', description: 'الحد الأقصى للترشيحات لكل فرصة' },
  { key: 'sla.review_days', value: 5, group: 'sla', description: 'مهلة الفرز الأولي' },
  { key: 'sla.completion_days', value: 14, group: 'sla', description: 'مهلة الاستكمال' },
  { key: 'sla.evaluation_days', value: 10, group: 'sla', description: 'مهلة التقييم' },
  { key: 'sla.owner_decision_days', value: 7, group: 'sla', description: 'مهلة قرار صاحب المشروع' },
  { key: 'documents.max_size_mb', value: 25, group: 'documents', description: 'الحد الأقصى لحجم الملف' },
  { key: 'documents.allowed_mimes', value: ['application/pdf', 'image/png', 'image/jpeg', 'docx', 'xlsx', 'pptx'], group: 'documents', description: 'الصيغ المسموحة' },
  { key: 'privacy.retention_days.rejected', value: 365, group: 'privacy', description: 'الاحتفاظ بالطلبات المرفوضة' },
  { key: 'privacy.retention_days.withdrawn', value: 365, group: 'privacy', description: 'الاحتفاظ بالطلبات المسحوبة' },
  { key: 'privacy.retention_days.inactive', value: 730, group: 'privacy', description: 'الاحتفاظ بالحسابات غير النشطة' },
  { key: 'features.payments', value: false, group: 'features', description: 'المدفوعات' },
  { key: 'features.english', value: false, group: 'features', description: 'الواجهة الإنجليزية' },
  { key: 'features.browse_all', value: false, group: 'features', description: 'تصفح كل الفرص' },
  { key: 'features.watermark', value: false, group: 'features', description: 'علامة مائية على المستندات' },
  { key: 'features.dark_mode', value: false, group: 'features', description: 'الوضع الداكن' },
  { key: 'membership.fee_sar', value: 0, group: 'membership', description: 'رسوم العضوية (المرحلة التأسيسية مجانية)' },
  { key: 'membership.requires_prior_investments', value: 2, group: 'membership', description: 'الحد الأدنى للاستثمارات السابقة' },
  { key: 'notifications.sms_sender', value: 'SCIIS', group: 'notifications', description: 'اسم مرسل الرسائل النصية' },
  { key: 'reports.allowed_export_roles', value: ['super_admin', 'reports_viewer'], group: 'reports', description: 'الأدوار المسموح لها بالتصدير' },
]
export const setting = <T = unknown>(key: string): T => settings.find((s) => s.key === key)?.value as T

// ---------------------------------------------------------------- content
export const faqs: PublicFaq[] = [
  { id: 1, category: 'general', question: 'هل تُجري المنصة عمليات مالية؟', answer: 'لا. المنصة أداة ربط ومعلومات فقط؛ لا تجمع أموالًا ولا تحتفظ بها ولا تُصدر أوراقًا مالية ولا تقدم توصية استثمارية.' },
  { id: 2, category: 'membership', question: 'هل العضوية مدفوعة؟', answer: 'العضوية مجانية في المرحلة التأسيسية. أي رسوم مستقبلية ستُعلن مسبقًا.' },
  { id: 3, category: 'investor', question: 'هل يعرف صاحب المشروع هويتي عندما أبدي اهتمامًا؟', answer: 'لا. تُبلّغ المنصة صاحب المشروع باهتمام «مستثمر معتمد» دون كشف الهوية حتى يوافق على مشاركة ملفه الكامل.' },
  { id: 4, category: 'investor', question: 'كيف تُرشَّح لي الفرص؟', answer: 'بقاعدة مطابقة شفافة: القطاع، المرحلة، المنطقة، نوع الاستثمار، والنطاق. تُعرض لك المعايير المطابقة مع كل ترشيح.' },
  { id: 5, category: 'entrepreneur', question: 'كم يستغرق تقييم مشروعي؟', answer: 'الفرز الأولي خلال 5 أيام عمل، والتقييم المستقل خلال 10 أيام عمل بعد اكتمال الملف.' },
  { id: 6, category: 'entrepreneur', question: 'من يرى بياناتي المالية؟', answer: 'فريق التقييم فقط. يرى المستثمرون مبلغ التمويل كنطاق، ولا يصلون إلى المستندات إلا بموافقتك وبعد قبول اتفاقية عدم الإفصاح.' },
  { id: 7, category: 'entrepreneur', question: 'هل يمكنني سحب مشروعي؟', answer: 'نعم في أي وقت قبل مرحلة الربط، ويمكنك إعادة تقديمه لاحقًا.' },
  { id: 8, category: 'general', question: 'هل المجلس طرف في الاتفاقيات؟', answer: 'لا، إلا إذا نُص على خلاف ذلك. الاتفاق يُبرم مباشرة بين المستثمر وصاحب المشروع.' },
]

export const content: ContentItem[] = [
  { id: 1, kind: 'pages', slug: 'about', title_ar: 'عن المجلس', title_en: 'About', is_published: true, published_at: daysAgo(30) },
  { id: 2, kind: 'pages', slug: 'privacy', title_ar: 'سياسة الخصوصية', title_en: 'Privacy', is_published: true, published_at: daysAgo(30) },
  { id: 3, kind: 'pages', slug: 'terms', title_ar: 'الشروط والأحكام', title_en: 'Terms', is_published: true, published_at: daysAgo(30) },
  { id: 4, kind: 'pages', slug: 'disclaimer', title_ar: 'إخلاء المسؤولية', title_en: 'Disclaimer', is_published: false, published_at: null },
  { id: 5, kind: 'news', slug: 'launch-founding', title_ar: `إطلاق المرحلة التأسيسية للمنصة ${DEMO}`, title_en: 'Founding phase launched', body_ar: 'أعلن المجلس إطلاق المرحلة التأسيسية مع 8 فرص أولية و3 مستثمرين مؤسسين.', is_published: true, published_at: daysAgo(10) },
  { id: 6, kind: 'news', slug: 'first-connection', title_ar: `أول ربط ناجح على المنصة ${DEMO}`, title_en: 'First successful connection', body_ar: 'تم ربط مشروع رفق بمكتب عائلي بعد موافقة مزدوجة.', is_published: true, published_at: daysAgo(5) },
  { id: 7, kind: 'events', slug: 'impact-measurement-workshop', title_ar: `ورشة قياس الأثر للمشاريع ${DEMO}`, title_en: 'Impact measurement workshop', body_ar: 'ورشة عملية لأصحاب المشاريع حول بناء مؤشرات الأثر.', is_published: true, published_at: daysAgo(3), starts_at: daysAhead(14) },
  { id: 8, kind: 'faqs', slug: 'faq-1', title_ar: 'هل تُجري المنصة عمليات مالية؟', is_published: true, published_at: daysAgo(30) },
]
let contentSeq = 100
export const nextContentId = () => contentSeq++

export const contactMessages: ContactMessage[] = [
  { id: 1, category: 'investor', name: `محمد العمري ${DEMO}`, email: 'm@example.com', phone: '+966500000099', message: 'أرغب بمعرفة متطلبات العضوية.', reference_no: 'C-2026-0001', status: 'closed', created_at: daysAgo(4) },
  { id: 2, category: 'partnership', name: `مؤسسة خير ${DEMO}`, email: 'p@example.com', message: 'نرغب بشراكة لتمويل مشاريع التعليم.', reference_no: 'C-2026-0002', status: 'new', created_at: daysAgo(1) },
]
let contactSeq = 3
export const nextContactRef = () => `C-2026-${String(contactSeq++).padStart(4, '0')}`

// ---------------------------------------------------------------- audit
export const auditLogs: AuditLog[] = [
  { uuid: 'al-1', user: { name: admin.name }, action: 'opportunity.transition', auditable_type: 'opportunity', auditable_id: 'A-2026-0007', before: { status: 'under_review' }, after: { status: 'eligible' }, ip: '10.0.0.5', request_id: 'req-1', created_at: daysAgo(14) },
  { uuid: 'al-2', user: { name: investor2.name }, action: 'document.download', auditable_type: 'document', auditable_id: 'doc-010', ip: '10.0.0.9', request_id: 'req-2', created_at: daysAgo(12) },
  { uuid: 'al-3', user: { name: matching.name }, action: 'nomination.sent', auditable_type: 'opportunity', auditable_id: 'A-2026-0007', after: { investor: investor1.uuid }, ip: '10.0.0.5', request_id: 'req-3', created_at: daysAgo(12) },
  { uuid: 'al-4', user: null, action: 'sla.reminder', auditable_type: 'opportunity', auditable_id: 'A-2026-0012', ip: null as unknown as string, request_id: 'sched-1', created_at: daysAgo(1) },
  { uuid: 'al-5', user: { name: users[8].name }, action: 'membership.transition', auditable_type: 'membership', auditable_id: 4, before: { status: 'under_review' }, after: { status: 'needs_completion' }, ip: '10.0.0.7', request_id: 'req-5', created_at: daysAgo(2) },
]
let auditSeq = 100
export const audit = (user: MockUser | null, action: string, type: string, id: string | number, before?: unknown, after?: unknown) => {
  auditLogs.unshift({
    uuid: `al-${auditSeq++}`,
    user: user ? { name: user.name } : null,
    action,
    auditable_type: type,
    auditable_id: id,
    before: (before as Record<string, unknown>) ?? null,
    after: (after as Record<string, unknown>) ?? null,
    ip: '127.0.0.1',
    request_id: `mock-${auditSeq}`,
    created_at: new Date().toISOString(),
  })
}

// ---------------------------------------------------------------- sessions
export const sessions = new Map<string, number>() // token -> user id
export const pendingTwoFactor = new Map<string, number>()

// ---------------------------------------------------------------- helpers
export const investorUsers = () => users.filter((u) => u.type.startsWith('investor'))

export const matchCriteria = (inv: MockUser, opp: OpportunityRow): Candidate['matched_criteria'] => {
  const p = inv.profile ?? emptyProfile()
  const rangeMatch = (() => {
    if (!p.investment_range_id || !opp.funding_amount) return false
    const r = txById('investment_ranges', p.investment_range_id)?.key
    const a = opp.funding_amount
    return (
      (r === 'lt_250k' && a < 250_000) ||
      (r === '250k_1m' && a >= 250_000 && a < 1_000_000) ||
      (r === '1m_3m' && a >= 1_000_000 && a < 3_000_000) ||
      (r === '3m_10m' && a >= 3_000_000 && a < 10_000_000) ||
      (r === 'gt_10m' && a >= 10_000_000)
    )
  })()
  return {
    sector: p.sectors.includes(opp.sector_id ?? -1),
    stage: !!opp.stage && p.stages.includes(opp.stage),
    region: opp.region_ids.some((r) => p.regions.includes(r)),
    type: opp.investment_type_keys.some((k) => p.investment_types.includes(k)),
    range: rangeMatch,
  }
}

export const fundingBand = (amount: number | null): string | null => {
  if (amount == null) return null
  if (amount < 250_000) return tx('investment_ranges', 'lt_250k').label_ar
  if (amount < 1_000_000) return tx('investment_ranges', '250k_1m').label_ar
  if (amount < 3_000_000) return tx('investment_ranges', '1m_3m').label_ar
  if (amount < 10_000_000) return tx('investment_ranges', '3m_10m').label_ar
  return tx('investment_ranges', 'gt_10m').label_ar
}

export const profileCompletion = (p: InvestorProfileRow | undefined): { percent: number; missing: string[] } => {
  if (!p) return { percent: 0, missing: ['basics'] }
  const checks: [string, boolean][] = [
    ['city', !!p.city],
    ['job_title', !!p.job_title],
    ['sectors', p.sectors.length > 0],
    ['stages', p.stages.length > 0],
    ['investment_types', p.investment_types.length > 0],
    ['regions', p.regions.length > 0],
    ['investment_range', !!p.investment_range_id],
    ['experience_desc', !!p.experience_desc],
    ['prior_investments_count', p.prior_investments_count != null],
    ['risk_appetite', !!p.risk_appetite],
    ['conflicts_disclosure', !!p.conflicts_disclosure],
    ['commit_meetings', p.commit_meetings],
  ]
  const done = checks.filter((c) => c[1]).length
  return { percent: Math.round((done / checks.length) * 100), missing: checks.filter((c) => !c[1]).map((c) => c[0]) }
}

export const makeEmptyProfile = emptyProfile
