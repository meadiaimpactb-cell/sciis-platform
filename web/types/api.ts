/**
 * API resource types — SPEC §4 / §5 / §6. JSON keys are snake_case (API convention);
 * the client keeps them as-is so that resources can be passed straight to the UI.
 */
import type {
  AccessLevel,
  AssignmentStatus,
  ContactCategory,
  DevTeamStructure,
  DocumentKind,
  DocumentStatus,
  EvaluationAxis,
  EvaluationResult,
  ExecutionStatus,
  FoundersCommitment,
  FoundersKnownYears,
  IndicatorFrequency,
  InfoRequestStatus,
  InstitutionType,
  InterestStatus,
  InvestmentType,
  LegalForm,
  MeetingStatus,
  MembershipStatus,
  OpportunityStatus,
  ProductStage,
  ProjectStage,
  ReportKey,
  RevenueState,
  RiskAppetite,
  Sensitivity,
  StaffRole,
  SupportNeed,
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
  /** `supported_locales` from the Laravel API (['ar'] unless the `english` feature is on) */
  supported_locales: string[]
  legal_versions: { privacy_policy: string; terms: string; nda: string }
  max_nominations_per_opportunity?: number
  /** Local-dev only (SCIIS_VERIFICATION_OPTIONAL): the OTP screen shows a temporary "skip". */
  verification_optional?: boolean
  phone_verification?: boolean
  /** ACC-07: portal idle sign-out in minutes (0 = off). */
  idle_timeout_minutes?: number
  /** CMS-04: analytics ids, loaded only after cookie consent. */
  analytics?: { ga4: string | null }
  documents?: { max_size_mb: number; allowed_mimes: string[] }
  membership?: { fee_sar: number }
  featured_public?: boolean
  platform: {
    name_ar: string
    name_en: string
    short_name: string
    official_email: string
    official_phone: string
    whatsapp?: string | null
    /** Per-audience inboxes (null = not set → only the official e-mail is shown). */
    email_investors?: string | null
    email_entrepreneurs?: string | null
    /** Official social accounts (null = not set → hidden). */
    social?: Partial<Record<'x' | 'linkedin' | 'instagram' | 'youtube', string | null>>
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

/** GET /me (UserResource + session flags). Verification is exposed both as booleans and ISO timestamps. */
export interface Me {
  uuid: string
  id?: number
  type: UserType
  type_label?: string
  name: string
  email: string
  email_verified?: boolean
  email_verified_at?: string | null
  phone: string
  phone_verified?: boolean
  phone_verified_at?: string | null
  locale: 'ar' | 'en'
  two_factor_enabled: boolean
  /** false while a 2FA challenge is still pending for this session (login step 2) */
  two_factor_passed?: boolean
  unread_notifications?: number
  /** Profile picture (WebP on the API public disk) or null → initials avatar */
  avatar_url?: string | null
  roles?: StaffRole[]
  permissions?: string[]
  status: 'active' | 'suspended'
  organization?: Organization | null
  /** ACC-08 — organisation accounts: representative or colleague (read-only unless `can_act`) */
  organization_access?: { is_representative: boolean; can_act: boolean } | null
  membership?: Membership | null
  completion_percent?: number
  preferred_contact?: 'email' | 'phone' | 'whatsapp' | null
  last_login_at?: string | null
  created_at?: string
  /** UX-05 / BRD-04 — calendar + portal theme (self only) */
  preferences?: import('./platform').DisplayPreferences
  /** ACC-09 — national identity verified through Nafath */
  national_id_verified_at?: string | null
}

/** MembershipResource (staff-only keys are optional). `completion_note` is what the investor sees; `decision_note` is internal. */
export interface Membership {
  id: number
  status: MembershipStatus
  status_label?: string
  submitted_at: string | null
  reviewed_at?: string | null
  approved_at?: string | null
  terms_accepted_at?: string | null
  suspended_reason?: string | null
  completion_note?: string | null
  decision_note?: string | null
  /** staff only — ADM-06 link to /app/admin/users/{id} */
  user_id?: number
  user?: Pick<Me, 'uuid' | 'name' | 'email' | 'type' | 'phone'> & { organization?: Organization | null }
  /** nested (Laravel) or flat (mock) — normalise with `useInvestorProfile().fromApi()` */
  profile?: InvestorProfile | InvestorProfileApi
  missing_items?: string[]
  reviewed_by?: string | null
  approved_by?: string | null
  allowed_transitions?: MembershipStatus[]
  /** FRM-01 — staff only: answers to the membership review questions */
  extra_answers?: import('./platform').ExtraAnswers | null
}

/**
 * Taxonomy term as the Laravel resources embed it (`{id,key,label}` — `label` already localized; the opportunity
 * resources also add both labels). Single shared declaration — used by profiles, nominations and opportunity payloads.
 */
export interface TermRef {
  id: number
  key: string
  label?: string
  group?: TaxonomyGroup | string
  label_ar?: string
  label_en?: string
  is_active?: boolean
}

/** GET/PUT /investor/profile as returned by the Laravel API (sectioned). */
export interface InvestorProfileApi {
  basics: {
    country?: string | null
    city?: string | null
    employer?: string | null
    job_title?: string | null
    linkedin_url?: string | null
    preferred_contact?: 'email' | 'phone' | 'whatsapp' | null
    how_heard?: TermRef | null
  }
  interests: {
    sectors: TermRef[]
    stages: TermRef[]
    investment_types: TermRef[]
    regions: TermRef[]
    causes: TermRef[]
    risk_appetite?: RiskAppetite | null
    risk_appetite_label?: string | null
    investment_range?: TermRef | null
    annual_investment_band?: TermRef | null
  }
  experience: {
    experience_years?: number | null
    experience_desc?: string | null
    prior_investments_count?: number | null
    prior_investments_desc?: string | null
    has_social_investments?: boolean | null
    social_investments_desc?: string | null
    mentoring_available?: boolean | null
    mentoring_areas?: string | null
    /** INV-01 — institutional investors only (self / staff) */
    impact_policy?: string | null
    cv_document_uuid?: string | null
  }
  declarations: {
    accredited_declaration?: unknown
    conflicts_disclosure?: string | null
    commit_meetings?: boolean | null
    /** INV-01 — data-accuracy declaration consent (self / staff) */
    data_accuracy_declared_at?: string | null
  }
  /** INV-01 — membership file, every version (owner + membership reviewers only) */
  documents?: DocumentResource[]
  completion_percent: number
  missing_items?: string[]
  /** INV-02 — "آخر تحديث" */
  updated_at?: string | null
  /** FRM-01 — additional questions (self / staff) */
  extra_answers?: import('./platform').ExtraAnswers | null
}

/** PUT /investor/profile payload (UpdateProfileRequest) */
export interface InvestorProfilePayload {
  basics?: Partial<Omit<InvestorProfileApi['basics'], 'how_heard'>> & { how_heard_id?: number | null }
  interests?: {
    sector_ids?: number[]
    stage_ids?: number[]
    investment_type_ids?: number[]
    region_ids?: number[]
    cause_ids?: number[]
    risk_appetite?: RiskAppetite | null
    investment_range_id?: number | null
    annual_investment_band_id?: number | null
  }
  experience?: Partial<Omit<InvestorProfileApi['experience'], 'cv_document_uuid'>>
  declarations?: Partial<Omit<InvestorProfileApi['declarations'], 'data_accuracy_declared_at'>>
  /** FRM-01 — `{question_id: value}` against the active schema */
  extra_answers?: import('./platform').AnswerMap
}

/** Flat form model used by the web pages (mock API shape). */
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
  /** INV-01 */
  impact_policy?: string | null
  cv_document_uuid?: string | null
  data_accuracy_declared_at?: string | null
  documents?: DocumentResource[]
  /** INV-02 */
  updated_at?: string | null
  /** FRM-01 — additional questions */
  extra_answers?: import('./platform').ExtraAnswers | null
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
  /** IMP-03 — IRIS+ catalogue code (PI1234, OI4060 …) */
  iris_code?: string | null
  sdg_id?: number | null
  is_primary: boolean
  is_disclosed: boolean
  latest_value?: number | null
  frequency_label?: string
  sdg?: TermLike | null
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
  uploaded_at?: string
  kind_label?: string
  status_label?: string
  downloadable?: boolean
  sha256?: string
  scan_result?: 'clean' | 'infected' | 'pending'
  /** DOC-03 — staff / owner views only; a staff uploader shows as "SCIIS team" to non-staff */
  previous_version_uuid?: string | null
  uploaded_by?: { name: string; is_staff: boolean } | null
}

/** MSG-02 — GET /opportunities/{uuid}/nda: the NDA in force (CMS text + server version) and this investor's state. */
export interface NdaText {
  version: string
  locale: 'ar' | 'en'
  title: string
  body: string
  text_hash: string
  pending_legal: boolean
  updated_at: string | null
  can_accept: boolean
  accepted_at: string | null
  accepted_version: string | null
}

/** Loose taxonomy term: a full `TaxonomyTerm` (mock) or the Laravel `{id, key, label, label_ar, label_en}` (`FormatsOpportunity::term()`). */
export type TermLike = {
  id: number
  key: string
  group?: TaxonomyGroup | string
  label?: string
  label_ar?: string
  label_en?: string
  is_active?: boolean
}
export type FundingDisplay = { mode: 'band' | 'exact' | 'hidden'; band?: string | null; amount?: number | null; currency?: string }

export interface OpportunityCard {
  uuid: string
  reference_no?: string | null
  name: string
  organization_name?: string | null
  sector: TaxonomyTerm | TermLike | null
  stage: ProjectStage
  stage_label?: string
  status: OpportunityStatus
  status_label?: string
  regions: (TaxonomyTerm | TermLike)[]
  /** enum keys (mock) or taxonomy terms (Laravel) — `useEnumLabel().label('investmentType', x)` accepts both */
  investment_types: (InvestmentType | TermLike)[]
  investment_type_keys?: InvestmentType[]
  founded_year?: number | null
  teaser_summary: string
  teaser_highlights?: string[]
  teaser?: { summary: string; highlights: string[]; version: number } | null
  short_description?: string | null
  beneficiaries_target: number | null
  beneficiary_groups: (TaxonomyTerm | TermLike)[]
  /** funding shown as band or exact depending on settings.funding_display (older API builds sent the mode string) */
  funding_display: FundingDisplay | 'band' | 'exact' | 'hidden'
  funding_display_mode?: 'band' | 'exact' | 'hidden'
  funding_band?: TermLike | null
  funding_amount?: number | null
  impact_score?: number | null
  is_saved?: boolean
  is_nominated?: boolean
  published_at?: string | null
  is_demo?: boolean
}

/** One founder row (team section, confidential "س"); max 6 per opportunity. */
export interface OpportunityFounder {
  name: string
  role: string | null
  linkedin_url: string | null
  is_full_time: boolean
}

/** Team & founders — confidential: owner, staff/evaluators and granted investors only. */
export interface OpportunityTeam {
  founders_count: number | null
  team_size: number | null
  founders_commitment: FoundersCommitment | null
  founders_commitment_label?: string | null
  founders_commitment_note: string | null
  prior_startup_experience: boolean | null
  prior_startup_experience_desc: string | null
  industry_experience: boolean | null
  industry_experience_desc: string | null
  founders_known_years: FoundersKnownYears | null
  founders_known_years_label?: string | null
  has_advisory_board: boolean | null
  advisory_board_desc: string | null
  dev_team_structure: DevTeamStructure | null
  dev_team_structure_label?: string | null
  founders_agreement_signed: boolean | null
  founders: OpportunityFounder[]
}

/** Public market/product subset — what investors see (card excluded). */
export interface OpportunityMarketPublic {
  product_stage: ProductStage | null
  product_stage_label?: string | null
  target_customers: string | null
  competitive_advantage: string | null
  traction_desc: string | null
}

/** Market & product — full answers (owner / staff). */
export interface OpportunityMarket extends OpportunityMarketPublic {
  market_size_desc: string | null
  competitors: string | null
  go_to_market: string | null
  technology_desc: string | null
  scalability_desc: string | null
}

/** Motivation & commitment — internal "د": owner + council only. */
export interface OpportunityMotivation {
  why_now: string | null
  support_needed: SupportNeed[]
  support_needed_labels?: (string | null)[]
  program_expectations: string | null
  how_heard_id: number | null
  how_heard?: TermLike | TaxonomyTerm | null
}

export interface OpportunityDetail extends OpportunityCard, Partial<OpportunityMarketPublic> {
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
  documents: { locked: boolean; items: DocumentResource[]; reason?: string }
  /** sensitive_personal — only when interest = connected */
  owner_contact?: { name: string; email: string; phone: string; preferred_contact?: string | null } | null
  my_interest?: Partial<Interest> & { status: InterestStatus; owner_decision: 'approved' | 'declined' | null; nda_accepted_at?: string | null; next_step?: string | null } | null
  my_nomination?: Partial<Nomination> & { match_score: number } | null
  /** Laravel also nests the same data; the flat keys above are the contract. */
  overview?: Record<string, unknown>
  impact?: Record<string, unknown>
  /** public market/product subset (also flat) */
  market?: OpportunityMarketPublic | null
  /** confidential — null before the owner's grant, the full team block after */
  team?: OpportunityTeam | null
  indicators?: ImpactIndicator[]
  nda_required?: boolean
}

/**
 * Entrepreneur-side full record (owner view / admin). The team / market / motivation answers are exposed flat
 * (Partial — older mock rows may lack them) and nested under `team` / `market` / `motivation`.
 */
export interface OpportunityFull extends Partial<OpportunityTeam>, Partial<OpportunityMarket>, Partial<OpportunityMotivation> {
  uuid: string
  reference_no: string | null
  status: OpportunityStatus
  is_listed: boolean
  name: string
  short_description: string
  /** OPP-11 — optional English card fields */
  name_en?: string | null
  short_description_en?: string | null
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
  /** EVL-03 — latest evaluation decision as the owner sees it */
  evaluation_decision?: EvaluationDecisionOwner | null
  execution_status?: ExecutionStatus | null
  created_at: string
  updated_at: string
  /** ENT-05: web form steps fully locked right now (basics | organization | team | project | impact | market | financials | documents | motivation | disclosures) */
  locked_sections?: string[]
  /** ENT-05: field keys (and `document:<kind>`) reopened by the open completion requests */
  reopened_fields?: string[]
  /** FRM-01 — additional questions of the project form (owner + staff) */
  extra_answers?: import('./platform').ExtraAnswers | null
  owner?: { id?: number; uuid: string; name: string; email: string; phone: string; type: UserType }
  owner_name?: string | null
  assigned_reviewer?: { id: number; name: string } | null
  assignee?: { id: number; name: string } | null
  is_overdue?: boolean
  overdue?: OverdueKind | null
  sla_due_at?: string | null
  /** negative when late */
  sla_days_left?: number | null
  teaser?: Teaser | null
  investment_type_ids?: number[]
  funding_range_id?: number | null
  sector?: TermLike | TaxonomyTerm | null
  regions?: (TermLike | TaxonomyTerm)[]
  investment_types?: (InvestmentType | TermLike)[]
  timeline?: StatusTransition[]
  completion_requests?: CompletionRequest[]
  required_now?: { id: number; items: CompletionRequest['items']; due_at: string | null; created_at?: string }[]
  nominations?: Nomination[]
  internal_notes?: InternalNote[]
  allowed_transitions?: OpportunityStatus[]
  /** Laravel also nests the same fields; the flat keys above are the contract. */
  overview?: Record<string, unknown>
  impact?: Record<string, unknown>
  financials?: Record<string, unknown>
  disclosures?: Record<string, unknown>
  team?: OpportunityTeam
  market?: OpportunityMarket
  motivation?: OpportunityMotivation
  completion_percent?: number
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
  /** Laravel legacy keys (same data) */
  complete?: boolean
  percent?: number
  missing?: { key: string; section: string; label: string }[]
  required_now?: { id: number; items: CompletionRequest['items']; due_at: string | null }[]
}

export interface StatusTransition {
  id: number
  from_status: OpportunityStatus | null
  to_status: OpportunityStatus
  to_label?: string
  actor: { id?: number | null; name: string } | null
  actor_name?: string | null
  reason?: string | null
  created_at: string
  /** Laravel legacy aliases */
  from?: OpportunityStatus | null
  to?: OpportunityStatus
  at?: string
}

export interface InternalNote {
  id: number
  author: { name: string }
  body: string
  created_at: string
}

export interface CompletionRequest {
  id: number
  due_at: string | null
  items: { field?: string | null; document_kind?: DocumentKind | null; note?: string | null; done?: boolean }[]
  note?: string | null
  resolved_at: string | null
  created_at?: string
  requested_by?: { id?: number | null; name: string } | null
}

// ---------- matching ----------
export type MatchCriterionKey = 'sector' | 'stage' | 'region' | 'investment_type' | 'funding_range'
/** Short keys the admin UI shows as chips (`type` = investment_type, `range` = funding_range). */
export type MatchFlagKey = 'sector' | 'stage' | 'region' | 'type' | 'range'
/** Laravel: `[{key,label}]` (long keys); mock: boolean record with the short keys. */
export type MatchedCriteria = { key: MatchCriterionKey | string; label?: string }[] | Partial<Record<MatchFlagKey, boolean>>

export interface Nomination {
  id: number
  opportunity: OpportunityCard
  investor?: { id?: number; uuid: string; name: string; type: UserType }
  matched_criteria: MatchedCriteria
  /** Laravel staff payload: boolean map keyed by the short names */
  matched?: Record<MatchFlagKey, boolean>
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
  investor?: { id?: number; uuid: string; name: string; type: UserType; email?: string }
  /** staff only — valid next states (InterestStatus state machine) */
  allowed_transitions?: InterestStatus[]
  status: InterestStatus
  owner_decision: 'approved' | 'declined' | null
  owner_decided_at?: string | null
  note?: string | null
  closed_reason?: string | null
  closed_at?: string | null
  created_at: string
  next_step?: string | null
  nda_accepted_at?: string | null
  /** ENT-06 — owner approved the full file and has not revoked it (NDA may still be pending) */
  access_active?: boolean
  access_revoked_at?: string | null
  /** owner only — the approved access may be revoked now */
  can_revoke?: boolean
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
  /** §6.3 — closed (staff: any open request; investor: their answered one) */
  closed_at?: string | null
  can_close?: boolean
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
  participants: { user_id?: number | null; name: string; role: 'investor' | 'owner' | 'council'; attended?: boolean | null }[]
  /** MTG-02 — `is_mine`: assigned to the viewer; `can_toggle`: the viewer may mark it done / undone */
  actions?: { id?: number; description: string; owner: string | null; owner_user_id?: number | null; due_at: string | null; done_at: string | null; is_mine?: boolean; can_toggle?: boolean }[]
  organizer?: { name: string } | null
  interest_id?: number | null
}

/** MTG-02 — a meeting follow-up action in a dashboard task list (PATCH /meeting-actions/{id} {done}). */
export interface MeetingTask {
  id: number
  description: string
  owner: string | null
  due_at: string | null
  done_at: string | null
  is_mine: boolean
  meeting: { id: number; title: string; starts_at: string | null } | null
  opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'> | null
}

// ---------------------------------------------------------------- Agreements (AGR-01..05)
type AgrStatus = import('./enums').AgreementStatus
type AgrParty = import('./enums').AgreementParty
export type AgreementAction = 'edit' | 'submit_review' | 'approve' | 'request_changes' | 'send_for_signature' | 'upload_signed' | 'archive' | 'cancel'
export interface AgreementPerson { id: number; name: string }
export interface AgreementVersionItem {
  id: number
  version: number
  body: string
  body_en: string | null
  /** SHA-256 of the exact text signed (AGR-05) */
  text_hash: string
  is_approved: boolean
  approved_at: string | null
  created_at: string | null
  /** staff only */
  change_note?: string | null
  placeholders?: Record<string, string>
  proposed_by?: AgreementPerson | null
  review?: { decision: 'approved' | 'changes_requested'; note: string | null; reviewer: string | null; created_at: string | null } | null
  /** party view — the version currently shown */
  is_shown?: boolean
}
export interface AgreementSignatureItem {
  id: number
  party: AgrParty
  version: number | null
  signer_name: string
  signed_at: string | null
  provider: string
  text_hash: string
  /** evidence — staff, or the signer's own signature */
  ip?: string | null
  user_agent?: string | null
  consent_text?: string
  locale?: string
  provider_reference?: string | null
  signer_email?: string | null
}
export type AgreementPartiesStatus = Record<AgrParty, { signed: boolean; signed_at: string | null; signer_name: string | null }>
export interface AgreementSignedDocument { uuid: string; title: string; size_bytes: number; sha256: string; downloadable: boolean; uploaded_at: string | null }
export interface AgreementListItem {
  uuid: string
  number: string
  title: string | null
  status: AgrStatus
  opportunity: { uuid: string; name: string; reference_no: string | null; status: OpportunityStatus } | null
  owner: AgreementPerson | null
  investor: AgreementPerson | null
  current_version: { version: number; created_at: string | null } | null
  template?: { key: string; title: string; version: number } | null
  signature_method: 'electronic' | 'wet' | null
  sent_for_signature_at: string | null
  signed_at: string | null
  archived_at: string | null
  cancelled_at: string | null
  created_at: string | null
  updated_at: string | null
  /** party list */
  my_party?: AgrParty
  awaiting_my_signature?: boolean
}
export interface AgreementAuditEntry { id: number; action: string; user: { name: string } | null; after: Record<string, unknown>; created_at: string | null }
export interface AgreementStaff extends Omit<AgreementListItem, 'template'> {
  interest: { id: number; status: InterestStatus } | null
  template: { id: number; key: string; title: string; version: number } | null
  versions: AgreementVersionItem[]
  reviews: { id: number; version: number; decision: 'approved' | 'changes_requested'; note: string | null; reviewer: AgreementPerson | null; created_at: string | null }[]
  signatures: AgreementSignatureItem[]
  parties_status: AgreementPartiesStatus
  signature_provider: string | null
  signed_document: AgreementSignedDocument | null
  review_requested_at: string | null
  approved_at: string | null
  approved_by: AgreementPerson | null
  cancel_reason: string | null
  created_by: AgreementPerson | null
  allowed_actions: AgreementAction[]
  audit: AgreementAuditEntry[]
}
export interface AgreementPartyFile extends Omit<AgreementListItem, 'my_party'> {
  my_party: AgrParty | null
  version: AgreementVersionItem | null
  versions: AgreementVersionItem[]
  signatures: AgreementSignatureItem[]
  parties_status: AgreementPartiesStatus
  can_sign: boolean
  consent_text: string | null
  signed_document: AgreementSignedDocument | null
  redirect_url?: string | null
}
export interface AgreementDiffLine { type: 'same' | 'added' | 'removed'; text: string; old: number | null; new: number | null }
export interface AgreementDiffPart { lines: AgreementDiffLine[]; added: number; removed: number }
export interface AgreementCompare {
  from: { version: number; created_at: string | null; text_hash: string }
  to: { version: number; created_at: string | null; text_hash: string }
  body: AgreementDiffPart
  body_en: AgreementDiffPart
  added: number
  removed: number
}
export interface AgreementConnection {
  interest_id: number
  status: InterestStatus
  opportunity: { uuid: string; name: string; reference_no: string | null; status: OpportunityStatus }
  owner: AgreementPerson | null
  investor: AgreementPerson | null
}
export interface AgreementTemplateItem {
  id: number
  key: string
  version: number
  title: string
  title_en: string | null
  body: string
  body_en: string | null
  description: string | null
  change_note: string | null
  is_active: boolean
  legal_approved_at: string | null
  placeholders: string[]
  created_by: AgreementPerson | null
  created_at: string | null
  updated_at: string | null
}
export interface AgreementTemplateFamily {
  key: string
  latest: AgreementTemplateItem
  active: AgreementTemplateItem | null
  versions: { id: number; version: number; title: string; is_active: boolean; change_note: string | null; created_by: string | null; created_at: string | null }[]
}

export interface Candidate {
  /** `id` is what POST …/nominations expects (`investor_user_ids[]`); the mock also matches by uuid */
  investor: { id?: number; uuid: string; name: string; type: UserType; city?: string | null }
  matched_criteria: MatchedCriteria
  matched?: Record<MatchFlagKey, boolean>
  match_score: number
  already_nominated: boolean
  coi_excluded?: boolean
  /** MAT-06 — weighted fit 0-100 (criteria weights + impact + past behaviour) and its plain-language explanation */
  weighted_score?: number
  explanation?: string
  score_breakdown?: { key: string; label: string; points: number; max: number }[]
  impact_matches?: string[]
}

/** GET /admin/opportunities/{uuid}/candidates (Laravel). The mock returns the bare Candidate[] */
export interface CandidatesResponse {
  criteria: string[]
  max_nominations: number
  current_nominations: number
  /** MAT-01 manual search echo: search / sector / region / type / investor_type */
  filters?: Record<string, string>
  candidates: Candidate[]
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
  due_at?: string | null
  evaluation?: Evaluation | null
}

/** Laravel stores per-axis `{sum, count, weighted (0..5), max}`; the mock (and the UI after normalisation) uses the 0..5 number. */
export type AxisTotal = number | { sum: number; count: number; weighted: number; max: number }

export interface Evaluation {
  id: number
  assignment_id: number
  criteria_version: number
  scores: Record<string, { score: number; note?: string | null }>
  axis_totals: Record<EvaluationAxis, AxisTotal> | null
  overall: number
  recommendation: EvaluationResult
  comment?: string | null
  submitted_at: string | null
}

export interface EvaluationPanel {
  /** full active criteria set (mock: `criteria`; Laravel: `active_criteria`, while `criteria` holds the per-row comparison) */
  criteria: EvaluationCriterion[]
  active_criteria?: EvaluationCriterion[]
  criteria_version?: number
  assignments: EvaluationAssignment[]
  diffs: { criterion_key: string; delta: number }[]
  /** EVL-05 — score difference that flags a criterion (setting `evaluation.score_diff_threshold`) */
  diff_threshold?: number
  complete?: boolean
  decision?: {
    result: EvaluationResult
    conditions?: string | null
    reasons?: string | null
    improvement_plan?: ImprovementPlanItem[]
    decided_by: { id?: number; name: string | null }
    decided_at: string
  } | null
}

/** EVL-03 — one step of the improvement plan entered with the decision (`due_at`: YYYY-MM-DD). */
export interface ImprovementPlanItem {
  text: string
  due_at: string | null
}

/** EVL-03 — the evaluation decision as the owner sees it (no decider identity). */
export interface EvaluationDecisionOwner {
  result: EvaluationResult
  result_label?: string
  conditions: string | null
  reasons: string | null
  improvement_plan: ImprovementPlanItem[]
  decided_at: string | null
}

/** ADM-03 — which SLA an overdue opportunity has missed. */
export type OverdueKind = 'review' | 'completion' | 'evaluation' | 'matching' | 'connection' | 'negotiation'

// ---------- dashboards ----------
export interface DashboardInvestor {
  completion: { percent: number; missing_items: string[] }
  completion_percent?: number
  counters: { saved: number; interests: number; info_requests: number; meetings: number; nominations_pending?: number }
  requests: {
    kind: 'interest' | 'nomination' | 'info_request'
    id: number
    opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>
    status: string
    next_step: string
    /** `interest.<status>` | `nomination.pending` | `info_request.<status>` — translated client-side when known */
    next_step_key?: string
    updated_at: string
  }[]
  suggested: (OpportunityCard & { match_score?: number; matched_criteria?: string[] })[]
  updates: { id: number | string; title: string; body: string; created_at: string; href?: string | null; read_at?: string | null }[]
  next_meeting: Meeting | null
  membership_status: MembershipStatus
  access_policy?: string
  has_connected?: boolean
  /** MTG-02 — open meeting follow-up actions assigned to the investor */
  tasks?: MeetingTask[]
}

export interface RequiredNowItem {
  id: number
  opportunity_uuid: string
  opportunity_name?: string | null
  title: string
  note?: string | null
  due_at: string | null
  action: 'complete' | 'upload' | 'decide_access' | 'answer_info' | 'approve_teaser' | 'declare' | 'meeting_action'
  kind: 'field' | 'document' | 'access_request' | 'info_request' | 'teaser' | 'meeting_action'
  /** Laravel extras */
  type?: string
  field?: string | null
  document_kind?: DocumentKind | null
  /** MTG-02 — set for `action: meeting_action` */
  meeting_action_id?: number
}

/** Row of `dashboard.opportunities[]` (Laravel sends a summary, the mock the full record). */
export type DashboardOpportunityRow = Pick<OpportunityFull, 'uuid' | 'reference_no' | 'name' | 'status' | 'submitted_at' | 'updated_at'> & {
  completion_percent: number
  status_label?: string
  sector?: string | TermLike | TaxonomyTerm | null
  overdue?: OverdueKind | null
  is_overdue?: boolean
  sla_due_at?: string | null
  sla_days_left?: number | null
  locked_sections?: string[]
  checklist_ready?: boolean
  /** EVL-03 */
  evaluation_decision?: EvaluationDecisionOwner | null
}

export interface DashboardEntrepreneur {
  opportunities: DashboardOpportunityRow[]
  required_now: RequiredNowItem[]
  access_requests: Interest[]
  documents: (DocumentResource & { opportunity_uuid: string | null })[]
  preview: OpportunityCard | null
  info_requests_open: number
  meetings_upcoming: number
  /** Laravel legacy keys */
  pending_info_requests?: number
  completion?: number
  counters?: { opportunities: number; access_requests: number; meetings: number; documents?: number }
}

/**
 * Row of the admin work queue / GET /admin/opportunities. The Laravel list returns the full staff resource
 * (OpportunityFullResource + owner_name/assignee/sla_* fields); the fields below are the ones the admin tables read.
 */
export interface QueueItem {
  uuid: string
  reference_no: string | null
  name: string
  status: OpportunityStatus
  sector: TermRef | null
  owner_name?: string | null
  /** Laravel full resource (staff): fallback for owner_name / meeting participant picker */
  owner?: { id?: number; uuid?: string; name: string; email?: string; phone?: string } | null
  assignee?: { id: number; name: string } | null
  /** Laravel full resource: same as assignee */
  assigned_reviewer?: { id: number; name: string } | null
  sla_due_at?: string | null
  sla_days_left?: number | null
  is_overdue?: boolean
  submitted_at: string | null
  updated_at: string
}

export interface AdminTask {
  id: number | string
  kind?: string
  title: string
  href: string
  due_at: string | null
}

export interface DashboardAdmin {
  today: { new: number; overdue: number; pending_memberships: number; interest_rate: number }
  pipeline: { status: OpportunityStatus; count: number; label?: string; color?: string }[]
  membership_requests: Membership[]
  queue: QueueItem[]
  evaluation_focus: { opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>; panel: EvaluationPanel } | null
  candidates_focus: { opportunity: Pick<OpportunityCard, 'uuid' | 'name' | 'reference_no'>; candidates: Candidate[]; max_nominations?: number } | null
  my_tasks: AdminTask[]
}

export interface ReportResult {
  key: ReportKey
  title: string
  generated_at: string
  kind: 'bars' | 'funnel' | 'number' | 'table'
  series: { label: string; value: number; color?: string }[]
  unit?: string | null
  note?: string
  /** table contract (Laravel) — also what the xlsx export contains */
  columns?: string[]
  rows?: Record<string, unknown>[]
  period?: { from: string | null; to: string | null }
  filters?: { sector: number | null; region: number | null; supports_sector_region: boolean }
}

export interface AuditLog {
  uuid: string
  user: { id?: number; name: string } | null
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
  is_public?: boolean
  updated_at?: string | null
  pending_legal?: boolean
}

/** GET /admin/settings (Laravel): `{ data: { settings, features } }`. The page also accepts a bare array. */
export interface SettingsResponse {
  settings: SettingItem[]
  features: Record<string, boolean>
}

export interface StaffUser {
  id: number
  uuid: string
  name: string
  email: string
  phone?: string | null
  type: UserType
  roles: StaffRole[]
  status: 'active' | 'suspended'
  two_factor_enabled: boolean
  last_login_at: string | null
}

/** Slim row returned by GET /admin/users?role=…|search=… to staff without `users.manage` (pickers). */
export interface UserPick {
  id: number
  uuid?: string
  name: string
  type?: UserType
  roles?: StaffRole[]
}

/** ACC-03 — GET /admin/roles */
export interface RoleItem {
  id: number
  name: string
  permissions: string[]
  users_count: number
  is_system: boolean
}

/** ADM-07 — GET /admin/roles/approval-matrix: one row per council decision, roles derived from live permissions. */
export interface ApprovalMatrixRow {
  key: string
  area: 'membership' | 'opportunity' | 'evaluation' | 'matching' | 'execution' | 'platform' | string
  permissions: string[]
  /** `all` = every permission required, `any` = one is enough */
  mode: 'all' | 'any'
  roles: { name: string; users_count: number }[]
  /** setting that further restricts the roles (report export → `reports.allowed_export_roles`) */
  roles_setting?: string | null
  /** hold the permission but are not in `roles_setting` */
  excluded_roles?: { name: string; users_count: number }[]
}

/** ADM-06 — merged staff timeline of an opportunity file (`timeline_events` on GET /admin/opportunities/{uuid}). */
export type TimelineEventType = 'status' | 'document_uploaded' | 'document_reviewed' | 'note' | 'completion_requested' | 'completion_resolved' | 'meeting_scheduled' | 'nomination_sent' | 'nomination_responded'
export interface TimelineEvent {
  id: string
  type: TimelineEventType
  at: string | null
  actor: { id?: number | null; name: string } | null
  data: {
    from?: OpportunityStatus | null
    to?: OpportunityStatus
    reason?: string | null
    uuid?: string | null
    title?: string | null
    kind?: string | null
    version?: number
    status?: string | null
    status_label?: string | null
    note?: string | null
    body?: string
    items?: { field?: string | null; document_kind?: string | null; note?: string | null }[]
    items_count?: number
    due_at?: string | null
    starts_at?: string | null
    investor?: { id: number; name: string } | null
    match_score?: number
    rationale?: string | null
    response?: string | null
  }
}

/** ADM-06 — GET /admin/users/{id} (staff only; linked lists / activity are empty when the viewer lacks the permission). */
export interface AdminUserFile {
  id: number
  uuid: string
  name: string
  email: string | null
  phone: string | null
  type: UserType
  type_label?: string
  status: 'active' | 'suspended'
  locale: 'ar' | 'en'
  preferred_contact?: string | null
  email_verified_at: string | null
  phone_verified_at: string | null
  two_factor_enabled: boolean
  two_factor_confirmed_at: string | null
  last_login_at: string | null
  anonymized_at: string | null
  created_at: string | null
  roles: StaffRole[]
  organization: Organization | null
  completion_percent: number | null
  membership: (Membership & { reviewed_by?: string | null; approved_by?: string | null }) | null
  membership_history: { id: number; action: string; from: MembershipStatus | null; to: MembershipStatus | null; note: string | null; actor: { id: number; name: string } | null; at: string | null }[]
  opportunities: { uuid: string; reference_no: string | null; name: string; status: OpportunityStatus; submitted_at: string | null; updated_at: string | null }[]
  interests: { id: number; opportunity: { uuid: string; reference_no: string | null; name: string } | null; status: InterestStatus; created_at: string | null; closed_at: string | null }[]
  nominations: { id: number; opportunity: { uuid: string; reference_no: string | null; name: string } | null; match_score: number; sent_at: string | null; response: 'interested' | 'declined' | null; responded_at: string | null }[]
  activity: { uuid: string; action: string; role: 'actor' | 'subject'; actor: { id: number; name: string } | null; auditable_type: string | null; auditable_id: number | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null; ip: string | null; at: string | null }[]
  can: { manage: boolean; review_membership: boolean; view_opportunities: boolean; view_matching: boolean; view_activity: boolean }
}

/** An image uploaded through POST /admin/content/media (WebP, stored on the public disk). */
export interface ContentImage {
  url: string
  path?: string | null
  alt_ar?: string | null
  alt_en?: string | null
  width?: number | null
  height?: number | null
}

/** Home hero slide (BRD-03) — stored in the `home` page meta.hero_images (max 3). */
export type HeroImage = ContentImage

/** News author block (E-E-A-T) as returned by the public API. */
export interface ContentAuthor {
  name: string
  title?: string | null
  bio?: string | null
  avatar_url?: string | null
  linkedin_url?: string | null
}

/** A document uploaded through POST /admin/content/files (knowledge resources). */
export interface ContentFile {
  url: string
  path?: string | null
  name?: string | null
  size?: number | null
  mime?: string | null
}

export interface ContentMeta {
  hero_images?: HeroImage[]
  cover?: ContentImage | null
  /** Partner / story logo. */
  logo?: ContentImage | null
  /** Knowledge resource file. */
  file?: ContentFile | null
  /** Governance bodies for the `governance` page (about page diagram). */
  bodies?: { name_ar: string; name_en?: string | null; level?: number }[]
  /** Public-page marketing copy `{ key: { ar, en } }` — see utils/pageSections.ts. */
  sections?: Record<string, { ar?: unknown; en?: unknown }>
  [key: string]: unknown
}

export interface ContentItem {
  id: number
  slug: string
  /** Localized title/body as returned by the Laravel API (the mock returns only the *_ar/*_en pairs). */
  title?: string
  body?: string | null
  title_ar: string
  title_en?: string | null
  body_ar?: string | null
  body_en?: string | null
  version?: string | null
  is_published: boolean
  published_at: string | null
  updated_at?: string | null
  sort?: number
  meta?: ContentMeta | null
  /** Set by the mock only; the Laravel API infers the type from the endpoint. */
  kind?: 'pages' | 'faqs' | 'news' | 'events' | 'stories' | 'partners' | 'resources'
  // stories / partners / resources (growth content)
  link_url?: string | null
  sector?: string | null
  sector_ar?: string | null
  sector_en?: string | null
  beneficiaries?: number | null
  impact_metric?: string | null
  impact_metric_ar?: string | null
  impact_metric_en?: string | null
  /** partners: localized name (= title) + the staff-only display approval. */
  name?: string
  approved_for_display?: boolean
  /** resources */
  audience?: 'investor' | 'entrepreneur' | 'all'
  url?: string | null
  file?: Pick<ContentFile, 'name' | 'size' | 'mime'> | null
  // events
  starts_at?: string | null
  ends_at?: string | null
  location?: string | null
  registration_url?: string | null
  // faqs
  category?: string | null
  // news (E-E-A-T)
  author?: ContentAuthor | null
  author_name?: string | null
  author_title?: string | null
  author_bio?: string | null
  author_avatar_url?: string | null
  author_linkedin_url?: string | null
}

/** GET /public/pages/{slug} */
export type PublicPage = ContentItem

/** API vocabulary (contact_messages.status): new | in_progress | closed. */
export type ContactMessageStatus = 'new' | 'in_progress' | 'closed'

export interface ContactMessage {
  id: number
  category: ContactCategory
  category_label?: string
  name: string
  email: string
  phone?: string | null
  message: string
  reference_no: string
  /** `answered` is a legacy mock value only (server/mock/db.ts); the API uses ContactMessageStatus. */
  status: ContactMessageStatus
  assigned_to?: string | null
  assigned_to_id?: number | null
  created_at: string
}

export interface PublicFaq {
  id: number
  question: string
  answer: string
  category: 'general' | 'investor' | 'entrepreneur' | 'membership'
}

/** Normalized home-page stats. The Laravel API returns the raw keys (see PublicStatsRaw); the mock returns these directly. */
export interface PublicStats {
  opportunities: number
  members: number
  sectors: number
  regions?: number
  connections: number
}

/** Raw shape of GET /public/stats from the Laravel API (PublicContentController@stats). */
export interface PublicStatsRaw {
  approved_investors: number
  registered_entrepreneurs: number
  opportunities_submitted: number
  opportunities_eligible: number
  connections: number
  sectors_covered: number
}

// ───────────── Follow-up & impact (PRJ-01..03, IMP-01/02, ENT-10, INV-06) — FollowUpPresenter ─────────────
import type { IndicatorValueStatus, InvestorUpdateStatus, MilestonePlanStatus, MilestoneStatus, ProgressReportStatus } from './enums'

/** Items waiting for a staff decision (staff / owner views). */
export interface FollowUpPending { plan: number; reports: number; indicator_values: number; updates: number; total: number }

/** List row: GET /admin/follow-up · /entrepreneur/follow-up · /investor/portfolio */
export interface FollowUpSummary {
  uuid: string
  reference_no: string | null
  name: string
  sector: { key: string; label_ar: string; label_en: string | null } | null
  status: OpportunityStatus
  status_label: string
  /** false for a connected project whose follow-up has not started (investor portfolio) */
  in_follow_up: boolean
  execution_status: ExecutionStatus | null
  execution_status_label: string | null
  status_overridden: boolean
  plan_status: MilestonePlanStatus | null
  milestones: { total: number; done: number; overdue: number; next: { id: number; title: string; due_at: string | null } | null } | null
  latest_report: { id: number; period_start: string; period_end: string; status: ProgressReportStatus; excerpt: string; submitted_at: string | null } | null
  impact: { indicators: number; verified_values: number; primary: { name: string; unit: string | null; baseline: number | null; target: number | null; latest_verified: { value: number; period_end: string } | null; progress_percent: number | null } } | null
  latest_update: { id: number; title: string | null; excerpt: string; sent_at: string | null } | null
  /** staff / owner only */
  reports_overdue?: number
  next_report_due_on?: string | null
  owner?: { id: number; name: string } | null
  pending?: FollowUpPending | null
  escalated_at?: string | null
}

export interface FollowUpMilestone {
  id: number
  title: string
  description: string | null
  deliverable: string | null
  responsible: string | null
  budget_share: number | null
  sort: number
  due_at: string | null
  status: MilestoneStatus
  status_label: string
  is_overdue: boolean
  completed_at: string | null
  evidence_note: string | null
  evidence: DocumentResource[]
}

export interface FollowUpReport {
  id: number
  period_start: string
  period_end: string
  due_on: string | null
  status: ProgressReportStatus
  status_label: string
  narrative: string | null
  achieved: string | null
  delayed: string | null
  risks: string | null
  support_needed: string | null
  spend_amount: number | null
  execution_status: ExecutionStatus | null
  milestone_updates: { milestone_id: number; status: string; note: string | null; title: string | null }[]
  submitted_at: string | null
  reviewed_at: string | null
  review_note: string | null
  is_late: boolean
  attachments: DocumentResource[]
}

export interface FollowUpIndicatorValue {
  id: number
  period_start: string
  period_end: string
  value: number
  status: IndicatorValueStatus
  status_label: string
  note: string | null
  review_note: string | null
  verified_at: string | null
  created_at: string | null
  evidence: DocumentResource | null
}

export interface FollowUpIndicator {
  id: number
  name: string
  definition: string | null
  unit: string | null
  baseline: number | null
  target: number | null
  frequency: string | null
  verification_method: string | null
  is_primary: boolean
  latest_verified: { value: number; period_end: string } | null
  progress_percent: number | null
  /** investors receive verified values only (IMP-02) */
  values: FollowUpIndicatorValue[]
}

export interface FollowUpUpdate {
  id: number
  title: string | null
  body: string
  status: InvestorUpdateStatus
  status_label: string
  metrics: { indicator_id: number; name: string; unit: string | null; value: number; period_end: string; target: number | null }[]
  sent_at: string | null
  created_at: string | null
  review_note: string | null
  attachments: DocumentResource[]
}

export interface FollowUpPeriod {
  period_start: string
  period_end: string
  due_on: string
  state: 'current' | 'due' | 'overdue' | ProgressReportStatus
  report_id: number | null
  days_overdue: number
}

export interface FollowUpHistoryEntry { id: number; action: string; user: string | null; after: Record<string, unknown> | null; created_at: string | null }

/** Detail: GET /admin/follow-up/{uuid} · /entrepreneur/follow-up/{uuid} · /investor/portfolio/{uuid} */
export interface FollowUpDetail extends FollowUpSummary {
  follow_up: {
    plan_status: MilestonePlanStatus
    plan_status_label: string
    plan_version: number
    plan_submitted_at?: string
    plan_reviewed_at?: string
    plan_review_note?: string
    computed_status?: ExecutionStatus
    status_override?: ExecutionStatus
    override_reason?: string
    override_at?: string
    override_by?: string
    remediation_plan?: string
    remediation_updated_at?: string
    escalated_at?: string
    execution_started_at?: string
    report_frequency?: string
    delay_days?: number
    stalled_after_days?: number
  } | null
  milestones_list: FollowUpMilestone[]
  budget_share_total: number | null
  reports: FollowUpReport[]
  indicators: FollowUpIndicator[]
  updates: FollowUpUpdate[]
  /** staff / owner only */
  periods?: FollowUpPeriod[]
  connected_investors?: number
  updates_require_review?: boolean
  /** staff only — decision trail (AUD-01) */
  history?: FollowUpHistoryEntry[]
}

export interface FollowUpStats { total: number; on_track: number; delayed: number; stalled: number; overdue_reports: number; pending_verifications: number }
