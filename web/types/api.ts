/**
 * API resource types — SPEC §4 / §5 / §6. JSON keys are snake_case (API convention);
 * the client keeps them as-is so that resources can be passed straight to the UI.
 */
import type {
  AccessLevel,
  AssignmentStatus,
  ContactCategory,
  DocumentKind,
  DocumentStatus,
  EvaluationAxis,
  EvaluationResult,
  ExecutionStatus,
  IndicatorFrequency,
  InfoRequestStatus,
  InstitutionType,
  InterestStatus,
  InvestmentType,
  LegalForm,
  MeetingStatus,
  MembershipStatus,
  OpportunityStatus,
  ProjectStage,
  ReportKey,
  RevenueState,
  RiskAppetite,
  Sensitivity,
  StaffRole,
  TaxonomyGroup,
  UserType,
} from './enums'

// ---------- generic ----------
export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
  links?: { next?: string | null; prev?: string | null }
}

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
  code?: string
  status?: number
}

export interface TaxonomyTerm {
  id: number
  group: TaxonomyGroup
  key: string
  label_ar: string
  label_en: string
  sort: number
  is_active: boolean
  meta?: Record<string, unknown> | null
  /** present on filter endpoints */
  count?: number
}

export type Taxonomies = Record<TaxonomyGroup, TaxonomyTerm[]>

export interface PublicSettings {
  funding_display: 'band' | 'exact' | 'hidden'
  access_policy: 'nomination_only' | 'browse_all' | 'both'
  locales: string[]
  legal_versions: Record<'privacy' | 'terms' | 'disclaimer', string>
  platform: {
    name_ar: string
    name_en: string
    short_name: string
    official_email: string
    official_phone: string
    whatsapp?: string | null
  }
  features: Record<string, boolean>
}

// ---------- identity ----------
export interface Organization {
  uuid: string
  name: string
  legal_form?: LegalForm | null
  institution_type?: InstitutionType | null
  cr_number?: string | null
  unified_number?: string | null
  license_number?: string | null
  founded_on?: string | null
  employees_count?: number | null
  website?: string | null
  region?: TaxonomyTerm | null
  city?: string | null
}

export interface Me {
  uuid: string
  type: UserType
  name: string
  email: string
  email_verified_at: string | null
  phone: string
  phone_verified_at: string | null
  locale: 'ar' | 'en'
  two_factor_enabled: boolean
  roles: StaffRole[]
  permissions: string[]
  status: 'active' | 'suspended'
  organization?: Organization | null
  membership?: Membership | null
  completion_percent?: number
  preferred_contact?: 'email' | 'phone' | 'whatsapp' | null
  last_login_at?: string | null
}

export interface Membership {
  id: number
  status: MembershipStatus
  submitted_at: string | null
  reviewed_at?: string | null
  approved_at?: string | null
  terms_accepted_at?: string | null
  suspended_reason?: string | null
  decision_note?: string | null
  user?: Pick<Me, 'uuid' | 'name' | 'email' | 'type' | 'phone'> & { organization?: Organization | null }
  profile?: InvestorProfile
  missing_items?: string[]
}

export interface InvestorProfile {
  country?: string | null
  city?: string | null
  employer?: string | null
  job_title?: string | null
  linkedin_url?: string | null
  experience_years?: number | null
  experience_desc?: string | null
  prior_investments_count?: number | null
  prior_investments_desc?: string | null
  has_social_investments?: boolean
  social_investments_desc?: string | null
  risk_appetite?: RiskAppetite | null
  mentoring_available?: boolean
  mentoring_areas?: string | null
  investment_range_id?: number | null
  annual_investment_band_id?: number | null
  preferred_contact?: 'email' | 'phone' | 'whatsapp' | null
  how_heard_id?: number | null
  conflicts_disclosure?: string | null
  commit_meetings?: boolean
  sectors: number[]
  stages: string[]
  investment_types: string[]
  regions: number[]
  causes: number[]
  completion_percent: number
  missing_items: string[]
}

// ---------- opportunities ----------
export interface ImpactIndicator {
  id?: number
  name: string
  definition?: string | null
  unit: string
  baseline: number | null
  target: number | null
  data_source: string
  frequency: IndicatorFrequency
  verification_method?: string | null
  sdg_id?: number | null
  is_primary: boolean
  is_disclosed: boolean
  latest_value?: number | null
}

export interface DocumentResource {
  uuid: string
  kind: DocumentKind
  title: string
  mime?: string
  size_bytes?: number
  sensitivity: Sensitivity
  status: DocumentStatus
  reviewer_note?: string | null
  version: number
  created_at: string
  scan_result?: 'clean' | 'infected' | 'pending'
}

export interface OpportunityCard {
  uuid: string
  reference_no?: string | null
  name: string
  organization_name?: string | null
  sector: TaxonomyTerm
  stage: ProjectStage
  status: OpportunityStatus
  regions: TaxonomyTerm[]
  investment_types: InvestmentType[]
  founded_year?: number | null
  teaser_summary: string
  beneficiaries_target: number | null
  beneficiary_groups: TaxonomyTerm[]
  /** funding shown as band or exact depending on settings.funding_display */
  funding_display: { mode: 'band' | 'exact' | 'hidden'; band?: string | null; amount?: number | null }
  impact_score?: number | null
  is_saved?: boolean
  is_nominated?: boolean
  published_at?: string | null
  is_demo?: boolean
}

export interface OpportunityDetail extends OpportunityCard {
  access_level: AccessLevel
  short_description: string
  website?: string | null
  problem_statement: string
  solution: string
  expected_change: string
  beneficiaries_desc: string
  beneficiaries_current?: number | null
  revenue_model_type?: string | null
  revenue_model_desc?: string | null
  revenue_state: RevenueState
  primary_indicator?: ImpactIndicator | null
  disclosed_indicators: ImpactIndicator[]
  sdgs: TaxonomyTerm[]
  /** confidential — summary % before grant, detailed after */
  use_of_funds?: { item: string; percent: number }[] | null
  /** confidential — summary before grant, full after */
  risks_summary?: string | null
  risks_full?: {
    prior_funding?: { has: boolean; desc?: string | null }
    legal_disputes?: { has: boolean; desc?: string | null }
    license?: { required: boolean; status?: string | null }
    operational_risks?: string | null
  } | null
  documents: { locked: boolean; items: DocumentResource[] }
  /** sensitive_personal — only when interest = connected */
  owner_contact?: { name: string; email: string; phone: string } | null
  teaser_highlights?: string[]
  my_interest?: Interest | null
  my_nomination?: Nomination | null
}

/** Entrepreneur-side full record (owner view / admin) */
export interface OpportunityFull {
  uuid: string
  reference_no: string | null
  status: OpportunityStatus
  is_listed: boolean
  name: string
  short_description: string
  sector_id: number | null
  stage: ProjectStage | null
  website?: string | null
  social_links?: Record<string, string>
  region_ids: number[]
  investment_type_keys: InvestmentType[]
  beneficiary_group_ids: number[]
  sdg_ids: number[]
  organization?: Partial<Organization> & { legal_form?: LegalForm | null }
  problem_statement: string
  solution: string
  expected_change: string
  beneficiaries_desc: string
  beneficiaries_current: number | null
  beneficiaries_target: number | null
  revenue_model_type: string | null
  revenue_model_desc: string | null
  revenue_state: RevenueState | null
  last_year_revenue_band_id: number | null
  funding_amount: number | null
  use_of_funds: { item: string; percent: number }[]
  prior_funding: boolean
  prior_funding_desc: string | null
  legal_disputes: boolean
  legal_disputes_desc: string | null
  license_required: boolean
  license_status: string | null
  operational_risks: string | null
  council_relationship: boolean
  council_relationship_desc: string | null
  data_accuracy_declared_at: string | null
  teaser_publication_consent: boolean
  indicators: ImpactIndicator[]
  documents: DocumentResource[]
  submitted_at: string | null
  review_due_at?: string | null
  completion_due_at?: string | null
  evaluation_due_at?: string | null
  evaluation_result?: EvaluationResult | null
  execution_status?: ExecutionStatus | null
  created_at: string
  updated_at: string
  locked_sections?: string[]
  owner?: { uuid: string; name: string; email: string; phone: string; type: UserType }
  assigned_reviewer?: { id: number; name: string } | null
  is_overdue?: boolean
  teaser?: Teaser | null
}

export interface Teaser {
  summary_ar: string
  summary_en?: string | null
  highlights: string[]
  version: number
  approved_by_owner_at: string | null
  approved_by_council_at: string | null
}

export interface ChecklistItem {
  key: string
  label: string
  section: string
  done: boolean
  kind: 'field' | 'document'
}

export interface Checklist {
  ready: boolean
  completion_percent: number
  items: ChecklistItem[]
}

export interface StatusTransition {
  id: number
  from_status: OpportunityStatus | null
  to_status: OpportunityStatus
  actor: { name: string } | null
  reason?: string | null
  created_at: string
}

export interface InternalNote {
  id: number
  author: { name: string }
  body: string
  created_at: string
}

export interface CompletionRequest {
  id: number
  due_at: string
  items: { field?: string; document_kind?: DocumentKind; note: string; done?: boolean }[]
  resolved_at: string | null
  created_at: string
  requested_by?: { name: string }
}

// ---------- matching ----------
export interface Nomination {
  id: number
  opportunity: OpportunityCard
  investor?: { uuid: string; name: string; type: UserType }
  matched_criteria: Partial<Record<'sector' | 'stage' | 'region' | 'type' | 'range', boolean>>
  match_score: number
  rationale: string
  teaser_version: number
  sent_at: string
  response: 'interested' | 'declined' | null
  decline_reason?: TaxonomyTerm | null
  decline_note?: string | null
  responded_at: string | null
}

export interface Interest {
  id: number
  opportunity: OpportunityCard
  investor?: { uuid: string; name: string; type: UserType; email?: string }
  status: InterestStatus
  owner_decision: 'approved' | 'declined' | null
  owner_decided_at?: string | null
  note?: string | null
  closed_reason?: string | null
  closed_at?: string | null
  created_at: string
  next_step?: string | null
  nda_accepted_at?: string | null
}

export interface InfoRequest {
  id: number
  opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>
  subject: string
  body: string
  status: InfoRequestStatus
  owner_answer?: string | null
  answered_at?: string | null
  forwarded_to_owner_at?: string | null
  created_at: string
  /** staff only */
  investor?: { uuid: string; name: string } | null
  handled_by?: { name: string } | null
}

export interface Meeting {
  id: number
  opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>
  title: string
  starts_at: string
  ends_at: string
  location?: string | null
  video_url?: string | null
  status: MeetingStatus
  minutes?: string | null
  participants: { name: string; role: 'investor' | 'owner' | 'council'; attended?: boolean | null }[]
  actions?: { description: string; owner: string; due_at: string; done_at: string | null }[]
}

export interface Candidate {
  investor: { uuid: string; name: string; type: UserType; city?: string | null }
  matched_criteria: Record<'sector' | 'stage' | 'region' | 'type' | 'range', boolean>
  match_score: number
  already_nominated: boolean
  coi_excluded?: boolean
}

// ---------- evaluation ----------
export interface EvaluationCriterion {
  id: number
  axis: EvaluationAxis
  key: string
  label_ar: string
  label_en: string
  weight: number
  scale_descriptions: Record<'1' | '2' | '3' | '4' | '5', string>
  version: number
  is_active: boolean
}

export interface EvaluationAssignment {
  id: number
  evaluator: { id: number; name: string }
  status: AssignmentStatus
  coi_declared_at: string | null
  coi_has_conflict: boolean
  evaluation?: Evaluation | null
}

export interface Evaluation {
  id: number
  assignment_id: number
  criteria_version: number
  scores: Record<string, { score: number; note?: string | null }>
  axis_totals: Record<EvaluationAxis, number>
  overall: number
  recommendation: EvaluationResult
  comment?: string | null
  submitted_at: string | null
}

export interface EvaluationPanel {
  criteria: EvaluationCriterion[]
  assignments: EvaluationAssignment[]
  diffs: { criterion_key: string; delta: number }[]
  decision?: {
    result: EvaluationResult
    conditions?: string | null
    reasons?: string | null
    decided_by: { name: string }
    decided_at: string
  } | null
}

// ---------- dashboards ----------
export interface DashboardInvestor {
  completion: { percent: number; missing_items: string[] }
  counters: { saved: number; interests: number; info_requests: number; meetings: number }
  requests: {
    kind: 'interest' | 'nomination' | 'info_request'
    id: number
    opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>
    status: string
    next_step: string
    updated_at: string
  }[]
  suggested: OpportunityCard[]
  updates: { id: number; title: string; body: string; created_at: string; href?: string }[]
  next_meeting: Meeting | null
  membership_status: MembershipStatus
}

export interface RequiredNowItem {
  id: number
  opportunity_uuid: string
  title: string
  note?: string | null
  due_at: string | null
  action: 'complete' | 'upload' | 'decide_access' | 'answer_info' | 'approve_teaser' | 'declare'
  kind: 'field' | 'document' | 'access_request' | 'info_request' | 'teaser'
}

export interface DashboardEntrepreneur {
  opportunities: (OpportunityFull & { completion_percent: number })[]
  required_now: RequiredNowItem[]
  access_requests: Interest[]
  documents: (DocumentResource & { opportunity_uuid: string })[]
  preview: OpportunityCard | null
  info_requests_open: number
  meetings_upcoming: number
}

export interface QueueItem {
  uuid: string
  reference_no: string
  name: string
  status: OpportunityStatus
  sector: TaxonomyTerm
  owner_name: string
  assignee?: { id: number; name: string } | null
  sla_due_at: string | null
  sla_days_left: number | null
  is_overdue: boolean
  submitted_at: string
  updated_at: string
}

export interface DashboardAdmin {
  today: { new: number; overdue: number; pending_memberships: number; interest_rate: number }
  pipeline: { status: OpportunityStatus; count: number }[]
  membership_requests: Membership[]
  queue: QueueItem[]
  evaluation_focus: { opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>; panel: EvaluationPanel } | null
  candidates_focus: { opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>; candidates: Candidate[] } | null
  my_tasks: { id: number; title: string; href: string; due_at: string | null }[]
}

export interface ReportResult {
  key: ReportKey
  title: string
  generated_at: string
  kind: 'bars' | 'funnel' | 'number' | 'table'
  series: { label: string; value: number; color?: string }[]
  unit?: string
  note?: string
}

export interface AuditLog {
  uuid: string
  user: { name: string } | null
  action: string
  auditable_type: string
  auditable_id: string | number
  before?: Record<string, unknown> | null
  after?: Record<string, unknown> | null
  ip?: string
  request_id?: string
  created_at: string
}

export interface SettingItem {
  key: string
  value: unknown
  group: string
  description?: string | null
  pending_legal?: boolean
}

export interface StaffUser {
  id: number
  uuid: string
  name: string
  email: string
  type: UserType
  roles: StaffRole[]
  status: 'active' | 'suspended'
  two_factor_enabled: boolean
  last_login_at: string | null
}

export interface ContentItem {
  id: number
  slug: string
  title_ar: string
  title_en?: string | null
  body_ar?: string
  body_en?: string | null
  is_published: boolean
  published_at: string | null
  kind: 'pages' | 'faqs' | 'news' | 'events'
  starts_at?: string | null
  category?: string | null
}

export interface ContactMessage {
  id: number
  category: ContactCategory
  name: string
  email: string
  phone?: string | null
  message: string
  reference_no: string
  status: 'new' | 'assigned' | 'answered'
  created_at: string
}

export interface PublicFaq {
  id: number
  question: string
  answer: string
  category: 'general' | 'investor' | 'entrepreneur' | 'membership'
}

export interface PublicStats {
  opportunities: number
  members: number
  sectors: number
  regions: number
  connections: number
}
