/**
 * Enumerations — mirrors SPEC §2 exactly (string-backed, snake_case).
 * Keep in sync with api/app/Enums/*.php and mobile/lib/core/enums.dart.
 */

export const OPPORTUNITY_STATUSES = [
  'draft',
  'new',
  'under_review',
  'needs_completion',
  'not_eligible',
  'eligible',
  'connected',
  'negotiating',
  'in_execution',
  'operating',
  'withdrawn',
  'closed',
] as const
export type OpportunityStatus = (typeof OPPORTUNITY_STATUSES)[number]

/** The 9 states shown on the entrepreneur progress strip (doc §7.6) — happy path only. */
export const OPPORTUNITY_STRIP_STATUSES = [
  'draft',
  'new',
  'under_review',
  'needs_completion',
  'eligible',
  'connected',
  'negotiating',
  'in_execution',
  'operating',
] as const
export type OpportunityStripStatus = (typeof OPPORTUNITY_STRIP_STATUSES)[number]

export const MEMBERSHIP_STATUSES = [
  'registered',
  'under_review',
  'needs_completion',
  'approved',
  'suspended',
  'rejected',
] as const
export type MembershipStatus = (typeof MEMBERSHIP_STATUSES)[number]

export const INTEREST_STATUSES = [
  'sent',
  'awaiting_owner_approval',
  'shared',
  'connected',
  'meeting',
  'closed',
] as const
export type InterestStatus = (typeof INTEREST_STATUSES)[number]

export const INFO_REQUEST_STATUSES = ['sent', 'processing', 'answered', 'closed'] as const
export type InfoRequestStatus = (typeof INFO_REQUEST_STATUSES)[number]

/** ع / د / س / ح */
export const SENSITIVITIES = ['public', 'internal', 'confidential', 'sensitive_personal'] as const
export type Sensitivity = (typeof SENSITIVITIES)[number]

export const EVALUATION_AXES = ['readiness', 'feasibility', 'impact'] as const
export type EvaluationAxis = (typeof EVALUATION_AXES)[number]

export const EVALUATION_RESULTS = ['eligible', 'eligible_with_conditions', 'not_eligible_now'] as const
export type EvaluationResult = (typeof EVALUATION_RESULTS)[number]

export const ASSIGNMENT_STATUSES = ['assigned', 'coi_declared', 'scored', 'withdrawn'] as const
export type AssignmentStatus = (typeof ASSIGNMENT_STATUSES)[number]

export const DOCUMENT_KINDS = [
  'pitch_deck',
  'commercial_register',
  'license',
  'financial_statements',
  'business_plan',
  'team_cv',
  'impact_evidence',
  'authorization_letter',
  'financial_projections',
  'agreement',
  'progress_evidence',
  'other',
] as const
/** INV-01 — investor membership file: individuals upload a CV; institutions the CR, authorization letter and impact policy. */
export const INVESTOR_DOCUMENT_KINDS = {
  individual: ['investor_cv'],
  institutional: ['commercial_register', 'authorization_letter', 'impact_policy'],
} as const
export type InvestorDocumentKind = (typeof INVESTOR_DOCUMENT_KINDS)[keyof typeof INVESTOR_DOCUMENT_KINDS][number]
export type DocumentKind = (typeof DOCUMENT_KINDS)[number] | InvestorDocumentKind

export const DOCUMENT_STATUSES = ['pending_review', 'accepted', 'required', 'rejected'] as const
export type DocumentStatus = (typeof DOCUMENT_STATUSES)[number]

export const INDICATOR_VALUE_STATUSES = ['entered', 'verified', 'needs_clarification'] as const
export type IndicatorValueStatus = (typeof INDICATOR_VALUE_STATUSES)[number]

export const EXECUTION_STATUSES = ['on_track', 'delayed', 'stalled'] as const
export type ExecutionStatus = (typeof EXECUTION_STATUSES)[number]

/** PRJ-01 milestone plan approval · milestone state (`delayed` is derived) · PRJ-02 report review · ENT-10 update review */
export const MILESTONE_PLAN_STATUSES = ['draft', 'submitted', 'approved', 'changes_requested'] as const
export type MilestonePlanStatus = (typeof MILESTONE_PLAN_STATUSES)[number]
export const MILESTONE_STATUSES = ['planned', 'in_progress', 'done', 'delayed'] as const
export type MilestoneStatus = (typeof MILESTONE_STATUSES)[number]
export const PROGRESS_REPORT_STATUSES = ['submitted', 'accepted', 'changes_requested'] as const
export type ProgressReportStatus = (typeof PROGRESS_REPORT_STATUSES)[number]
export const INVESTOR_UPDATE_STATUSES = ['pending_review', 'published', 'rejected'] as const
export type InvestorUpdateStatus = (typeof INVESTOR_UPDATE_STATUSES)[number]

export const MEETING_STATUSES = ['scheduled', 'held', 'cancelled'] as const
export type MeetingStatus = (typeof MEETING_STATUSES)[number]

/** AGR-02..05 — draft → legal_review → changes_requested → approved → sent_for_signature → signed → archived | cancelled */
export const AGREEMENT_STATUSES = ['draft', 'legal_review', 'changes_requested', 'approved', 'sent_for_signature', 'signed', 'archived', 'cancelled'] as const
export type AgreementStatus = (typeof AGREEMENT_STATUSES)[number]
export const AGREEMENT_PARTIES = ['owner', 'investor'] as const
export type AgreementParty = (typeof AGREEMENT_PARTIES)[number]

export const CONSENT_TYPES = [
  'privacy_policy',
  'terms',
  'teaser_publication',
  'full_file_share',
  'nda',
  'data_accuracy',
] as const
export type ConsentType = (typeof CONSENT_TYPES)[number]

export const NOTIFICATION_CHANNELS = ['database', 'mail', 'sms'] as const
export type NotificationChannel = (typeof NOTIFICATION_CHANNELS)[number]

export const PAYMENT_STATUSES = ['pending', 'authorized', 'paid', 'failed', 'refunded', 'cancelled'] as const
export type PaymentStatus = (typeof PAYMENT_STATUSES)[number]

export const PAYMENT_PURPOSES = ['membership_fee', 'service_fee', 'training', 'sponsorship'] as const
export type PaymentPurpose = (typeof PAYMENT_PURPOSES)[number]

/** 9 seeded taxonomy values */
export const INVESTMENT_TYPES = [
  'equity',
  'debt',
  'pay_for_results',
  'grant',
  'guarantee',
  'patient_capital',
  'convertible',
  'revenue_share',
  'blended',
] as const
export type InvestmentType = (typeof INVESTMENT_TYPES)[number]

export const PROJECT_STAGES = ['idea', 'prototype', 'pre_seed', 'seed', 'early_stage', 'later_stage'] as const
export type ProjectStage = (typeof PROJECT_STAGES)[number]

/** Opportunity application — team / market / motivation sections (api Opportunity::*). */
export const PRODUCT_STAGES = ['idea', 'prototype', 'mvp', 'launched', 'scaling'] as const
export type ProductStage = (typeof PRODUCT_STAGES)[number]

export const FOUNDERS_COMMITMENTS = ['full_time', 'part_time', 'transitioning'] as const
export type FoundersCommitment = (typeof FOUNDERS_COMMITMENTS)[number]

/** How long the founders have known each other: <1 / 1–3 / 3–5 / 5+ years. */
export const FOUNDERS_KNOWN_YEARS = ['lt_1', '1_3', '3_5', '5_plus'] as const
export type FoundersKnownYears = (typeof FOUNDERS_KNOWN_YEARS)[number]

export const DEV_TEAM_STRUCTURES = ['in_house', 'outsourced', 'hybrid', 'none'] as const
export type DevTeamStructure = (typeof DEV_TEAM_STRUCTURES)[number]

export const SUPPORT_NEEDS = ['mentoring', 'investor_intros', 'market_access', 'technical', 'legal', 'impact_measurement'] as const
export type SupportNeed = (typeof SUPPORT_NEEDS)[number]

export const MAX_FOUNDERS = 6

export const RISK_APPETITES = ['high', 'medium', 'low'] as const
export type RiskAppetite = (typeof RISK_APPETITES)[number]

export const REVENUE_STATES = ['none', 'early', 'regular'] as const
export type RevenueState = (typeof REVENUE_STATES)[number]

export const LEGAL_FORMS = ['company', 'establishment', 'nonprofit', 'other'] as const
export type LegalForm = (typeof LEGAL_FORMS)[number]

export const INSTITUTION_TYPES = ['company', 'fund', 'family_office', 'donor', 'nonprofit', 'other'] as const
export type InstitutionType = (typeof INSTITUTION_TYPES)[number]

/** SPEC §1.1 users.type */
export const USER_TYPES = [
  'investor_individual',
  'investor_institutional',
  'entrepreneur_individual',
  'entrepreneur_entity',
  'staff',
] as const
export type UserType = (typeof USER_TYPES)[number]

/** SPEC §1.2 staff roles */
export const STAFF_ROLES = [
  'super_admin',
  'membership_officer',
  'reviewer',
  'evaluator',
  'matching_officer',
  'agreements_officer',
  'impact_officer',
  'advisory_member',
  'reports_viewer',
] as const
export type StaffRole = (typeof STAFF_ROLES)[number]

/** SPEC §1.3 investor access levels */
export const ACCESS_LEVELS = ['visitor', 'registered', 'approved_member', 'granted', 'connected'] as const
export type AccessLevel = (typeof ACCESS_LEVELS)[number]

/** Taxonomy groups editable by staff (FRM-04) */
export const TAXONOMY_GROUPS = [
  'sectors',
  'regions',
  'investment_types',
  'beneficiary_groups',
  'sdgs',
  'project_stages',
  'investment_ranges',
  'decline_reasons',
  'how_heard',
] as const
export type TaxonomyGroup = (typeof TAXONOMY_GROUPS)[number]

export const INDICATOR_FREQUENCIES = ['monthly', 'quarterly', 'semiannual', 'annual'] as const
export type IndicatorFrequency = (typeof INDICATOR_FREQUENCIES)[number]

export const REPORT_KEYS = [
  'registrations_by_type',
  'memberships_by_status',
  'opportunities_funnel',
  'opportunities_by_sector_stage_region',
  'first_time_completion_rate',
  'avg_review_time',
  'avg_evaluation_time',
  'overdue_items',
  'eligibility_rate',
  'interest_rate',
  'connection_rate',
  'acquisition_channels',
] as const
export type ReportKey = (typeof REPORT_KEYS)[number]

export const CONTACT_CATEGORIES = ['investor', 'entrepreneur', 'partnership', 'media', 'other'] as const
export type ContactCategory = (typeof CONTACT_CATEGORIES)[number]

/** Allowed transitions map — SPEC §3 (ADM-01). Used by the admin UI to show only valid actions. */
export const OPPORTUNITY_TRANSITIONS: Record<OpportunityStatus, OpportunityStatus[]> = {
  draft: ['new', 'withdrawn', 'closed'],
  new: ['under_review', 'needs_completion', 'not_eligible', 'withdrawn', 'closed'],
  under_review: ['eligible', 'needs_completion', 'not_eligible', 'withdrawn', 'closed'],
  needs_completion: ['under_review', 'withdrawn', 'closed'],
  not_eligible: ['needs_completion', 'withdrawn', 'closed'],
  eligible: ['connected', 'withdrawn', 'closed'],
  connected: ['negotiating', 'closed'],
  negotiating: ['in_execution', 'closed'],
  in_execution: ['operating', 'closed'],
  operating: ['closed'],
  withdrawn: [],
  closed: [],
}

export const MEMBERSHIP_TRANSITIONS: Record<MembershipStatus, MembershipStatus[]> = {
  registered: ['under_review'],
  under_review: ['needs_completion', 'approved', 'rejected'],
  needs_completion: ['under_review'],
  approved: ['suspended'],
  suspended: ['approved'],
  rejected: [],
}

export const INTEREST_TRANSITIONS: Record<InterestStatus, InterestStatus[]> = {
  sent: ['awaiting_owner_approval', 'closed'],
  awaiting_owner_approval: ['shared', 'closed'],
  shared: ['connected', 'closed'],
  connected: ['meeting', 'closed'],
  meeting: ['closed'],
  closed: [],
}

/** Statuses never visible to investors (doc §6.6) */
export const INVESTOR_HIDDEN_STATUSES: OpportunityStatus[] = [
  'draft',
  'new',
  'under_review',
  'needs_completion',
  'not_eligible',
]
