/**
 * Resource serializers for mock mode. Mirrors the sensitivity matrix (SPEC §5):
 * OpportunityCardResource / OpportunityDetailResource($accessLevel) / OpportunityFullResource.
 */
import type { AccessLevel } from '~/types/enums'
import type { Interest, InfoRequest, Meeting, Membership, Me, Nomination, OpportunityCard, OpportunityDetail, OpportunityFull, OpportunityMarket, OpportunityMarketPublic, OpportunityMotivation, OpportunityTeam, QueueItem } from '~/types/api'
import {
  type MockUser,
  type OpportunityRow,
  fileGrants,
  fundingBand,
  infoRequests,
  interests,
  meetings,
  ndaAccepted,
  nominations,
  opportunities,
  profileCompletion,
  savedByUser,
  setting,
  taxonomies,
  txById,
  userById,
} from './db'

export const serializeMe = (u: MockUser): Me => {
  const completion = u.type.startsWith('investor') ? profileCompletion(u.profile).percent : undefined
  return {
    uuid: u.uuid,
    type: u.type,
    name: u.name,
    email: u.email,
    email_verified_at: u.email_verified_at,
    phone: u.phone,
    phone_verified_at: u.phone_verified_at,
    locale: u.locale,
    two_factor_enabled: u.two_factor_enabled,
    roles: u.roles,
    permissions: permissionsFor(u),
    status: u.status,
    organization: u.organization ? { uuid: u.organization.uuid, name: u.organization.name, institution_type: u.organization.institution_type as never, legal_form: u.organization.legal_form as never, city: u.organization.city } : null,
    membership: u.membership ?? null,
    completion_percent: completion,
    preferred_contact: u.preferred_contact ?? null,
    last_login_at: u.last_login_at,
  }
}

const ROLE_PERMS: Record<string, string[]> = {
  super_admin: ['opportunities.view', 'opportunities.transition', 'evaluations.assign', 'evaluations.score', 'evaluations.approve', 'memberships.review', 'memberships.approve', 'nominations.manage', 'teasers.approve', 'meetings.manage', 'agreements.manage', 'impact.verify', 'reports.view', 'reports.export', 'settings.manage', 'users.manage', 'audit.view'],
  membership_officer: ['memberships.review', 'memberships.approve', 'reports.view'],
  reviewer: ['opportunities.view', 'opportunities.transition'],
  evaluator: ['opportunities.view', 'evaluations.score'],
  matching_officer: ['opportunities.view', 'nominations.manage', 'teasers.approve', 'meetings.manage'],
  agreements_officer: ['opportunities.view', 'agreements.manage'],
  impact_officer: ['opportunities.view', 'impact.verify', 'reports.view'],
  advisory_member: ['opportunities.view'],
  reports_viewer: ['reports.view', 'reports.export'],
}
export const permissionsFor = (u: MockUser) => Array.from(new Set(u.roles.flatMap((r) => ROLE_PERMS[r] ?? [])))

export const accessLevelFor = (u: MockUser | null, opp: OpportunityRow): AccessLevel => {
  if (!u) return 'visitor'
  if (!u.type.startsWith('investor')) return 'visitor'
  if (!u.email_verified_at || !u.phone_verified_at) return 'visitor'
  if (u.membership?.status !== 'approved') return 'registered'
  const interest = interests.find((i) => i.investor_user_id === u.id && i.opportunity_uuid === opp.uuid && ['connected', 'meeting'].includes(i.status))
  if (interest) return 'connected'
  const grant = fileGrants.find((g) => g.opportunity_uuid === opp.uuid && g.investor_user_id === u.id && !g.revoked_at)
  if (grant && ndaAccepted[u.id]?.has(opp.uuid)) return 'granted'
  return 'approved_member'
}

export const serializeCard = (o: OpportunityRow, viewer?: MockUser | null): OpportunityCard => {
  const mode = setting<'band' | 'exact' | 'hidden'>('opportunity.funding_display')
  const sector = txById('sectors', o.sector_id ?? -1) ?? taxonomies.sectors[0]
  return {
    uuid: o.uuid,
    reference_no: o.reference_no,
    name: o.name,
    organization_name: o.organization?.name ?? null,
    sector,
    stage: o.stage ?? 'idea',
    status: o.status,
    regions: o.region_ids.map((id) => txById('regions', id)!).filter(Boolean),
    investment_types: o.investment_type_keys,
    founded_year: o.organization?.founded_on ? Number(o.organization.founded_on.slice(0, 4)) : null,
    teaser_summary: o.teaser?.summary_ar || o.short_description,
    beneficiaries_target: o.beneficiaries_target,
    beneficiary_groups: o.beneficiary_group_ids.map((id) => txById('beneficiary_groups', id)!).filter(Boolean),
    funding_display: mode === 'hidden' ? { mode } : mode === 'exact' ? { mode, amount: o.funding_amount } : { mode, band: fundingBand(o.funding_amount) },
    impact_score: o.impact_score,
    is_saved: viewer ? savedByUser[viewer.id]?.has(o.uuid) ?? false : false,
    is_nominated: viewer ? nominations.some((n) => n.investor_user_id === viewer.id && n.opportunity_uuid === o.uuid) : false,
    published_at: o.teaser?.approved_by_council_at ?? null,
    is_demo: true,
  }
}

/** Team & founders (س) — mirrors FormatsOpportunity::teamBlock(). */
export const teamOf = (o: OpportunityRow): OpportunityTeam => ({
  founders_count: o.founders_count ?? null,
  team_size: o.team_size ?? null,
  founders_commitment: o.founders_commitment ?? null,
  founders_commitment_note: o.founders_commitment_note ?? null,
  prior_startup_experience: o.prior_startup_experience ?? null,
  prior_startup_experience_desc: o.prior_startup_experience_desc ?? null,
  industry_experience: o.industry_experience ?? null,
  industry_experience_desc: o.industry_experience_desc ?? null,
  founders_known_years: o.founders_known_years ?? null,
  has_advisory_board: o.has_advisory_board ?? null,
  advisory_board_desc: o.advisory_board_desc ?? null,
  dev_team_structure: o.dev_team_structure ?? null,
  founders_agreement_signed: o.founders_agreement_signed ?? null,
  founders: (o.founders ?? []).slice(0, 6),
})
/** Public market subset — the only market answers investors see. */
export const marketPublicOf = (o: OpportunityRow): OpportunityMarketPublic => ({
  product_stage: o.product_stage ?? null,
  target_customers: o.target_customers ?? null,
  competitive_advantage: o.competitive_advantage ?? null,
  traction_desc: o.traction_desc ?? null,
})
export const marketOf = (o: OpportunityRow): OpportunityMarket => ({
  ...marketPublicOf(o),
  market_size_desc: o.market_size_desc ?? null,
  competitors: o.competitors ?? null,
  go_to_market: o.go_to_market ?? null,
  technology_desc: o.technology_desc ?? null,
  scalability_desc: o.scalability_desc ?? null,
})
/** Motivation (د) — owner + staff only. */
export const motivationOf = (o: OpportunityRow): OpportunityMotivation => ({
  why_now: o.why_now ?? null,
  support_needed: o.support_needed ?? [],
  program_expectations: o.program_expectations ?? null,
  how_heard_id: o.how_heard_id ?? null,
  how_heard: o.how_heard_id ? txById('how_heard', o.how_heard_id) ?? null : null,
})

export const serializeDetail = (o: OpportunityRow, viewer: MockUser | null, forcedLevel?: AccessLevel): OpportunityDetail => {
  const level = forcedLevel ?? accessLevelFor(viewer, o)
  const granted = level === 'granted' || level === 'connected'
  const card = serializeCard(o, viewer)
  const myInterest = viewer ? interests.find((i) => i.investor_user_id === viewer.id && i.opportunity_uuid === o.uuid && i.status !== 'closed') : null
  const myNomination = viewer ? nominations.find((n) => n.investor_user_id === viewer.id && n.opportunity_uuid === o.uuid) : null
  const owner = userById(o.owner_user_id)
  return {
    ...card,
    access_level: level,
    short_description: o.short_description,
    website: o.website,
    problem_statement: o.problem_statement,
    solution: o.solution,
    expected_change: o.expected_change,
    beneficiaries_desc: o.beneficiaries_desc,
    beneficiaries_current: o.beneficiaries_current,
    revenue_model_type: o.revenue_model_type,
    revenue_model_desc: o.revenue_model_desc,
    revenue_state: o.revenue_state ?? 'none',
    primary_indicator: o.indicators.find((i) => i.is_primary) ?? null,
    disclosed_indicators: o.indicators.filter((i) => i.is_disclosed && !i.is_primary),
    sdgs: o.sdg_ids.map((id) => txById('sdgs', id)!).filter(Boolean),
    use_of_funds: granted ? o.use_of_funds : o.use_of_funds.map((u) => ({ item: u.item, percent: u.percent })),
    risks_summary: o.operational_risks ? `${o.operational_risks.split('؛')[0].split('.')[0]}.` : null,
    risks_full: granted
      ? {
          prior_funding: { has: o.prior_funding, desc: o.prior_funding_desc },
          legal_disputes: { has: o.legal_disputes, desc: o.legal_disputes_desc },
          license: { required: o.license_required, status: o.license_status },
          operational_risks: o.operational_risks,
        }
      : null,
    documents: granted ? { locked: false, items: o.documents.filter((d) => d.status === 'accepted') } : { locked: true, items: [] },
    ...marketPublicOf(o),
    market: marketPublicOf(o),
    team: granted ? teamOf(o) : null,
    owner_contact: level === 'connected' && owner ? { name: owner.name, email: owner.email, phone: owner.phone } : null,
    teaser_highlights: o.teaser?.highlights ?? [],
    my_interest: myInterest ? serializeInterest(myInterest) : null,
    my_nomination: myNomination ? serializeNomination(myNomination) : null,
  }
}

export const serializeFull = (o: OpportunityRow): OpportunityFull & { completion_percent: number } => {
  const owner = userById(o.owner_user_id)
  const reviewer = o.assigned_reviewer_id ? userById(o.assigned_reviewer_id) : null
  const { transitions: _t, notes: _n, completion_requests: _c, evaluation_assignments: _e, evaluation_decision: _d, owner_user_id: _o, sector_key: _s, ...rest } = o
  const dueDates = [o.review_due_at, o.completion_due_at, o.evaluation_due_at].filter(Boolean) as string[]
  const isOverdue = dueDates.some((d) => new Date(d).getTime() < Date.now()) && !['eligible', 'connected', 'negotiating', 'in_execution', 'operating', 'closed', 'withdrawn'].includes(o.status)
  return {
    ...rest,
    owner: owner ? { uuid: owner.uuid, name: owner.name, email: owner.email, phone: owner.phone, type: owner.type } : undefined,
    assigned_reviewer: reviewer ? { id: reviewer.id, name: reviewer.name } : null,
    is_overdue: isOverdue,
    team: teamOf(o),
    market: marketOf(o),
    motivation: motivationOf(o),
    completion_percent: checklistFor(o).completion_percent,
  }
}

export const checklistFor = (o: OpportunityRow) => {
  const items: { key: string; label: string; section: string; done: boolean; kind: 'field' | 'document' }[] = [
    { key: 'name', label: 'اسم المشروع', section: 'basics', done: !!o.name, kind: 'field' },
    { key: 'short_description', label: 'الوصف المختصر', section: 'basics', done: !!o.short_description, kind: 'field' },
    { key: 'sector_id', label: 'القطاع', section: 'basics', done: !!o.sector_id, kind: 'field' },
    { key: 'stage', label: 'المرحلة', section: 'basics', done: !!o.stage, kind: 'field' },
    { key: 'region_ids', label: 'المناطق', section: 'basics', done: o.region_ids.length > 0, kind: 'field' },
    { key: 'investment_type_keys', label: 'أنواع الاستثمار', section: 'basics', done: o.investment_type_keys.length > 0, kind: 'field' },
    { key: 'founders_count', label: 'عدد المؤسسين', section: 'team', done: o.founders_count != null, kind: 'field' },
    { key: 'team_size', label: 'حجم الفريق', section: 'team', done: o.team_size != null, kind: 'field' },
    { key: 'founders_commitment', label: 'مدى تفرّغ المؤسسين', section: 'team', done: !!o.founders_commitment, kind: 'field' },
    { key: 'problem_statement', label: 'المشكلة', section: 'project', done: !!o.problem_statement, kind: 'field' },
    { key: 'solution', label: 'الحل', section: 'project', done: !!o.solution, kind: 'field' },
    { key: 'expected_change', label: 'التغيير المتوقع', section: 'project', done: !!o.expected_change, kind: 'field' },
    { key: 'beneficiaries_desc', label: 'وصف المستفيدين', section: 'project', done: !!o.beneficiaries_desc, kind: 'field' },
    { key: 'beneficiaries_target', label: 'عدد المستفيدين المستهدف', section: 'project', done: o.beneficiaries_target != null, kind: 'field' },
    { key: 'indicators', label: 'مؤشر أثر رئيسي', section: 'impact', done: o.indicators.some((i) => i.is_primary), kind: 'field' },
    { key: 'product_stage', label: 'مرحلة المنتج أو الخدمة', section: 'market', done: !!o.product_stage, kind: 'field' },
    { key: 'target_customers', label: 'العملاء المستهدفون', section: 'market', done: !!o.target_customers, kind: 'field' },
    { key: 'competitive_advantage', label: 'الميزة التنافسية', section: 'market', done: !!o.competitive_advantage, kind: 'field' },
    { key: 'funding_amount', label: 'مبلغ التمويل', section: 'financials', done: o.funding_amount != null, kind: 'field' },
    { key: 'use_of_funds', label: 'استخدام التمويل (100%)', section: 'financials', done: o.use_of_funds.reduce((a, b) => a + (b.percent || 0), 0) === 100, kind: 'field' },
    { key: 'pitch_deck', label: 'العرض التعريفي', section: 'documents', done: o.documents.some((d) => d.kind === 'pitch_deck' && d.status !== 'rejected'), kind: 'document' },
    { key: 'team_cv', label: 'السير الذاتية للفريق', section: 'documents', done: o.documents.some((d) => d.kind === 'team_cv' && d.status !== 'rejected'), kind: 'document' },
    { key: 'why_now', label: 'لماذا الآن', section: 'motivation', done: !!o.why_now, kind: 'field' },
    { key: 'data_accuracy', label: 'إقرار صحة البيانات', section: 'disclosures', done: !!o.data_accuracy_declared_at, kind: 'field' },
    { key: 'teaser_consent', label: 'الموافقة على نشر الملخص', section: 'disclosures', done: o.teaser_publication_consent, kind: 'field' },
  ]
  const done = items.filter((i) => i.done).length
  return { ready: done === items.length, completion_percent: Math.round((done / items.length) * 100), items }
}

export const oppRef = (uuid: string) => {
  const o = opportunities.find((x) => x.uuid === uuid)!
  return { uuid: o.uuid, name: o.name, reference_no: o.reference_no }
}

export const serializeInterest = (i: (typeof interests)[number], withInvestor = false): Interest => {
  const { investor_user_id, opportunity_uuid, ...rest } = i
  const inv = userById(investor_user_id)
  const o = opportunities.find((x) => x.uuid === opportunity_uuid)!
  return {
    ...rest,
    opportunity: serializeCard(o),
    investor: withInvestor && inv ? { uuid: inv.uuid, name: inv.name, type: inv.type, email: inv.email } : undefined,
  }
}

export const serializeNomination = (n: (typeof nominations)[number], withInvestor = false): Nomination => {
  const { investor_user_id, opportunity_uuid, ...rest } = n
  const inv = userById(investor_user_id)
  const o = opportunities.find((x) => x.uuid === opportunity_uuid)!
  return { ...rest, opportunity: serializeCard(o, inv), investor: withInvestor && inv ? { uuid: inv.uuid, name: inv.name, type: inv.type } : undefined }
}

export const serializeInfoRequest = (r: (typeof infoRequests)[number], withInvestor = false): InfoRequest => {
  const { investor_user_id, opportunity_uuid, ...rest } = r
  const inv = userById(investor_user_id)
  return { ...rest, opportunity: oppRef(opportunity_uuid), investor: withInvestor && inv ? { uuid: inv.uuid, name: inv.name } : null }
}

export const serializeMeeting = (m: (typeof meetings)[number]): Meeting => {
  const { participant_user_ids: _p, opportunity_uuid, interest_id: _i, ...rest } = m
  return { ...rest, opportunity: oppRef(opportunity_uuid) }
}

export const serializeMembership = (u: MockUser): Membership => {
  const m = u.membership ?? { id: u.id * 100, status: 'registered' as const, submitted_at: null }
  const completion = profileCompletion(u.profile)
  return {
    ...m,
    submitted_at: m.submitted_at ?? null,
    user: { uuid: u.uuid, name: u.name, email: u.email, type: u.type, phone: u.phone, organization: u.organization ? { uuid: u.organization.uuid, name: u.organization.name } : null },
    profile: u.profile ? { ...u.profile, completion_percent: completion.percent, missing_items: completion.missing } : undefined,
    missing_items: m.missing_items ?? completion.missing,
  }
}

export const queueItem = (o: OpportunityRow): QueueItem => {
  const owner = userById(o.owner_user_id)
  const reviewer = o.assigned_reviewer_id ? userById(o.assigned_reviewer_id) : null
  const due = o.status === 'new' ? o.review_due_at : o.status === 'needs_completion' ? o.completion_due_at : o.status === 'under_review' ? o.evaluation_due_at : null
  const daysLeft = due ? Math.ceil((new Date(due).getTime() - Date.now()) / 86400000) : null
  return {
    uuid: o.uuid,
    reference_no: o.reference_no ?? '—',
    name: o.name,
    status: o.status,
    sector: txById('sectors', o.sector_id ?? -1) ?? taxonomies.sectors[0],
    owner_name: owner?.name ?? '—',
    assignee: reviewer ? { id: reviewer.id, name: reviewer.name } : null,
    sla_due_at: due ?? null,
    sla_days_left: daysLeft,
    is_overdue: daysLeft != null && daysLeft < 0,
    submitted_at: o.submitted_at ?? o.created_at,
    updated_at: o.updated_at,
  }
}
