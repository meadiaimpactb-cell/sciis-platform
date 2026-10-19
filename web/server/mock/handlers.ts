/**
 * Mock API handlers — implements the subset of SPEC §6 that the web UI consumes.
 * Shapes mirror Laravel API Resources. All data is synthetic (SPEC §11).
 */
import { getCookie, setCookie, deleteCookie } from 'h3'
import { MockRouter, apiError, paginate, type Handler } from './router'
import type { ImpactIndicator, DocumentResource, CompletionRequest } from '~/types/api'
import {
  DEMO,
  type MockUser,
  type OpportunityRow,
  audit,
  auditLogs,
  contactMessages,
  content,
  criteria,
  daysAgo,
  daysAhead,
  faqs,
  fileGrants,
  infoRequests,
  interests,
  investorUsers,
  makeEmptyProfile,
  matchCriteria,
  meetings,
  ndaAccepted,
  nextCompletionId,
  nextContactRef,
  nextContentId,
  nextCriterionId,
  nextDocUuid,
  nextInfoId,
  nextInterestId,
  nextMeetingId,
  nextNominationId,
  nextNoteId,
  nextTermId,
  nextTransitionId,
  nextUserId,
  nominations,
  oppByUuid,
  opportunities,
  pendingTwoFactor,
  profileCompletion,
  savedByUser,
  sessions,
  setting,
  settings,
  taxonomies,
  tx,
  txById,
  userByEmail,
  userById,
  users,
} from './db'
import {
  accessLevelFor,
  checklistFor,
  queueItem,
  serializeCard,
  serializeDetail,
  serializeFull,
  serializeInfoRequest,
  serializeInterest,
  serializeMe,
  serializeMeeting,
  serializeMembership,
  serializeNomination,
} from './serializers'
import { OPPORTUNITY_TRANSITIONS, MEMBERSHIP_TRANSITIONS, INTEREST_TRANSITIONS, type OpportunityStatus, type MembershipStatus, type InterestStatus, type TaxonomyGroup, type ReportKey } from '~/types/enums'

const COOKIE = 'sciis_mock_session'

const currentUser = (event: Parameters<Handler>[0]['event']): MockUser | null => {
  const token = getCookie(event, COOKIE) || (event.node.req.headers.authorization?.replace(/^Bearer\s+/i, '') ?? '')
  const id = token ? sessions.get(token) : undefined
  return id ? userById(id) ?? null : null
}

const requireUser = (event: Parameters<Handler>[0]['event']) => {
  const u = currentUser(event)
  if (!u) throw apiError(401, 'Unauthenticated', undefined, 'unauthenticated')
  return u
}
const requireInvestor = (event: Parameters<Handler>[0]['event']) => {
  const u = requireUser(event)
  if (!u.type.startsWith('investor')) throw apiError(403, 'Investors only', undefined, 'forbidden')
  return u
}
const requireMember = (event: Parameters<Handler>[0]['event']) => {
  const u = requireInvestor(event)
  if (u.membership?.status !== 'approved') throw apiError(403, 'Approved membership required', undefined, 'membership_required')
  return u
}
const requireEntrepreneur = (event: Parameters<Handler>[0]['event']) => {
  const u = requireUser(event)
  if (!u.type.startsWith('entrepreneur')) throw apiError(403, 'Project owners only', undefined, 'forbidden')
  return u
}
const requireStaff = (event: Parameters<Handler>[0]['event'], roles?: string[]) => {
  const u = requireUser(event)
  if (u.type !== 'staff') throw apiError(403, 'Staff only', undefined, 'forbidden')
  if (roles && !u.roles.includes('super_admin') && !u.roles.some((r) => roles.includes(r))) throw apiError(403, 'Role not allowed', undefined, 'forbidden')
  return u
}

const newToken = () => `mock_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`
const randomId = () => Math.floor(Math.random() * 1e6)
const touch = (o: OpportunityRow) => (o.updated_at = new Date().toISOString())

const doTransition = (o: OpportunityRow, to: OpportunityStatus, actor: MockUser, reason?: string) => {
  const allowed = OPPORTUNITY_TRANSITIONS[o.status]
  if (!allowed.includes(to)) throw apiError(422, `Transition ${o.status} → ${to} not allowed`, { to: [`الانتقال من ${o.status} إلى ${to} غير مسموح`] }, 'transition_not_allowed')
  if (['not_eligible', 'needs_completion', 'closed'].includes(to) && !reason) throw apiError(422, 'Reason required', { reason: ['السبب مطلوب لهذه الحالة'] })
  if (to === 'eligible' && !(o.evaluation_result && ['eligible', 'eligible_with_conditions'].includes(o.evaluation_result)))
    throw apiError(422, 'Eligibility requires evaluation result', { to: ['يتطلب الانتقال إلى «مؤهّل» نتيجة تقييم (مؤهّل / مؤهّل بشروط)'] })
  const from = o.status
  o.status = to
  touch(o)
  const slaDays = { new: setting<number>('sla.review_days'), needs_completion: setting<number>('sla.completion_days'), under_review: setting<number>('sla.evaluation_days') } as Record<string, number>
  if (to === 'new') {
    o.review_due_at = daysAhead(slaDays.new)
    if (!o.reference_no) o.reference_no = `A-2026-${String(20 + opportunities.indexOf(o)).padStart(4, '0')}`
    o.submitted_at = new Date().toISOString()
  }
  if (to === 'needs_completion') o.completion_due_at = daysAhead(slaDays.needs_completion)
  if (to === 'under_review') o.evaluation_due_at = daysAhead(slaDays.under_review)
  if (to === 'eligible') {
    if (!o.teaser) o.teaser = { summary_ar: o.short_description, summary_en: null, highlights: [], version: 1, approved_by_owner_at: null, approved_by_council_at: null }
    o.locked_sections = ['basics', 'organization', 'project', 'impact', 'financials']
  }
  o.transitions.push({ id: nextTransitionId(), from_status: from, to_status: to, actor: { name: actor.name }, reason: reason ?? null, created_at: new Date().toISOString() })
  audit(actor, 'opportunity.transition', 'opportunity', o.reference_no ?? o.uuid, { status: from }, { status: to })
}

export const router = new MockRouter()

// ======================================================================== AUTH
router.post('/auth/register', ({ body }) => {
  const errors: Record<string, string[]> = {}
  if (!body.name) errors.name = ['الاسم مطلوب']
  if (!body.email || !/^[^@]+@[^@]+\.[^@]+$/.test(body.email)) errors.email = ['البريد غير صحيح']
  if (userByEmail(body.email ?? '')) errors.email = ['البريد مسجّل مسبقًا']
  if (!body.password || String(body.password).length < 8) errors.password = ['8 أحرف على الأقل']
  if (!body.phone) errors.phone = ['رقم الجوال مطلوب']
  if (Object.keys(errors).length) throw apiError(422, 'Validation failed', errors, 'validation')
  const u: MockUser = {
    id: nextUserId(),
    uuid: `u-${Date.now().toString(36)}`,
    type: body.type ?? 'investor_individual',
    name: `${body.name} ${DEMO}`,
    email: body.email,
    phone: body.phone,
    password: body.password,
    locale: body.locale ?? 'ar',
    roles: [],
    status: 'active',
    email_verified_at: null,
    phone_verified_at: null,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: new Date().toISOString(),
    organization: body.organization?.name ? { uuid: `org-${Date.now().toString(36)}`, name: `${body.organization.name} ${DEMO}`, institution_type: body.organization.institution_type, legal_form: body.organization.legal_form, city: body.organization.city, cr_number: body.organization.cr_number } : null,
    preferred_contact: body.preferred_contact ?? 'email',
  }
  if (u.type.startsWith('investor')) {
    const p = makeEmptyProfile()
    Object.assign(p, body.profile ?? {})
    u.profile = p
    u.membership = { id: u.id * 100, status: 'registered', submitted_at: null }
  }
  if (u.type.startsWith('entrepreneur') && body.project?.name) {
    opportunities.push({
      ...(opportunities[7] as OpportunityRow),
      uuid: `opp-${Date.now().toString(36)}`,
      reference_no: null,
      name: `${body.project.name} ${DEMO}`,
      short_description: body.project.short_description ?? '',
      sector_key: body.project.sector_key ?? 'education',
      sector_id: body.project.sector_id ?? taxonomies.sectors[0].id,
      stage: body.project.stage ?? 'idea',
      status: 'draft',
      owner_user_id: u.id,
      indicators: [],
      documents: [],
      transitions: [],
      notes: [],
      completion_requests: [],
      evaluation_assignments: [],
      evaluation_decision: null,
      teaser: null,
      use_of_funds: [],
      funding_amount: null,
      problem_statement: '',
      solution: '',
      expected_change: '',
      beneficiaries_desc: '',
      beneficiaries_target: null,
      data_accuracy_declared_at: null,
      teaser_publication_consent: false,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      locked_sections: [],
      region_ids: [],
      investment_type_keys: [],
      beneficiary_group_ids: [],
      sdg_ids: [],
    })
  }
  users.push(u)
  const token = newToken()
  sessions.set(token, u.id)
  audit(u, 'user.register', 'user', u.uuid)
  return { data: serializeMe(u), token }
})

router.post('/auth/login', ({ event, body }) => {
  const u = userByEmail(body.email ?? '')
  if (!u || u.password !== body.password) throw apiError(422, 'Invalid credentials', { email: ['البريد أو كلمة المرور غير صحيحة'] }, 'invalid_credentials')
  if (u.status === 'suspended') throw apiError(403, 'Account suspended', undefined, 'suspended')
  if (u.two_factor_enabled) {
    const challenge = newToken()
    pendingTwoFactor.set(challenge, u.id)
    return { two_factor_required: true, challenge_token: challenge }
  }
  const token = newToken()
  sessions.set(token, u.id)
  u.last_login_at = new Date().toISOString()
  setCookie(event, COOKIE, token, { path: '/', sameSite: 'lax', httpOnly: false })
  audit(u, 'user.login', 'user', u.uuid)
  return { data: serializeMe(u), token }
})

router.post('/auth/2fa/challenge', ({ event, body }) => {
  const id = pendingTwoFactor.get(body.challenge_token ?? '')
  if (!id || !/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'invalid_code')
  pendingTwoFactor.delete(body.challenge_token)
  const u = userById(id)!
  const token = newToken()
  sessions.set(token, u.id)
  u.last_login_at = new Date().toISOString()
  setCookie(event, COOKIE, token, { path: '/', sameSite: 'lax', httpOnly: false })
  return { data: serializeMe(u), token }
})

router.post('/auth/2fa/enable', ({ event }) => {
  const u = requireUser(event)
  return { secret: 'MOCKSECRET234567', qr_svg: null, otpauth_url: `otpauth://totp/SCII:${u.email}?secret=MOCKSECRET234567&issuer=SCII` }
})
router.post('/auth/2fa/confirm', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] })
  u.two_factor_enabled = true
  return { data: serializeMe(u) }
})
router.delete('/auth/2fa', ({ event }) => {
  const u = requireUser(event)
  if (u.type === 'staff') throw apiError(422, '2FA mandatory for staff', { code: ['التحقق بخطوتين إلزامي لفريق المجلس'] })
  u.two_factor_enabled = false
  return { data: serializeMe(u) }
})

router.post('/auth/logout', ({ event }) => {
  const token = getCookie(event, COOKIE)
  if (token) sessions.delete(token)
  deleteCookie(event, COOKIE, { path: '/' })
  return { ok: true }
})

router.post('/auth/verify-email', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'invalid_code')
  u.email_verified_at = new Date().toISOString()
  return { data: serializeMe(u) }
})
router.post('/auth/verify-phone', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'invalid_code')
  u.phone_verified_at = new Date().toISOString()
  return { data: serializeMe(u) }
})
router.post('/auth/resend-otp', () => ({ ok: true, expires_in: 300 }))
router.post('/auth/forgot-password', () => ({ ok: true }))
router.post('/auth/reset-password', ({ body }) => {
  if (!body.password || String(body.password).length < 8) throw apiError(422, 'Validation', { password: ['8 أحرف على الأقل'] })
  return { ok: true }
})

router.get('/me', ({ event }) => ({ data: serializeMe(requireUser(event)) }))
router.patch('/me', ({ event, body }) => {
  const u = requireUser(event)
  if (body.name) u.name = body.name
  if (body.locale) u.locale = body.locale
  if (body.preferred_contact) u.preferred_contact = body.preferred_contact
  return { data: serializeMe(u) }
})
router.post('/me/data-requests', ({ event, body }) => {
  const u = requireUser(event)
  audit(u, `data_request.${body.type}`, 'user', u.uuid, null, { note: body.note })
  return { ok: true, reference_no: `DR-${Date.now().toString(36).toUpperCase()}` }
})
router.get('/me/notification-preferences', () => ({
  data: [
    { channel: 'mail', category: 'opportunities', enabled: true },
    { channel: 'sms', category: 'opportunities', enabled: false },
    { channel: 'mail', category: 'meetings', enabled: true },
    { channel: 'sms', category: 'meetings', enabled: true },
  ],
}))
router.put('/me/notification-preferences', ({ body }) => ({ data: body.preferences ?? [] }))

// ======================================================================== PUBLIC
router.get('/taxonomies', () => ({ data: taxonomies }))
router.get('/taxonomies/:group', ({ params }) => {
  const g = params.group as TaxonomyGroup
  if (!taxonomies[g]) throw apiError(404, 'Unknown group')
  return { data: taxonomies[g] }
})
router.get('/settings/public', () => ({
  data: {
    funding_display: setting('opportunity.funding_display'),
    access_policy: setting('opportunity.access_policy'),
    locales: ['ar', 'en'],
    legal_versions: { privacy: '0.3-draft', terms: '0.3-draft', disclaimer: '0.2-draft' },
    platform: {
      name_ar: setting('platform.name_ar'),
      name_en: setting('platform.name_en'),
      short_name: setting('platform.short_name'),
      official_email: setting('platform.official_email'),
      official_phone: setting('platform.official_phone'),
      whatsapp: setting('platform.whatsapp'),
    },
    features: Object.fromEntries(settings.filter((s) => s.group === 'features').map((s) => [s.key.replace('features.', ''), s.value])),
  },
}))
router.get('/public/faqs', () => ({ data: faqs }))
router.get('/public/news', () => ({ data: content.filter((c) => c.kind === 'news' && c.is_published) }))
router.get('/public/events', () => ({ data: content.filter((c) => c.kind === 'events' && c.is_published) }))
router.get('/public/pages/:slug', ({ params }) => {
  const p = content.find((c) => c.kind === 'pages' && c.slug === params.slug)
  if (!p) throw apiError(404, 'Page not found')
  return { data: p }
})
router.get('/public/stats', () => ({
  data: {
    opportunities: opportunities.filter((o) => ['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length,
    members: users.filter((u) => u.membership?.status === 'approved').length,
    sectors: new Set(opportunities.map((o) => o.sector_id)).size,
    regions: new Set(opportunities.flatMap((o) => o.region_ids)).size,
    connections: opportunities.filter((o) => ['connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length,
  },
}))
router.get('/public/featured-opportunities', () => ({
  data: opportunities.filter((o) => o.status === 'eligible' && o.teaser?.approved_by_council_at).slice(0, 3).map((o) => serializeCard(o)),
}))
router.post('/public/contact', ({ body }) => {
  if (body.website_url) return { reference_no: 'C-0000-0000' } // honeypot → silently accepted
  const errors: Record<string, string[]> = {}
  if (!body.name) errors.name = ['الاسم مطلوب']
  if (!body.email || !/^[^@]+@[^@]+\.[^@]+$/.test(body.email)) errors.email = ['البريد غير صحيح']
  if (!body.message || String(body.message).length < 10) errors.message = ['اكتب رسالة من 10 أحرف على الأقل']
  if (Object.keys(errors).length) throw apiError(422, 'Validation failed', errors, 'validation')
  const reference_no = nextContactRef()
  contactMessages.unshift({ id: randomId(), category: body.category ?? 'other', name: body.name, email: body.email, phone: body.phone ?? null, message: body.message, reference_no, status: 'new', created_at: new Date().toISOString() })
  return { reference_no }
})

// ======================================================================== INVESTOR
router.get('/investor/profile', ({ event }) => {
  const u = requireInvestor(event)
  const p = u.profile ?? makeEmptyProfile()
  const c = profileCompletion(p)
  return { data: { ...p, completion_percent: c.percent, missing_items: c.missing } }
})
router.put('/investor/profile', ({ event, body }) => {
  const u = requireInvestor(event)
  u.profile = { ...(u.profile ?? makeEmptyProfile()), ...body }
  const c = profileCompletion(u.profile)
  audit(u, 'investor.profile.update', 'user', u.uuid)
  return { data: { ...u.profile, completion_percent: c.percent, missing_items: c.missing } }
})
router.get('/investor/membership', ({ event }) => ({ data: serializeMembership(requireInvestor(event)) }))
router.post('/investor/membership/submit', ({ event }) => {
  const u = requireInvestor(event)
  if (!u.membership || ['registered', 'needs_completion'].includes(u.membership.status)) {
    u.membership = { ...(u.membership ?? { id: u.id * 100 }), status: 'under_review', submitted_at: new Date().toISOString() } as MockUser['membership']
    audit(u, 'membership.submit', 'membership', u.membership!.id)
  }
  return { data: serializeMembership(u) }
})

router.get('/investor/dashboard', ({ event }) => {
  const u = requireInvestor(event)
  const c = profileCompletion(u.profile)
  const myInterests = interests.filter((i) => i.investor_user_id === u.id)
  const myNoms = nominations.filter((n) => n.investor_user_id === u.id)
  const myInfo = infoRequests.filter((i) => i.investor_user_id === u.id)
  const myMeetings = meetings.filter((m) => m.participant_user_ids.includes(u.id))
  const requests = [
    ...myInterests.map((i) => ({ kind: 'interest' as const, id: i.id, opportunity: { uuid: i.opportunity_uuid, name: oppByUuid(i.opportunity_uuid)!.name, reference_no: oppByUuid(i.opportunity_uuid)!.reference_no }, status: i.status, next_step: i.next_step ?? '—', updated_at: i.created_at })),
    ...myNoms.filter((n) => !n.response).map((n) => ({ kind: 'nomination' as const, id: n.id, opportunity: { uuid: n.opportunity_uuid, name: oppByUuid(n.opportunity_uuid)!.name, reference_no: oppByUuid(n.opportunity_uuid)!.reference_no }, status: 'pending', next_step: 'الرد على الترشيح (مهتم / اعتذار)', updated_at: n.sent_at })),
    ...myInfo.map((r) => ({ kind: 'info_request' as const, id: r.id, opportunity: { uuid: r.opportunity_uuid, name: oppByUuid(r.opportunity_uuid)!.name, reference_no: oppByUuid(r.opportunity_uuid)!.reference_no }, status: r.status, next_step: r.status === 'answered' ? 'اطّلع على الإجابة' : 'بانتظار إجابة صاحب المشروع', updated_at: r.created_at })),
  ].sort((a, b) => b.updated_at.localeCompare(a.updated_at))
  const suggested = opportunities
    .filter((o) => o.status === 'eligible' && o.teaser?.approved_by_council_at && !myInterests.some((i) => i.opportunity_uuid === o.uuid))
    .map((o) => ({ o, score: Object.values(matchCriteria(u, o)).filter(Boolean).length }))
    .sort((a, b) => b.score - a.score)
    .slice(0, 3)
    .map(({ o }) => serializeCard(o, u))
  const upcoming = myMeetings.filter((m) => m.status === 'scheduled' && new Date(m.starts_at) > new Date()).sort((a, b) => a.starts_at.localeCompare(b.starts_at))[0]
  return {
    data: {
      completion: { percent: c.percent, missing_items: c.missing },
      counters: { saved: savedByUser[u.id]?.size ?? 0, interests: myInterests.filter((i) => i.status !== 'closed').length, info_requests: myInfo.length, meetings: myMeetings.length },
      requests,
      suggested,
      updates: [
        { id: 1, title: `تمت الإجابة على طلب معلوماتك — سُلّم ${DEMO}`, body: 'أجاب صاحب المشروع عن سؤالك حول اتفاقيات الشركاء.', created_at: daysAgo(6), href: '/app/investor/info-requests' },
        { id: 2, title: `ترشيح جديد — مهارة ${DEMO}`, body: 'رُشّحت لفرصة جديدة بتطابق معيارين.', created_at: daysAgo(4), href: '/app/investor/nominations' },
        { id: 3, title: 'تحديث: سياسة الوصول للفرص', body: 'السياسة الحالية «الترشيح فقط» — بانتظار الرأي القانوني.', created_at: daysAgo(10) },
      ],
      next_meeting: upcoming ? serializeMeeting(upcoming) : null,
      membership_status: u.membership?.status ?? 'registered',
    },
  }
})

const visibleToMembers = (u: MockUser) => {
  const policy = setting<string>('opportunity.access_policy')
  return opportunities.filter((o) => {
    if (!o.teaser?.approved_by_council_at) return false
    if (!['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)) return false
    if (o.status !== 'eligible' && !o.is_listed) return false
    if (policy === 'nomination_only') return nominations.some((n) => n.investor_user_id === u.id && n.opportunity_uuid === o.uuid) || interests.some((i) => i.investor_user_id === u.id && i.opportunity_uuid === o.uuid)
    return true
  })
}

router.get('/opportunities', ({ event, query }) => {
  const u = requireMember(event)
  // In mock mode we show all teaser-approved opportunities so the catalogue is testable; policy is surfaced to the UI.
  let list = opportunities.filter((o) => o.teaser?.approved_by_council_at && ['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status) && (o.status === 'eligible' || o.is_listed))
  const f = (k: string) => query[`filter[${k}]`]
  const allForCounts = list
  if (f('sector')) list = list.filter((o) => txById('sectors', o.sector_id ?? -1)?.key === f('sector'))
  if (f('region')) list = list.filter((o) => o.region_ids.some((id) => txById('regions', id)?.key === f('region')))
  if (f('stage')) list = list.filter((o) => o.stage === f('stage'))
  if (f('type')) list = list.filter((o) => o.investment_type_keys.includes(f('type') as never))
  if (f('range')) {
    const r = f('range')
    list = list.filter((o) => {
      const a = o.funding_amount ?? 0
      return (r === 'lt_250k' && a < 250_000) || (r === '250k_1m' && a >= 250_000 && a < 1_000_000) || (r === '1m_3m' && a >= 1_000_000 && a < 3_000_000) || (r === '3m_10m' && a >= 3_000_000 && a < 10_000_000) || (r === 'gt_10m' && a >= 10_000_000)
    })
  }
  if (f('nominated') === '1') list = list.filter((o) => nominations.some((n) => n.investor_user_id === u.id && n.opportunity_uuid === o.uuid))
  if (query.search) {
    const s = query.search.toLowerCase()
    list = list.filter((o) => o.name.toLowerCase().includes(s) || (o.reference_no ?? '').toLowerCase().includes(s) || o.short_description.toLowerCase().includes(s))
  }
  const sort = query.sort ?? 'newest'
  list = [...list].sort((a, b) => (sort === 'impact' ? b.impact_score - a.impact_score : sort === 'need' ? (a.funding_amount ?? 0) - (b.funding_amount ?? 0) : (b.teaser?.approved_by_council_at ?? '').localeCompare(a.teaser?.approved_by_council_at ?? '')))
  const counts = {
    sector: Object.fromEntries(taxonomies.sectors.map((t) => [t.key, allForCounts.filter((o) => o.sector_id === t.id).length])),
    region: Object.fromEntries(taxonomies.regions.map((t) => [t.key, allForCounts.filter((o) => o.region_ids.includes(t.id)).length])),
    stage: Object.fromEntries(taxonomies.project_stages.map((t) => [t.key, allForCounts.filter((o) => o.stage === t.key).length])),
    type: Object.fromEntries(taxonomies.investment_types.map((t) => [t.key, allForCounts.filter((o) => o.investment_type_keys.includes(t.key as never)).length])),
    range: Object.fromEntries(taxonomies.investment_ranges.map((t) => [t.key, allForCounts.filter((o) => { const a = o.funding_amount ?? 0; const r = t.key; return (r === 'lt_250k' && a < 250_000) || (r === '250k_1m' && a >= 250_000 && a < 1_000_000) || (r === '1m_3m' && a >= 1_000_000 && a < 3_000_000) || (r === '3m_10m' && a >= 3_000_000 && a < 10_000_000) || (r === 'gt_10m' && a >= 10_000_000) }).length])),
  }
  const page = paginate(list.map((o) => serializeCard(o, u)), query)
  return { ...page, meta: { ...page.meta, counts, access_policy: setting('opportunity.access_policy'), visible_by_policy: visibleToMembers(u).map((o) => o.uuid) } }
})

router.get('/opportunities/:uuid', ({ event, params }) => {
  const u = requireMember(event)
  const o = oppByUuid(params.uuid)
  if (!o || ['draft', 'new', 'under_review', 'needs_completion', 'not_eligible'].includes(o.status)) throw apiError(404, 'Opportunity not found')
  return { data: serializeDetail(o, u) }
})
router.post('/opportunities/:uuid/save', ({ event, params }) => {
  const u = requireMember(event)
  ;(savedByUser[u.id] ??= new Set()).add(params.uuid)
  return { ok: true, is_saved: true }
})
router.delete('/opportunities/:uuid/save', ({ event, params }) => {
  const u = requireMember(event)
  savedByUser[u.id]?.delete(params.uuid)
  return { ok: true, is_saved: false }
})
router.get('/investor/saved', ({ event }) => {
  const u = requireInvestor(event)
  const ids = savedByUser[u.id] ?? new Set()
  return { data: opportunities.filter((o) => ids.has(o.uuid)).map((o) => serializeCard(o, u)) }
})
router.post('/opportunities/:uuid/interest', ({ event, params, body }) => {
  const u = requireMember(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  if (interests.some((i) => i.investor_user_id === u.id && i.opportunity_uuid === o.uuid && i.status !== 'closed')) throw apiError(422, 'Already interested', { opportunity: ['لديك اهتمام مفتوح بهذه الفرصة'] })
  const row = { id: nextInterestId(), investor_user_id: u.id, opportunity_uuid: o.uuid, opportunity: null as never, status: 'awaiting_owner_approval' as InterestStatus, owner_decision: null, note: body.note ?? null, created_at: new Date().toISOString(), next_step: 'بانتظار موافقة صاحب المشروع على مشاركة الملف الكامل (مهلة 7 أيام)' }
  interests.unshift(row)
  audit(u, 'interest.create', 'opportunity', o.reference_no ?? o.uuid)
  return { data: serializeInterest(row) }
})
router.get('/investor/interests', ({ event }) => {
  const u = requireInvestor(event)
  return { data: interests.filter((i) => i.investor_user_id === u.id).map((i) => serializeInterest(i)) }
})
router.get('/investor/interests/:id', ({ event, params }) => {
  const u = requireInvestor(event)
  const i = interests.find((x) => x.id === Number(params.id) && x.investor_user_id === u.id)
  if (!i) throw apiError(404, 'Not found')
  return { data: serializeInterest(i) }
})
router.post('/investor/interests/:id/close', ({ event, params, body }) => {
  const u = requireInvestor(event)
  const i = interests.find((x) => x.id === Number(params.id) && x.investor_user_id === u.id)
  if (!i) throw apiError(404, 'Not found')
  i.status = 'closed'
  i.closed_at = new Date().toISOString()
  i.closed_reason = txById('decline_reasons', Number(body.reason_id))?.label_ar ?? body.note ?? 'أغلقه المستثمر'
  i.next_step = null
  return { data: serializeInterest(i) }
})
router.post('/opportunities/:uuid/info-requests', ({ event, params, body }) => {
  const u = requireMember(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  const errors: Record<string, string[]> = {}
  if (!body.subject) errors.subject = ['الموضوع مطلوب']
  if (!body.body || String(body.body).length < 10) errors.body = ['اكتب تفاصيل من 10 أحرف على الأقل']
  if (Object.keys(errors).length) throw apiError(422, 'Validation failed', errors)
  const row = { id: nextInfoId(), investor_user_id: u.id, opportunity_uuid: o.uuid, opportunity: null as never, subject: body.subject, body: body.body, status: 'sent' as const, created_at: new Date().toISOString() }
  infoRequests.unshift(row)
  return { data: serializeInfoRequest(row) }
})
router.get('/investor/info-requests', ({ event }) => {
  const u = requireInvestor(event)
  return { data: infoRequests.filter((r) => r.investor_user_id === u.id).map((r) => serializeInfoRequest(r)) }
})
router.get('/investor/nominations', ({ event }) => {
  const u = requireInvestor(event)
  return { data: nominations.filter((n) => n.investor_user_id === u.id).map((n) => serializeNomination(n)) }
})
router.post('/investor/nominations/:id/respond', ({ event, params, body }) => {
  const u = requireInvestor(event)
  const n = nominations.find((x) => x.id === Number(params.id) && x.investor_user_id === u.id)
  if (!n) throw apiError(404, 'Not found')
  n.response = body.response
  n.responded_at = new Date().toISOString()
  if (body.response === 'declined') {
    n.decline_reason = txById('decline_reasons', Number(body.decline_reason_id)) ?? null
    n.decline_note = body.note ?? null
  } else if (!interests.some((i) => i.investor_user_id === u.id && i.opportunity_uuid === n.opportunity_uuid && i.status !== 'closed')) {
    interests.unshift({ id: nextInterestId(), investor_user_id: u.id, opportunity_uuid: n.opportunity_uuid, opportunity: null as never, status: 'awaiting_owner_approval', owner_decision: null, created_at: new Date().toISOString(), next_step: 'بانتظار موافقة صاحب المشروع على مشاركة الملف الكامل' })
  }
  return { data: serializeNomination(n) }
})
router.post('/opportunities/:uuid/nda-accept', ({ event, params }) => {
  const u = requireMember(event)
  ;(ndaAccepted[u.id] ??= new Set()).add(params.uuid)
  audit(u, 'consent.nda', 'opportunity', params.uuid)
  return { ok: true, data: serializeDetail(oppByUuid(params.uuid)!, u) }
})
router.get('/opportunities/:uuid/documents', ({ event, params }) => {
  const u = requireMember(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  const level = accessLevelFor(u, o)
  if (level !== 'granted' && level !== 'connected') throw apiError(403, 'Access not granted', undefined, 'not_granted')
  return { data: o.documents.filter((d) => d.status === 'accepted') }
})
router.get('/documents/:uuid/download-url', ({ event, params }) => {
  const u = requireUser(event)
  audit(u, 'document.download', 'document', params.uuid)
  return { url: `/mock-files/${params.uuid}.pdf`, expires_at: daysAhead(0.01) }
})
router.get('/investor/meetings', ({ event }) => {
  const u = requireInvestor(event)
  return { data: meetings.filter((m) => m.participant_user_ids.includes(u.id)).map(serializeMeeting) }
})

// ======================================================================== ENTREPRENEUR
const myOpps = (u: MockUser) => opportunities.filter((o) => o.owner_user_id === u.id)
const ownOpp = (u: MockUser, uuid: string) => {
  const o = myOpps(u).find((x) => x.uuid === uuid)
  if (!o) throw apiError(404, 'Opportunity not found')
  return o
}

router.get('/entrepreneur/dashboard', ({ event }) => {
  const u = requireEntrepreneur(event)
  const opps = myOpps(u)
  const uuids = opps.map((o) => o.uuid)
  const required: Record<string, unknown>[] = []
  for (const o of opps) {
    for (const cr of o.completion_requests.filter((c) => !c.resolved_at)) {
      cr.items.forEach((it, idx) => {
        if (!it.done) required.push({ id: cr.id * 100 + idx, opportunity_uuid: o.uuid, title: it.document_kind ? `رفع مستند: ${it.document_kind}` : `استكمال: ${it.field}`, note: it.note, due_at: cr.due_at, action: it.document_kind ? 'upload' : 'complete', kind: it.document_kind ? 'document' : 'field' })
      })
    }
    if (o.status === 'draft') required.push({ id: 9000 + opps.indexOf(o), opportunity_uuid: o.uuid, title: 'إكمال ملف المشروع وإرساله للمراجعة', note: `الاكتمال ${checklistFor(o).completion_percent}٪`, due_at: null, action: 'complete', kind: 'field' })
    if (o.teaser && !o.teaser.approved_by_owner_at) required.push({ id: 9100 + opps.indexOf(o), opportunity_uuid: o.uuid, title: 'مراجعة الملخص التنفيذي واعتماده', note: 'لن يُعرض للمستثمرين قبل موافقتك', due_at: null, action: 'approve_teaser', kind: 'teaser' })
  }
  for (const i of interests.filter((i) => uuids.includes(i.opportunity_uuid) && i.status === 'awaiting_owner_approval')) required.push({ id: 8000 + i.id, opportunity_uuid: i.opportunity_uuid, title: 'طلب وصول إلى الملف الكامل من مستثمر معتمد', note: 'مهلة الرد 7 أيام', due_at: daysAhead(7), action: 'decide_access', kind: 'access_request' })
  for (const r of infoRequests.filter((r) => uuids.includes(r.opportunity_uuid) && r.status === 'processing')) required.push({ id: 7000 + r.id, opportunity_uuid: r.opportunity_uuid, title: `الإجابة عن طلب معلومات: ${r.subject}`, note: null, due_at: daysAhead(5), action: 'answer_info', kind: 'info_request' })
  const accessRequests = interests.filter((i) => uuids.includes(i.opportunity_uuid) && ['awaiting_owner_approval', 'shared', 'connected', 'meeting'].includes(i.status)).map((i) => serializeInterest(i, i.owner_decision === 'approved'))
  const previewOpp = opps.find((o) => o.teaser) ?? opps[0]
  return {
    data: {
      opportunities: opps.map(serializeFull),
      required_now: required,
      access_requests: accessRequests,
      documents: opps.flatMap((o) => o.documents.map((d) => ({ ...d, opportunity_uuid: o.uuid }))),
      preview: previewOpp ? serializeCard(previewOpp) : null,
      info_requests_open: infoRequests.filter((r) => uuids.includes(r.opportunity_uuid) && r.status !== 'answered' && r.status !== 'closed').length,
      meetings_upcoming: meetings.filter((m) => uuids.includes(m.opportunity_uuid) && m.status === 'scheduled' && new Date(m.starts_at) > new Date()).length,
    },
  }
})
router.get('/entrepreneur/opportunities', ({ event }) => ({ data: myOpps(requireEntrepreneur(event)).map(serializeFull) }))
router.post('/entrepreneur/opportunities', ({ event, body }) => {
  const u = requireEntrepreneur(event)
  const sectorId = body.sector_id ?? taxonomies.sectors[0].id
  const row: OpportunityRow = {
    ...(opportunities[7] as OpportunityRow),
    uuid: `opp-${Date.now().toString(36)}`,
    reference_no: null,
    name: body.name ? `${body.name} ${DEMO}` : `مشروع جديد ${DEMO}`,
    short_description: body.short_description ?? '',
    sector_key: txById('sectors', sectorId)?.key ?? 'education',
    sector_id: sectorId,
    stage: body.stage ?? null,
    status: 'draft',
    owner_user_id: u.id,
    organization: u.organization ? { name: u.organization.name, legal_form: u.organization.legal_form as never, city: u.organization.city, cr_number: u.organization.cr_number } : undefined,
    region_ids: [],
    investment_type_keys: [],
    beneficiary_group_ids: [],
    sdg_ids: [],
    problem_statement: '',
    solution: '',
    expected_change: '',
    beneficiaries_desc: '',
    beneficiaries_current: null,
    beneficiaries_target: null,
    revenue_model_type: null,
    revenue_model_desc: null,
    revenue_state: null,
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
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    locked_sections: [],
    impact_score: 50,
    teaser: null,
    transitions: [{ id: nextTransitionId(), from_status: null, to_status: 'draft', actor: { name: u.name }, created_at: new Date().toISOString() }],
    notes: [],
    completion_requests: [],
    assigned_reviewer_id: null,
    evaluation_assignments: [],
    evaluation_decision: null,
    is_demo: true,
  }
  opportunities.push(row)
  return { data: serializeFull(row) }
})
router.get('/entrepreneur/opportunities/:uuid', ({ event, params }) => ({ data: serializeFull(ownOpp(requireEntrepreneur(event), params.uuid)) }))
router.patch('/entrepreneur/opportunities/:uuid', ({ event, params, body }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  const section = body.section as string | undefined
  if (section && o.locked_sections?.includes(section) && !['needs_completion', 'draft'].includes(o.status)) throw apiError(423, 'Section locked', { section: ['هذا القسم مقفل في الحالة الحالية'] }, 'locked')
  const editable = ['name', 'short_description', 'sector_id', 'stage', 'website', 'social_links', 'region_ids', 'investment_type_keys', 'beneficiary_group_ids', 'sdg_ids', 'organization', 'problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'revenue_model_type', 'revenue_model_desc', 'revenue_state', 'last_year_revenue_band_id', 'funding_amount', 'use_of_funds', 'prior_funding', 'prior_funding_desc', 'legal_disputes', 'legal_disputes_desc', 'license_required', 'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc', 'teaser_publication_consent', 'indicators']
  for (const k of editable) if (k in body) (o as never as Record<string, unknown>)[k] = body[k]
  if (body.data_accuracy_declared === true && !o.data_accuracy_declared_at) o.data_accuracy_declared_at = new Date().toISOString()
  if (body.data_accuracy_declared === false) o.data_accuracy_declared_at = null
  if (o.sector_id) o.sector_key = txById('sectors', o.sector_id)?.key ?? o.sector_key
  if (body.name && !String(body.name).includes(DEMO)) o.name = `${body.name} ${DEMO}`
  touch(o)
  return { data: serializeFull(o) }
})
router.get('/entrepreneur/opportunities/:uuid/checklist', ({ event, params }) => ({ data: checklistFor(ownOpp(requireEntrepreneur(event), params.uuid)) }))
router.post('/entrepreneur/opportunities/:uuid/submit', ({ event, params }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  const cl = checklistFor(o)
  if (!cl.ready) throw apiError(422, 'Checklist incomplete', { checklist: cl.items.filter((i) => !i.done).map((i) => i.label) }, 'checklist')
  if (o.status === 'draft') doTransition(o, 'new', u)
  else if (o.status === 'needs_completion') {
    o.completion_requests.forEach((c) => (c.resolved_at = c.resolved_at ?? new Date().toISOString()))
    doTransition(o, 'under_review', u, 'إعادة التقديم بعد الاستكمال')
  } else throw apiError(422, 'Cannot submit in current status', { status: ['لا يمكن الإرسال في الحالة الحالية'] })
  return { data: serializeFull(o), reference_no: o.reference_no }
})
router.post('/entrepreneur/opportunities/:uuid/withdraw', ({ event, params }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  if (['connected', 'negotiating', 'in_execution', 'operating', 'closed', 'withdrawn'].includes(o.status)) throw apiError(422, 'Cannot withdraw', { status: ['لا يمكن السحب بعد الربط'] })
  const from = o.status
  o.status = 'withdrawn'
  o.transitions.push({ id: nextTransitionId(), from_status: from, to_status: 'withdrawn', actor: { name: u.name }, created_at: new Date().toISOString() })
  touch(o)
  return { data: serializeFull(o) }
})
router.get('/entrepreneur/opportunities/:uuid/indicators', ({ event, params }) => ({ data: ownOpp(requireEntrepreneur(event), params.uuid).indicators }))
router.post('/entrepreneur/opportunities/:uuid/indicators', ({ event, params, body }) => {
  const o = ownOpp(requireEntrepreneur(event), params.uuid)
  const row = { id: randomId(), is_primary: false, is_disclosed: true, frequency: 'quarterly', ...body } as ImpactIndicator
  o.indicators.push(row)
  touch(o)
  return { data: row }
})
router.delete('/entrepreneur/opportunities/:uuid/indicators/:id', ({ event, params }) => {
  const o = ownOpp(requireEntrepreneur(event), params.uuid)
  o.indicators = o.indicators.filter((i) => i.id !== Number(params.id))
  touch(o)
  return { ok: true }
})
router.post('/entrepreneur/opportunities/:uuid/documents', ({ event, params, body }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  const existing = o.documents.find((d) => d.kind === body.kind)
  const row = { uuid: nextDocUuid(), kind: body.kind ?? 'other', title: `${body.title ?? body.file_name ?? 'مستند'} ${DEMO}`, mime: body.mime ?? 'application/pdf', size_bytes: body.size_bytes ?? 500_000, sensitivity: 'confidential' as const, status: 'pending_review' as const, reviewer_note: null, version: existing ? existing.version + 1 : 1, created_at: new Date().toISOString(), scan_result: 'pending' as DocumentResource['scan_result'] }
  if (existing) o.documents = o.documents.filter((d) => d.uuid !== existing.uuid)
  o.documents.push(row)
  o.completion_requests.forEach((c) => c.items.forEach((it) => { if (it.document_kind === row.kind) it.done = true }))
  touch(o)
  setTimeout(() => (row.scan_result = 'clean'), 1500)
  audit(u, 'document.upload', 'opportunity', o.reference_no ?? o.uuid, null, { kind: row.kind })
  return { data: row }
})
router.get('/entrepreneur/opportunities/:uuid/preview', ({ event, params }) => {
  const o = ownOpp(requireEntrepreneur(event), params.uuid)
  return { data: { card: serializeCard(o), detail: serializeDetail(o, null, 'approved_member') } }
})
router.post('/entrepreneur/opportunities/:uuid/teaser/approve', ({ event, params }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  if (!o.teaser) throw apiError(422, 'No teaser', { teaser: ['لا يوجد ملخص بعد'] })
  o.teaser.approved_by_owner_at = new Date().toISOString()
  audit(u, 'teaser.owner_approve', 'opportunity', o.reference_no ?? o.uuid)
  return { data: o.teaser }
})
router.get('/entrepreneur/access-requests', ({ event }) => {
  const uuids = myOpps(requireEntrepreneur(event)).map((o) => o.uuid)
  return { data: interests.filter((i) => uuids.includes(i.opportunity_uuid)).map((i) => serializeInterest(i, i.owner_decision === 'approved')) }
})
router.post('/entrepreneur/access-requests/:interestId/decide', ({ event, params, body }) => {
  const u = requireEntrepreneur(event)
  const uuids = myOpps(u).map((o) => o.uuid)
  const i = interests.find((x) => x.id === Number(params.interestId) && uuids.includes(x.opportunity_uuid))
  if (!i) throw apiError(404, 'Not found')
  i.owner_decision = body.decision === 'approve' ? 'approved' : 'declined'
  i.owner_decided_at = new Date().toISOString()
  if (body.decision === 'approve') {
    i.status = 'shared'
    i.next_step = 'قبول اتفاقية عدم الإفصاح للاطلاع على الملف الكامل'
    fileGrants.push({ opportunity_uuid: i.opportunity_uuid, investor_user_id: i.investor_user_id, granted_at: new Date().toISOString(), revoked_at: null })
  } else {
    i.status = 'closed'
    i.closed_at = new Date().toISOString()
    i.closed_reason = body.reason ?? 'رفض صاحب المشروع'
    i.next_step = null
  }
  audit(u, 'access_request.decide', 'interest', i.id, null, { decision: i.owner_decision })
  return { data: serializeInterest(i, i.owner_decision === 'approved') }
})
router.get('/entrepreneur/info-requests', ({ event }) => {
  const uuids = myOpps(requireEntrepreneur(event)).map((o) => o.uuid)
  return { data: infoRequests.filter((r) => uuids.includes(r.opportunity_uuid) && r.forwarded_to_owner_at).map((r) => serializeInfoRequest(r, false)) }
})
router.post('/entrepreneur/info-requests/:id/answer', ({ event, params, body }) => {
  const uuids = myOpps(requireEntrepreneur(event)).map((o) => o.uuid)
  const r = infoRequests.find((x) => x.id === Number(params.id) && uuids.includes(x.opportunity_uuid))
  if (!r) throw apiError(404, 'Not found')
  if (!body.answer) throw apiError(422, 'Validation', { answer: ['الإجابة مطلوبة'] })
  r.owner_answer = body.answer
  r.answered_at = new Date().toISOString()
  r.status = 'answered'
  return { data: serializeInfoRequest(r, false) }
})
router.get('/entrepreneur/meetings', ({ event }) => {
  const u = requireEntrepreneur(event)
  return { data: meetings.filter((m) => m.participant_user_ids.includes(u.id)).map(serializeMeeting) }
})

// ======================================================================== ADMIN
const evaluatorsPool = () => users.filter((u) => u.roles.includes('evaluator'))
const reviewersPool = () => users.filter((u) => u.roles.includes('reviewer') || u.roles.includes('super_admin'))

const evaluationPanel = (o: OpportunityRow) => {
  const assignments = o.evaluation_assignments
  const [a, b] = assignments
  const diffs = a?.evaluation && b?.evaluation ? criteria.map((c) => ({ criterion_key: c.key, delta: Math.abs((a.evaluation!.scores[c.key]?.score ?? 0) - (b.evaluation!.scores[c.key]?.score ?? 0)) })).filter((d) => d.delta >= 2) : []
  return { criteria, assignments, diffs, decision: o.evaluation_decision }
}

const candidatesFor = (o: OpportunityRow) =>
  investorUsers()
    .filter((u) => u.membership?.status === 'approved')
    .map((u) => {
      const mc = matchCriteria(u, o)
      const coi = !!u.profile?.conflicts_disclosure && u.profile.conflicts_disclosure !== 'لا يوجد' && o.sector_key === 'housing' && u.id === users[1].id && false
      return { investor: { uuid: u.uuid, name: u.name, type: u.type, city: u.profile?.city ?? null }, matched_criteria: mc, match_score: Object.values(mc).filter(Boolean).length, already_nominated: nominations.some((n) => n.investor_user_id === u.id && n.opportunity_uuid === o.uuid), coi_excluded: coi }
    })
    .filter((c) => !c.coi_excluded)
    .sort((a, b) => b.match_score - a.match_score)

router.get('/admin/dashboard', ({ event }) => {
  const u = requireStaff(event)
  const queue = opportunities.filter((o) => !['draft', 'withdrawn', 'closed'].includes(o.status)).map(queueItem)
  const eligibleCount = opportunities.filter((o) => ['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length
  const withInterest = new Set(interests.map((i) => i.opportunity_uuid)).size
  const evalFocus = opportunities.find((o) => o.evaluation_assignments.length === 2 && o.evaluation_assignments.every((a) => a.evaluation) && !o.evaluation_decision)
  const candFocus = opportunities.find((o) => o.status === 'eligible' && o.teaser?.approved_by_council_at)
  const myTasks = [
    ...(u.roles.includes('reviewer') || u.roles.includes('super_admin') ? queue.filter((q) => q.status === 'new').map((q) => ({ id: randomId(), title: `فرز أولي: ${q.name}`, href: `/app/admin/opportunities/${q.uuid}`, due_at: q.sla_due_at })) : []),
    ...(u.roles.includes('membership_officer') || u.roles.includes('super_admin') ? users.filter((x) => x.membership?.status === 'under_review').map((x) => ({ id: randomId(), title: `مراجعة عضوية: ${x.name}`, href: `/app/admin/memberships/${x.membership!.id}`, due_at: null })) : []),
    ...(u.roles.includes('matching_officer') || u.roles.includes('super_admin') ? infoRequests.filter((r) => r.status === 'sent').map((r) => ({ id: randomId(), title: `تحويل طلب معلومات: ${r.subject}`, href: '/app/admin/info-requests', due_at: null })) : []),
    ...(u.roles.includes('evaluator') ? opportunities.filter((o) => o.evaluation_assignments.some((a) => a.evaluator.id === u.id && !a.evaluation)).map((o) => ({ id: randomId(), title: `تقييم: ${o.name}`, href: `/app/admin/opportunities/${o.uuid}?tab=evaluation`, due_at: o.evaluation_due_at ?? null })) : []),
  ]
  return {
    data: {
      today: { new: opportunities.filter((o) => o.status === 'new').length, overdue: queue.filter((q) => q.is_overdue).length, pending_memberships: users.filter((x) => x.membership?.status === 'under_review').length, interest_rate: eligibleCount ? Math.round((withInterest / eligibleCount) * 100) : 0 },
      pipeline: (['new', 'under_review', 'needs_completion', 'not_eligible', 'eligible', 'connected', 'negotiating', 'in_execution', 'operating', 'withdrawn', 'closed'] as OpportunityStatus[]).map((s) => ({ status: s, count: opportunities.filter((o) => o.status === s).length })),
      membership_requests: users.filter((x) => x.membership && ['under_review', 'needs_completion', 'registered'].includes(x.membership.status)).map(serializeMembership),
      queue,
      evaluation_focus: evalFocus ? { opportunity: { uuid: evalFocus.uuid, name: evalFocus.name, reference_no: evalFocus.reference_no }, panel: evaluationPanel(evalFocus) } : null,
      candidates_focus: candFocus ? { opportunity: { uuid: candFocus.uuid, name: candFocus.name, reference_no: candFocus.reference_no }, candidates: candidatesFor(candFocus) } : null,
      my_tasks: myTasks,
    },
  }
})

router.get('/admin/opportunities', ({ event, query }) => {
  requireStaff(event)
  let list = opportunities.filter((o) => o.status !== 'draft')
  const f = (k: string) => query[`filter[${k}]`]
  if (f('status')) list = list.filter((o) => o.status === f('status'))
  if (f('sector')) list = list.filter((o) => txById('sectors', o.sector_id ?? -1)?.key === f('sector'))
  if (f('assignee')) list = list.filter((o) => String(o.assigned_reviewer_id) === f('assignee'))
  let items = list.map(queueItem)
  if (f('overdue') === '1') items = items.filter((i) => i.is_overdue)
  if (query.search) {
    const s = query.search.toLowerCase()
    items = items.filter((i) => i.name.toLowerCase().includes(s) || i.reference_no.toLowerCase().includes(s))
  }
  items.sort((a, b) => b.updated_at.localeCompare(a.updated_at))
  return paginate(items, query)
})
router.get('/admin/opportunities/:uuid', ({ event, params }) => {
  requireStaff(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  return {
    data: {
      ...serializeFull(o),
      teaser: o.teaser,
      timeline: o.transitions,
      notes: o.notes,
      completion_requests: o.completion_requests,
      evaluation: evaluationPanel(o),
      candidates: candidatesFor(o),
      nominations: nominations.filter((n) => n.opportunity_uuid === o.uuid).map((n) => serializeNomination(n, true)),
      interests: interests.filter((i) => i.opportunity_uuid === o.uuid).map((i) => serializeInterest(i, true)),
      allowed_transitions: OPPORTUNITY_TRANSITIONS[o.status],
      reviewers: reviewersPool().map((r) => ({ id: r.id, name: r.name })),
      evaluators: evaluatorsPool().map((r) => ({ id: r.id, name: r.name })),
      max_nominations: setting('opportunity.max_nominations_per_opportunity'),
    },
  }
})
router.post('/admin/opportunities/:uuid/assign', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  o.assigned_reviewer_id = Number(body.reviewer_user_id)
  touch(o)
  audit(u, 'opportunity.assign', 'opportunity', o.reference_no ?? o.uuid, null, { reviewer: o.assigned_reviewer_id })
  return { data: serializeFull(o) }
})
router.post('/admin/opportunities/:uuid/transition', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer', 'matching_officer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  doTransition(o, body.to, u, body.reason)
  return { data: serializeFull(o), timeline: o.transitions }
})
router.post('/admin/opportunities/:uuid/completion-requests', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  if (!Array.isArray(body.items) || !body.items.length) throw apiError(422, 'Validation', { items: ['أضف عنصرًا واحدًا على الأقل'] })
  const cr: CompletionRequest = { id: nextCompletionId(), due_at: body.due_at ?? daysAhead(setting<number>('sla.completion_days')), items: body.items.map((i: Record<string, unknown>) => ({ ...i, done: false })) as CompletionRequest['items'], resolved_at: null, created_at: new Date().toISOString(), requested_by: { name: u.name } }
  o.completion_requests.push(cr)
  if (OPPORTUNITY_TRANSITIONS[o.status].includes('needs_completion')) doTransition(o, 'needs_completion', u, body.reason ?? 'طلب استكمال')
  return { data: cr, opportunity: serializeFull(o) }
})
router.post('/admin/opportunities/:uuid/notes', ({ event, params, body }) => {
  const u = requireStaff(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  if (!body.body) throw apiError(422, 'Validation', { body: ['نص الملاحظة مطلوب'] })
  const n = { id: nextNoteId(), author: { name: u.name }, body: body.body, created_at: new Date().toISOString() }
  o.notes.unshift(n)
  return { data: n }
})
router.post('/admin/opportunities/:uuid/documents/:docUuid/review', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer'])
  const o = oppByUuid(params.uuid)
  const d = o?.documents.find((x) => x.uuid === params.docUuid)
  if (!o || !d) throw apiError(404, 'Not found')
  d.status = body.status
  d.reviewer_note = body.note ?? null
  audit(u, 'document.review', 'document', d.uuid, null, { status: d.status })
  return { data: d }
})
router.post('/admin/opportunities/:uuid/evaluation/assign', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  const ids: number[] = (body.evaluator_ids ?? []).map(Number)
  if (ids.length !== 2 || ids[0] === ids[1]) throw apiError(422, 'Exactly two evaluators required', { evaluator_ids: ['يجب إسناد مقيّمَين مختلفَين (EVL-02)'] })
  o.evaluation_assignments = ids.map((id, idx) => ({ id: o.transitions.length * 10 + idx + 1, evaluator: { id, name: userById(id)?.name ?? '—' }, status: 'assigned', coi_declared_at: null, coi_has_conflict: false, evaluation: null }))
  if (o.status === 'new') doTransition(o, 'under_review', u, 'إسناد التقييم')
  touch(o)
  return { data: evaluationPanel(o) }
})
router.post('/admin/evaluation-assignments/:id/coi', ({ event, params, body }) => {
  requireStaff(event, ['evaluator'])
  for (const o of opportunities) {
    const a = o.evaluation_assignments.find((x) => x.id === Number(params.id))
    if (a) {
      a.coi_declared_at = new Date().toISOString()
      a.coi_has_conflict = !!body.has_conflict
      a.status = body.has_conflict ? 'withdrawn' : 'coi_declared'
      return { data: a }
    }
  }
  throw apiError(404, 'Not found')
})
router.get('/admin/evaluation-assignments/:id/score', ({ event, params }) => {
  requireStaff(event, ['evaluator'])
  for (const o of opportunities) {
    const a = o.evaluation_assignments.find((x) => x.id === Number(params.id))
    if (a) return { data: { assignment: a, criteria } }
  }
  throw apiError(404, 'Not found')
})
router.post('/admin/evaluation-assignments/:id/score', ({ event, params, body }) => {
  const u = requireStaff(event, ['evaluator'])
  for (const o of opportunities) {
    const a = o.evaluation_assignments.find((x) => x.id === Number(params.id))
    if (a) {
      const scores = body.scores as Record<string, { score: number; note?: string }>
      const missing = criteria.filter((c) => !scores?.[c.key]?.score)
      if (missing.length) throw apiError(422, 'All criteria required', { scores: missing.map((c) => c.label_ar) })
      const axisTotals = { readiness: 0, feasibility: 0, impact: 0 }
      const axisWeights = { readiness: 0, feasibility: 0, impact: 0 }
      let overall = 0
      for (const c of criteria) {
        axisTotals[c.axis] += scores[c.key].score * c.weight
        axisWeights[c.axis] += c.weight
        overall += scores[c.key].score * c.weight
      }
      ;(Object.keys(axisTotals) as (keyof typeof axisTotals)[]).forEach((k) => (axisTotals[k] = Number((axisTotals[k] / axisWeights[k]).toFixed(1))))
      a.evaluation = { id: randomId(), assignment_id: a.id, criteria_version: 1, scores, axis_totals: axisTotals, overall: Number(overall.toFixed(2)), recommendation: body.recommendation, comment: body.comment ?? null, submitted_at: new Date().toISOString() }
      a.status = 'scored'
      touch(o)
      audit(u, 'evaluation.score', 'opportunity', o.reference_no ?? o.uuid)
      return { data: evaluationPanel(o) }
    }
  }
  throw apiError(404, 'Not found')
})
router.get('/admin/opportunities/:uuid/evaluation', ({ event, params }) => {
  requireStaff(event)
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  return { data: evaluationPanel(o) }
})
router.post('/admin/opportunities/:uuid/evaluation/decide', ({ event, params, body }) => {
  const u = requireStaff(event, ['reviewer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  if (!body.result) throw apiError(422, 'Validation', { result: ['النتيجة مطلوبة'] })
  o.evaluation_result = body.result
  o.evaluation_decision = { result: body.result, conditions: body.conditions ?? null, reasons: body.reasons ?? null, decided_by: { name: u.name }, decided_at: new Date().toISOString() }
  touch(o)
  audit(u, 'evaluation.decide', 'opportunity', o.reference_no ?? o.uuid, null, { result: body.result })
  return { data: evaluationPanel(o), opportunity: serializeFull(o) }
})
router.post('/admin/opportunities/:uuid/teaser', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer', 'reviewer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  if (!body.summary_ar) throw apiError(422, 'Validation', { summary_ar: ['الملخص العربي مطلوب'] })
  const version = (o.teaser?.version ?? 0) + 1
  o.teaser = { summary_ar: body.summary_ar, summary_en: body.summary_en ?? null, highlights: body.highlights ?? [], version, approved_by_owner_at: null, approved_by_council_at: null }
  touch(o)
  audit(u, 'teaser.save', 'opportunity', o.reference_no ?? o.uuid)
  return { data: o.teaser }
})
router.post('/admin/opportunities/:uuid/teaser/approve', ({ event, params }) => {
  const u = requireStaff(event, ['matching_officer'])
  const o = oppByUuid(params.uuid)
  if (!o?.teaser) throw apiError(404, 'No teaser')
  o.teaser.approved_by_council_at = new Date().toISOString()
  touch(o)
  audit(u, 'teaser.council_approve', 'opportunity', o.reference_no ?? o.uuid)
  return { data: o.teaser }
})
router.get('/admin/opportunities/:uuid/candidates', ({ event, params }) => {
  requireStaff(event, ['matching_officer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  return { data: candidatesFor(o) }
})
router.post('/admin/opportunities/:uuid/nominations', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  const max = setting<number>('opportunity.max_nominations_per_opportunity')
  const existing = nominations.filter((n) => n.opportunity_uuid === o.uuid).length
  const ids: string[] = body.investor_user_ids ?? []
  if (existing + ids.length > max) throw apiError(422, 'Max nominations', { investor_user_ids: [`الحد الأقصى ${max} ترشيحات لكل فرصة`] })
  const created = ids.map((uuid) => {
    const inv = users.find((x) => x.uuid === uuid)!
    const mc = matchCriteria(inv, o)
    const row = { id: nextNominationId(), investor_user_id: inv.id, opportunity_uuid: o.uuid, opportunity: null as never, matched_criteria: mc, match_score: Object.values(mc).filter(Boolean).length, rationale: body.rationale ?? '', teaser_version: o.teaser?.version ?? 1, sent_at: new Date().toISOString(), response: null, responded_at: null }
    nominations.unshift(row)
    audit(u, 'nomination.sent', 'opportunity', o.reference_no ?? o.uuid, null, { investor: inv.uuid })
    return serializeNomination(row, true)
  })
  return { data: created }
})
router.get('/admin/interests', ({ event, query }) => {
  requireStaff(event)
  return paginate(interests.map((i) => ({ ...serializeInterest(i, true), allowed_transitions: INTEREST_TRANSITIONS[i.status] })), query)
})
router.post('/admin/interests/:id/transition', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer'])
  const i = interests.find((x) => x.id === Number(params.id))
  if (!i) throw apiError(404, 'Not found')
  if (!INTEREST_TRANSITIONS[i.status].includes(body.to)) throw apiError(422, 'Transition not allowed', { to: ['الانتقال غير مسموح'] })
  i.status = body.to
  if (body.to === 'connected') {
    i.next_step = 'جدولة اجتماع التعارف'
    const o = oppByUuid(i.opportunity_uuid)!
    if (o.status === 'eligible') doTransition(o, 'connected', u, 'موافقة مزدوجة')
  }
  if (body.to === 'meeting') i.next_step = 'اجتماع التعارف مجدول'
  if (body.to === 'closed') { i.closed_at = new Date().toISOString(); i.next_step = null; i.closed_reason = body.reason ?? 'أغلقه فريق المجلس' }
  audit(u, 'interest.transition', 'interest', i.id, null, { to: body.to })
  return { data: { ...serializeInterest(i, true), allowed_transitions: INTEREST_TRANSITIONS[i.status] } }
})
router.get('/admin/info-requests', ({ event, query }) => {
  requireStaff(event)
  return paginate(infoRequests.map((r) => serializeInfoRequest(r, true)), query)
})
router.post('/admin/info-requests/:id/forward', ({ event, params }) => {
  requireStaff(event, ['matching_officer'])
  const r = infoRequests.find((x) => x.id === Number(params.id))
  if (!r) throw apiError(404, 'Not found')
  r.forwarded_to_owner_at = new Date().toISOString()
  r.status = 'processing'
  return { data: serializeInfoRequest(r, true) }
})
router.post('/admin/info-requests/:id/reply', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer'])
  const r = infoRequests.find((x) => x.id === Number(params.id))
  if (!r) throw apiError(404, 'Not found')
  r.owner_answer = body.answer
  r.answered_at = new Date().toISOString()
  r.status = 'answered'
  r.handled_by = { name: u.name }
  return { data: serializeInfoRequest(r, true) }
})
router.get('/admin/meetings', ({ event, query }) => {
  requireStaff(event)
  return paginate(meetings.map(serializeMeeting), query)
})
router.post('/admin/meetings', ({ event, body }) => {
  const u = requireStaff(event, ['matching_officer', 'impact_officer'])
  const o = oppByUuid(body.opportunity_uuid)
  if (!o) throw apiError(422, 'Validation', { opportunity_uuid: ['الفرصة مطلوبة'] })
  if (!body.title || !body.starts_at) throw apiError(422, 'Validation', { title: body.title ? [] : ['العنوان مطلوب'], starts_at: body.starts_at ? [] : ['وقت البداية مطلوب'] })
  const interest = body.interest_id ? interests.find((i) => i.id === Number(body.interest_id)) : null
  const investor = interest ? userById(interest.investor_user_id) : null
  const owner = userById(o.owner_user_id)!
  const row = { id: nextMeetingId(), opportunity_uuid: o.uuid, interest_id: interest?.id ?? null, opportunity: null as never, title: body.title, starts_at: body.starts_at, ends_at: body.ends_at ?? body.starts_at, location: body.location ?? null, video_url: body.video_url ?? null, status: 'scheduled' as const, participants: [...(investor ? [{ name: investor.name, role: 'investor' as const }] : []), { name: owner.name, role: 'owner' as const }, { name: u.name, role: 'council' as const }], participant_user_ids: [...(investor ? [investor.id] : []), owner.id, u.id], actions: [] }
  meetings.unshift(row)
  if (interest && interest.status === 'connected') { interest.status = 'meeting'; interest.next_step = 'اجتماع التعارف مجدول' }
  return { data: serializeMeeting(row) }
})
router.patch('/admin/meetings/:id', ({ event, params, body }) => {
  requireStaff(event, ['matching_officer', 'impact_officer'])
  const m = meetings.find((x) => x.id === Number(params.id))
  if (!m) throw apiError(404, 'Not found')
  Object.assign(m, body)
  return { data: serializeMeeting(m) }
})
router.post('/admin/meetings/:id/minutes', ({ event, params, body }) => {
  requireStaff(event, ['matching_officer', 'impact_officer'])
  const m = meetings.find((x) => x.id === Number(params.id))
  if (!m) throw apiError(404, 'Not found')
  m.minutes = body.minutes
  m.status = 'held'
  m.actions = body.actions ?? []
  return { data: serializeMeeting(m) }
})
router.get('/admin/memberships', ({ event, query }) => {
  requireStaff(event, ['membership_officer'])
  let list = investorUsers().map(serializeMembership)
  if (query['filter[status]']) list = list.filter((m) => m.status === query['filter[status]'])
  return paginate(list, query)
})
router.get('/admin/memberships/:id', ({ event, params }) => {
  requireStaff(event, ['membership_officer'])
  const u = investorUsers().find((x) => x.membership?.id === Number(params.id))
  if (!u) throw apiError(404, 'Not found')
  return { data: { ...serializeMembership(u), allowed_transitions: MEMBERSHIP_TRANSITIONS[u.membership!.status] } }
})
router.post('/admin/memberships/:id/transition', ({ event, params, body }) => {
  const staff = requireStaff(event, ['membership_officer'])
  const u = investorUsers().find((x) => x.membership?.id === Number(params.id))
  if (!u?.membership) throw apiError(404, 'Not found')
  const to = body.to as MembershipStatus
  if (!MEMBERSHIP_TRANSITIONS[u.membership.status].includes(to)) throw apiError(422, 'Transition not allowed', { to: ['الانتقال غير مسموح'] })
  const from = u.membership.status
  u.membership = { ...u.membership, status: to, decision_note: body.note ?? u.membership.decision_note, reviewed_at: new Date().toISOString(), approved_at: to === 'approved' ? new Date().toISOString() : u.membership.approved_at, terms_accepted_at: to === 'approved' ? u.membership.terms_accepted_at ?? new Date().toISOString() : u.membership.terms_accepted_at, suspended_reason: to === 'suspended' ? body.note : null }
  audit(staff, 'membership.transition', 'membership', u.membership.id, { status: from }, { status: to })
  return { data: { ...serializeMembership(u), allowed_transitions: MEMBERSHIP_TRANSITIONS[to] } }
})
router.get('/admin/users', ({ event, query }) => {
  requireStaff(event, ['super_admin'])
  let list = users.map((u) => ({ id: u.id, uuid: u.uuid, name: u.name, email: u.email, type: u.type, roles: u.roles, status: u.status, two_factor_enabled: u.two_factor_enabled, last_login_at: u.last_login_at }))
  if (query['filter[type]']) list = list.filter((u) => u.type === query['filter[type]'])
  return paginate(list, query)
})
router.post('/admin/users', ({ event, body }) => {
  const staff = requireStaff(event, ['super_admin'])
  if (!body.name || !body.email) throw apiError(422, 'Validation', { name: body.name ? [] : ['الاسم مطلوب'], email: body.email ? [] : ['البريد مطلوب'] })
  const u: MockUser = { id: nextUserId(), uuid: `u-${Date.now().toString(36)}`, type: 'staff', name: `${body.name} ${DEMO}`, email: body.email, phone: body.phone ?? '+9665000000XX', password: 'password', locale: 'ar', roles: body.roles ?? [], status: 'active', email_verified_at: new Date().toISOString(), phone_verified_at: new Date().toISOString(), two_factor_enabled: false, last_login_at: null, created_at: new Date().toISOString() }
  users.push(u)
  audit(staff, 'user.create', 'user', u.uuid)
  return { data: { id: u.id, uuid: u.uuid, name: u.name, email: u.email, type: u.type, roles: u.roles, status: u.status, two_factor_enabled: false, last_login_at: null } }
})
router.patch('/admin/users/:id', ({ event, params, body }) => {
  const staff = requireStaff(event, ['super_admin'])
  const u = userById(Number(params.id))
  if (!u) throw apiError(404, 'Not found')
  if (body.roles) u.roles = body.roles
  if (body.status) u.status = body.status
  audit(staff, 'user.update', 'user', u.uuid, null, body)
  return { data: { id: u.id, uuid: u.uuid, name: u.name, email: u.email, type: u.type, roles: u.roles, status: u.status, two_factor_enabled: u.two_factor_enabled, last_login_at: u.last_login_at } }
})
router.get('/admin/settings', ({ event }) => {
  requireStaff(event, ['super_admin'])
  return { data: settings }
})
router.put('/admin/settings', ({ event, body }) => {
  const staff = requireStaff(event, ['super_admin'])
  for (const [k, v] of Object.entries(body.values ?? {})) {
    const s = settings.find((x) => x.key === k)
    if (s) { audit(staff, 'settings.update', 'setting', k, { value: s.value }, { value: v }); s.value = v }
  }
  return { data: settings }
})
router.post('/admin/taxonomies/:group', ({ event, params, body }) => {
  requireStaff(event, ['super_admin'])
  const g = params.group as TaxonomyGroup
  if (!taxonomies[g]) throw apiError(404, 'Unknown group')
  if (!body.key || !body.label_ar) throw apiError(422, 'Validation', { key: body.key ? [] : ['المعرّف مطلوب'], label_ar: body.label_ar ? [] : ['التسمية العربية مطلوبة'] })
  const t = { id: nextTermId(), group: g, key: body.key, label_ar: body.label_ar, label_en: body.label_en ?? body.label_ar, sort: body.sort ?? taxonomies[g].length + 1, is_active: body.is_active ?? true }
  taxonomies[g].push(t)
  return { data: t }
})
router.patch('/admin/taxonomies/:group/:id', ({ event, params, body }) => {
  requireStaff(event, ['super_admin'])
  const t = taxonomies[params.group as TaxonomyGroup]?.find((x) => x.id === Number(params.id))
  if (!t) throw apiError(404, 'Not found')
  Object.assign(t, body)
  return { data: t }
})
router.delete('/admin/taxonomies/:group/:id', ({ event, params }) => {
  requireStaff(event, ['super_admin'])
  const g = params.group as TaxonomyGroup
  const t = taxonomies[g]?.find((x) => x.id === Number(params.id))
  if (!t) throw apiError(404, 'Not found')
  t.is_active = false
  return { ok: true }
})
router.get('/admin/evaluation-criteria', ({ event }) => {
  requireStaff(event)
  return { data: criteria, version: 1 }
})
router.post('/admin/evaluation-criteria', ({ event, body }) => {
  requireStaff(event, ['super_admin'])
  const c = { id: nextCriterionId(), axis: body.axis ?? 'readiness', key: body.key ?? `c_${Date.now()}`, label_ar: body.label_ar ?? 'معيار جديد', label_en: body.label_en ?? 'New criterion', weight: Number(body.weight ?? 0.1), scale_descriptions: body.scale_descriptions ?? { '1': '', '2': '', '3': '', '4': '', '5': '' }, version: 1, is_active: true }
  criteria.push(c)
  return { data: c }
})
router.patch('/admin/evaluation-criteria/:id', ({ event, params, body }) => {
  requireStaff(event, ['super_admin'])
  const c = criteria.find((x) => x.id === Number(params.id))
  if (!c) throw apiError(404, 'Not found')
  Object.assign(c, body)
  return { data: c }
})

const reports: Record<ReportKey, () => Record<string, unknown>> = {
  registrations_by_type: () => ({ kind: 'bars', series: ['investor_individual', 'investor_institutional', 'entrepreneur_individual', 'entrepreneur_entity', 'staff'].map((t) => ({ label: t, value: users.filter((u) => u.type === t).length })) }),
  memberships_by_status: () => ({ kind: 'bars', series: ['registered', 'under_review', 'needs_completion', 'approved', 'suspended', 'rejected'].map((s) => ({ label: s, value: users.filter((u) => u.membership?.status === s).length })) }),
  opportunities_funnel: () => ({ kind: 'funnel', series: [{ label: 'submitted', value: opportunities.filter((o) => o.submitted_at).length }, { label: 'under_review', value: opportunities.filter((o) => ['under_review', 'eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length }, { label: 'eligible', value: opportunities.filter((o) => ['eligible', 'connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length }, { label: 'connected', value: opportunities.filter((o) => ['connected', 'negotiating', 'in_execution', 'operating'].includes(o.status)).length }, { label: 'in_execution', value: opportunities.filter((o) => ['in_execution', 'operating'].includes(o.status)).length }] }),
  opportunities_by_sector_stage_region: () => ({ kind: 'bars', series: taxonomies.sectors.map((s) => ({ label: s.label_ar, value: opportunities.filter((o) => o.sector_id === s.id).length })).filter((x) => x.value > 0) }),
  first_time_completion_rate: () => ({ kind: 'number', series: [{ label: 'rate', value: Math.round((opportunities.filter((o) => o.submitted_at && !o.completion_requests.length).length / Math.max(1, opportunities.filter((o) => o.submitted_at).length)) * 100) }], unit: '%' }),
  avg_review_time: () => ({ kind: 'number', series: [{ label: 'days', value: 3.4 }], unit: 'days' }),
  avg_evaluation_time: () => ({ kind: 'number', series: [{ label: 'days', value: 8.1 }], unit: 'days' }),
  overdue_items: () => ({ kind: 'bars', series: [{ label: 'review', value: opportunities.filter((o) => o.status === 'new' && o.review_due_at && new Date(o.review_due_at) < new Date()).length }, { label: 'completion', value: opportunities.filter((o) => o.status === 'needs_completion' && o.completion_due_at && new Date(o.completion_due_at) < new Date()).length }, { label: 'evaluation', value: opportunities.filter((o) => o.status === 'under_review' && o.evaluation_due_at && new Date(o.evaluation_due_at) < new Date()).length }] }),
  eligibility_rate: () => ({ kind: 'number', series: [{ label: 'rate', value: Math.round((opportunities.filter((o) => o.evaluation_result && o.evaluation_result !== 'not_eligible_now').length / Math.max(1, opportunities.filter((o) => o.evaluation_result || o.status === 'not_eligible').length)) * 100) }], unit: '%' }),
  interest_rate: () => ({ kind: 'number', series: [{ label: 'rate', value: Math.round((new Set(interests.map((i) => i.opportunity_uuid)).size / Math.max(1, opportunities.filter((o) => o.teaser?.approved_by_council_at).length)) * 100) }], unit: '%' }),
  connection_rate: () => ({ kind: 'number', series: [{ label: 'rate', value: Math.round((interests.filter((i) => ['connected', 'meeting'].includes(i.status)).length / Math.max(1, interests.length)) * 100) }], unit: '%' }),
  acquisition_channels: () => ({ kind: 'bars', series: taxonomies.how_heard.map((h) => ({ label: h.label_ar, value: users.filter((u) => u.profile?.how_heard_id === h.id).length })) }),
}
router.get('/admin/reports/:key', ({ event, params }) => {
  requireStaff(event, ['reports_viewer', 'impact_officer', 'membership_officer'])
  const fn = reports[params.key as ReportKey]
  if (!fn) throw apiError(404, 'Unknown report')
  return { data: { key: params.key, generated_at: new Date().toISOString(), ...fn() } }
})
router.get('/admin/reports/:key/export.xlsx', ({ event, params }) => {
  const u = requireStaff(event, ['reports_viewer'])
  audit(u, 'report.export', 'report', params.key)
  return { ok: true, url: `/mock-files/report-${params.key}.xlsx`, note: 'وضع المحاكاة: لا يُنشأ ملف فعلي' }
})
router.get('/admin/audit-logs', ({ event, query }) => {
  requireStaff(event, ['super_admin'])
  let list = auditLogs
  if (query['filter[action]']) list = list.filter((l) => l.action.includes(query['filter[action]']))
  if (query['filter[type]']) list = list.filter((l) => l.auditable_type === query['filter[type]'])
  if (query['filter[user]']) list = list.filter((l) => (l.user?.name ?? '').includes(query['filter[user]']))
  return paginate(list, query)
})
router.get('/admin/content/:kind', ({ event, params }) => {
  requireStaff(event, ['super_admin'])
  if (params.kind === 'faqs') return { data: faqs.map((f) => ({ id: f.id, kind: 'faqs', slug: `faq-${f.id}`, title_ar: f.question, body_ar: f.answer, is_published: true, published_at: daysAgo(30), category: f.category })) }
  return { data: content.filter((c) => c.kind === params.kind) }
})
router.post('/admin/content/:kind', ({ event, params, body }) => {
  requireStaff(event, ['super_admin'])
  if (!body.title_ar) throw apiError(422, 'Validation', { title_ar: ['العنوان مطلوب'] })
  if (params.kind === 'faqs') {
    const f = { id: nextContentId(), category: body.category ?? 'general', question: body.title_ar, answer: body.body_ar ?? '' }
    faqs.push(f)
    return { data: { id: f.id, kind: 'faqs', slug: `faq-${f.id}`, title_ar: f.question, body_ar: f.answer, is_published: true, published_at: new Date().toISOString(), category: f.category } }
  }
  const c = { id: nextContentId(), kind: params.kind as never, slug: body.slug ?? `item-${Date.now()}`, title_ar: body.title_ar, title_en: body.title_en ?? null, body_ar: body.body_ar ?? '', body_en: body.body_en ?? null, is_published: !!body.is_published, published_at: body.is_published ? new Date().toISOString() : null, starts_at: body.starts_at ?? null }
  content.push(c)
  return { data: c }
})
router.patch('/admin/content/:kind/:id', ({ event, params, body }) => {
  requireStaff(event, ['super_admin'])
  if (params.kind === 'faqs') {
    const f = faqs.find((x) => x.id === Number(params.id))
    if (!f) throw apiError(404, 'Not found')
    if (body.title_ar) f.question = body.title_ar
    if (body.body_ar) f.answer = body.body_ar
    if (body.category) f.category = body.category
    return { data: { id: f.id, kind: 'faqs', slug: `faq-${f.id}`, title_ar: f.question, body_ar: f.answer, is_published: true, published_at: daysAgo(30), category: f.category } }
  }
  const c = content.find((x) => x.id === Number(params.id))
  if (!c) throw apiError(404, 'Not found')
  Object.assign(c, body)
  if (body.is_published && !c.published_at) c.published_at = new Date().toISOString()
  return { data: c }
})
router.get('/admin/contact-messages', ({ event, query }) => {
  requireStaff(event)
  return paginate(contactMessages, query)
})

// ======================================================================== SYSTEM
router.get('/health', () => ({ status: 'ok', db: 'mock', redis: 'mock', storage: 'mock', queue_lag: 0 }))
router.get('/version', () => ({ version: '0.1.0-mock', spec: '3.0' }))

export { currentUser, COOKIE }
