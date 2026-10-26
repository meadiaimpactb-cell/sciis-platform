/**
 * Mock API handlers — implements the subset of SPEC §6 that the web UI consumes.
 * Shapes mirror Laravel API Resources. All data is synthetic (SPEC §11).
 */
import { getCookie, setCookie, deleteCookie } from 'h3'
import { MockRouter, apiError, paginate, type Handler } from './router'
import type { ImpactIndicator, DocumentResource, CompletionRequest, Nomination } from '~/types/api'
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
    o.locked_sections = ['basics', 'organization', 'team', 'project', 'impact', 'market', 'financials', 'motivation']
  }
  o.transitions.push({ id: nextTransitionId(), from_status: from, to_status: to, actor: { name: actor.name }, reason: reason ?? null, created_at: new Date().toISOString() })
  audit(actor, 'opportunity.transition', 'opportunity', o.reference_no ?? o.uuid, { status: from }, { status: to })
}

export const router = new MockRouter()

// ======================================================================== AUTH
/**
 * The web sends the Laravel `UpdateProfileRequest` shape (basics / interests{*_ids} / experience / declarations);
 * the mock stores the flat row — map taxonomy ids back to the enum keys for stages / investment types.
 */
const flattenProfilePayload = (body: Record<string, unknown> | undefined): Record<string, unknown> => {
  if (!body || !('basics' in body || 'interests' in body || 'experience' in body || 'declarations' in body)) return body ?? {}
  const b = body as { basics?: Record<string, unknown>; interests?: Record<string, unknown>; experience?: Record<string, unknown>; declarations?: Record<string, unknown> }
  const i = b.interests ?? {}
  const keysOf = (group: TaxonomyGroup, ids: unknown) => (Array.isArray(ids) ? ids.map((id) => txById(group, Number(id))?.key).filter((k): k is string => !!k) : [])
  const out: Record<string, unknown> = { ...(b.basics ?? {}), ...(b.experience ?? {}), ...(b.declarations ?? {}) }
  if ('sector_ids' in i) out.sectors = (i.sector_ids as number[] | null) ?? []
  if ('stage_ids' in i) out.stages = keysOf('project_stages', i.stage_ids)
  if ('investment_type_ids' in i) out.investment_types = keysOf('investment_types', i.investment_type_ids)
  if ('region_ids' in i) out.regions = (i.region_ids as number[] | null) ?? []
  if ('cause_ids' in i) out.causes = (i.cause_ids as number[] | null) ?? []
  for (const k of ['risk_appetite', 'investment_range_id', 'annual_investment_band_id']) if (k in i) out[k] = i[k]
  return out
}
/** Laravel `NominationResource.matched_criteria` = `[{key,label}]` (keys sector|stage|region|investment_type|funding_range). */
const nominationContract = (n: Nomination): Nomination => {
  const mc = n.matched_criteria
  if (Array.isArray(mc)) return n
  const keyMap: Record<string, string> = { sector: 'sector', stage: 'stage', region: 'region', type: 'investment_type', range: 'funding_range' }
  const labels: Record<string, string> = { sector: 'القطاع', stage: 'المرحلة', region: 'المنطقة', investment_type: 'نوع الاستثمار', funding_range: 'نطاق التمويل' }
  return { ...n, matched_criteria: Object.entries(mc ?? {}).filter(([, v]) => v).map(([k]) => ({ key: keyMap[k] ?? k, label: labels[keyMap[k] ?? k] ?? k })) }
}

router.post('/auth/register', ({ event, body }) => {
  const errors: Record<string, string[]> = {}
  if (!body.name) errors.name = ['الاسم مطلوب']
  if (!body.email || !/^[^@]+@[^@]+\.[^@]+$/.test(body.email)) errors.email = ['البريد غير صحيح']
  if (userByEmail(body.email ?? '')) errors.email = ['البريد مسجّل مسبقًا']
  if (!body.password || !/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{10,}$/.test(String(body.password))) errors.password = ['10 أحرف على الأقل مع حروف كبيرة وصغيرة ورقم']
  if (!body.phone) errors.phone = ['رقم الجوال مطلوب']
  else if (!/^\+[1-9]\d{7,14}$/.test(String(body.phone))) errors.phone = ['صيغة الرقم الدولية مطلوبة (+9665xxxxxxxx)']
  if (!Array.isArray(body.consents) || !body.consents.includes('privacy_policy') || !body.consents.includes('terms')) errors.consents = ['الموافقة على سياسة الخصوصية والشروط مطلوبة']
  if (['investor_institutional', 'entrepreneur_entity'].includes(body.type) && !(body.organization_name ?? body.organization?.name)) errors.organization_name = ['اسم الجهة مطلوب']
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
    organization: (body.organization_name ?? body.organization?.name) ? { uuid: `org-${Date.now().toString(36)}`, name: `${body.organization_name ?? body.organization?.name} ${DEMO}`, institution_type: body.organization?.institution_type, legal_form: body.organization?.legal_form, city: body.organization?.city, cr_number: body.organization?.cr_number } : null,
    preferred_contact: body.preferred_contact ?? 'email',
  }
  if (u.type.startsWith('investor')) {
    const p = makeEmptyProfile()
    Object.assign(p, flattenProfilePayload(body.profile), body.preferred_contact ? { preferred_contact: body.preferred_contact } : {})
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
  setCookie(event, COOKIE, token, { path: '/', sameSite: 'lax', httpOnly: false })
  audit(u, 'user.register', 'user', u.uuid)
  // Laravel: { data: UserResource, message }
  return { data: serializeMe(u), message: 'تم إنشاء الحساب — أرسلنا رمزي التحقق إلى بريدك وجوالك.' }
})

// Laravel: { data: { user, two_factor_required } } — the SPA always gets a cookie session; a 2FA-enabled user is
// logged in but flagged pending until POST /auth/2fa/challenge succeeds (mirrors the API's `2fa.passed` session flag).
router.post('/auth/login', ({ event, body }) => {
  const u = userByEmail(body.email ?? '')
  if (!u || u.password !== body.password) throw apiError(422, 'Invalid credentials', { email: ['البريد أو كلمة المرور غير صحيحة'] }, 'invalid_credentials')
  if (u.status === 'suspended') throw apiError(403, 'Account suspended', undefined, 'account_suspended')
  const token = newToken()
  sessions.set(token, u.id)
  if (u.two_factor_enabled) pendingTwoFactor.set(token, u.id)
  u.last_login_at = new Date().toISOString()
  setCookie(event, COOKIE, token, { path: '/', sameSite: 'lax', httpOnly: false })
  audit(u, 'user.login', 'user', u.uuid)
  return { data: { user: serializeMe(u), two_factor_required: u.two_factor_enabled } }
})

router.post('/auth/2fa/challenge', ({ event, body }) => {
  const u = requireUser(event)
  const token = getCookie(event, COOKIE) ?? ''
  if (!u.two_factor_enabled) throw apiError(422, '2FA not enabled', undefined, 'two_factor_not_enabled')
  const code = String(body.code ?? '')
  if (!/^\d{6}$/.test(code) && !/^[A-Z0-9]{10}$/i.test(code)) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'two_factor_invalid')
  pendingTwoFactor.delete(token)
  return { data: { two_factor_passed: true } }
})

router.post('/auth/2fa/enable', ({ event }) => {
  const u = requireUser(event)
  if (u.two_factor_enabled) throw apiError(422, '2FA already enabled', undefined, 'two_factor_enabled')
  return { data: { secret: 'MOCKSECRET234567ABCDEFGHIJKLMNOP', otpauth_url: `otpauth://totp/SCIIS:${encodeURIComponent(u.email)}?secret=MOCKSECRET234567ABCDEFGHIJKLMNOP&issuer=SCIIS&algorithm=SHA1&digits=6&period=30` } }
})
router.post('/auth/2fa/confirm', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'two_factor_invalid')
  u.two_factor_enabled = true
  const codes = Array.from({ length: 8 }, () => Math.random().toString(36).slice(2, 12).toUpperCase().padEnd(10, 'X'))
  return { data: { recovery_codes: codes } }
})
// Laravel: POST /auth/2fa/disable {password, code} (legacy alias DELETE /auth/2fa) — current password AND a TOTP / recovery code.
const disableTwoFactor: Handler = ({ event, body }) => {
  const u = requireUser(event)
  if (u.type === 'staff') throw apiError(403, '2FA mandatory for staff', undefined, 'two_factor_mandatory')
  if (!u.two_factor_enabled) throw apiError(422, '2FA not enabled', undefined, 'two_factor_not_enabled')
  if (!body?.password || body.password !== u.password) throw apiError(422, 'Invalid password', { password: ['كلمة المرور غير صحيحة'] }, 'validation')
  const code = String(body?.code ?? '')
  if (!/^\d{6}$/.test(code) && !/^[A-Z0-9]{10}$/i.test(code)) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'two_factor_invalid')
  u.two_factor_enabled = false
  return { message: 'تم إلغاء التحقق بخطوتين' }
}
router.post('/auth/2fa/disable', disableTwoFactor)
router.delete('/auth/2fa', disableTwoFactor)

router.post('/auth/logout', ({ event }) => {
  const token = getCookie(event, COOKIE)
  if (token) sessions.delete(token)
  deleteCookie(event, COOKIE, { path: '/' })
  return { ok: true }
})

// Laravel: { message } only — the web re-fetches /me afterwards.
router.post('/auth/verify-email', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'otp_invalid')
  u.email_verified_at = new Date().toISOString()
  return { message: 'تم التحقق من البريد الإلكتروني' }
})
router.post('/auth/verify-phone', ({ event, body }) => {
  const u = requireUser(event)
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'otp_invalid')
  u.phone_verified_at = new Date().toISOString()
  return { message: 'تم التحقق من رقم الجوال' }
})
router.post('/auth/resend-otp', () => ({ ok: true, expires_in: 300 }))
// Standard OTP contract — POST /auth/otp/send {channel: email|phone} and POST /auth/verify-otp {channel, code}.
router.post('/auth/otp/send', ({ event, body }) => {
  const u = requireUser(event)
  const channel = body.channel === 'sms' ? 'phone' : body.channel
  if (channel !== 'email' && channel !== 'phone') throw apiError(422, 'Validation', { channel: ['القناة غير صالحة'] }, 'validation')
  if ((channel === 'email' && u.email_verified_at) || (channel === 'phone' && u.phone_verified_at)) throw apiError(422, 'Already verified', undefined, 'already_verified')
  return { data: { channel, expires_in: 600, resend_in: 60 }, message: 'تم إرسال الرمز' }
})
router.post('/auth/verify-otp', ({ event, body }) => {
  const u = requireUser(event)
  const channel = body.channel === 'sms' ? 'phone' : body.channel
  if (channel !== 'email' && channel !== 'phone') throw apiError(422, 'Validation', { channel: ['القناة غير صالحة'] }, 'validation')
  if (!/^\d{6}$/.test(String(body.code ?? ''))) throw apiError(422, 'Invalid code', { code: ['رمز التحقق غير صحيح'] }, 'otp_invalid')
  if (channel === 'email') u.email_verified_at = new Date().toISOString()
  else u.phone_verified_at = new Date().toISOString()
  return { data: { channel, verified: true, email_verified: !!u.email_verified_at, phone_verified: !!u.phone_verified_at, fully_verified: !!u.email_verified_at && !!u.phone_verified_at }, message: channel === 'email' ? 'تم التحقق من البريد الإلكتروني' : 'تم التحقق من رقم الجوال' }
})
router.post('/auth/forgot-password', () => ({ ok: true }))
router.post('/auth/reset-password', ({ body }) => {
  if (!body.password || String(body.password).length < 8) throw apiError(422, 'Validation', { password: ['8 أحرف على الأقل'] })
  return { ok: true }
})

// Laravel: UserResource + session flags (`two_factor_passed`, `unread_notifications`) and both verification shapes.
router.get('/me', ({ event }) => {
  const u = requireUser(event)
  const me = serializeMe(u)
  return { data: { ...me, email_verified: !!me.email_verified_at, phone_verified: !!me.phone_verified_at, two_factor_passed: !pendingTwoFactor.has(getCookie(event, COOKIE) ?? ''), unread_notifications: 0 } }
})
router.patch('/me', ({ event, body }) => {
  const u = requireUser(event)
  if (body.name) u.name = body.name
  if (body.locale) u.locale = body.locale
  if (body.preferred_contact) u.preferred_contact = body.preferred_contact
  return { data: serializeMe(u) }
})
// Portal header bell + account page (mirrors MeController: notifications meta.unread_count, avatar, password).
router.get('/me/notifications', ({ event }) => {
  requireUser(event)
  return { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, unread_count: 0 } }
})
router.post('/me/notifications/read', ({ event }) => {
  requireUser(event)
  return { message: 'تم الحفظ.' }
})
router.post('/me/avatar', ({ event }) => ({ data: serializeMe(requireUser(event)) }))
router.delete('/me/avatar', ({ event }) => ({ data: serializeMe(requireUser(event)) }))
router.put('/me/password', ({ event, body }) => {
  requireUser(event)
  const errors: Record<string, string[]> = {}
  if (!body.current_password) errors.current_password = ['كلمة المرور الحالية مطلوبة']
  if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{10,}$/.test(body.password ?? '')) errors.password = ['10 أحرف على الأقل مع حرف كبير وصغير ورقم']
  else if (body.password !== body.password_confirmation) errors.password = ['تأكيد كلمة المرور غير مطابق']
  if (Object.keys(errors).length) throw apiError(422, 'البيانات المدخلة غير صالحة.', errors)
  return { message: 'تم تغيير كلمة المرور بنجاح.' }
})
router.post('/me/data-requests', ({ event, body }) => {
  const u = requireUser(event)
  audit(u, `data_request.${body.type}`, 'user', u.uuid, null, { note: body.note })
  // Laravel: { data: { id, type, status }, message }
  return { data: { id: randomId(), type: body.type ?? 'access', status: 'received' }, message: 'تم استلام طلبك وسنرد عليك خلال 30 يومًا.' }
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
    supported_locales: ['ar', 'en'],
    legal_versions: { privacy_policy: '0.3-draft', terms: '0.3-draft', nda: '0.2-draft' },
    verification_optional: false,
    platform: {
      name_ar: setting('platform.name_ar'),
      name_en: setting('platform.name_en'),
      short_name: setting('platform.short_name'),
      official_email: setting('platform.official_email'),
      official_phone: setting('platform.official_phone'),
      whatsapp: setting('platform.whatsapp'),
      email_investors: setting('platform.email_investors') ?? null,
      email_entrepreneurs: setting('platform.email_entrepreneurs') ?? null,
      social: { x: setting('platform.social.x') ?? null, linkedin: setting('platform.social.linkedin') ?? null, instagram: setting('platform.social.instagram') ?? null, youtube: setting('platform.social.youtube') ?? null },
    },
    features: Object.fromEntries(settings.filter((s) => s.group === 'features').map((s) => [s.key.replace('features.', ''), s.value])),
  },
}))
router.get('/public/faqs', () => ({ data: faqs }))
router.get('/public/news', ({ query }) => paginate(content.filter((c) => c.kind === 'news' && c.is_published), query))
router.get('/public/news/:slug', ({ params }) => {
  const p = content.find((c) => c.kind === 'news' && c.is_published && c.slug === params.slug)
  if (!p) throw apiError(404, 'Not found')
  return { data: { ...p, author: p.author ?? { name: 'د. نورة الشهري', title: 'مديرة البرامج', bio: 'باحثة ومتخصصة في تمويل الأثر الاجتماعي.', avatar_url: null, linkedin_url: 'https://www.linkedin.com/company/sciis' } } }
})
router.get('/public/events', () => ({ data: content.filter((c) => c.kind === 'events' && c.is_published) }))
router.get('/public/events/:slug', ({ params }) => {
  const p = content.find((c) => c.kind === 'events' && c.is_published && c.slug === params.slug)
  if (!p) throw apiError(404, 'Not found')
  return { data: p }
})
// Growth content (stories / partners / resources) — created from admin → content in mock mode; partners need display approval.
router.get('/public/stories', () => ({ data: content.filter((c) => c.kind === 'stories' && c.is_published) }))
router.get('/public/partners', () => ({ data: content.filter((c) => c.kind === 'partners' && c.is_published && (c as { approved_for_display?: boolean }).approved_for_display) }))
router.get('/public/resources', ({ query }) => ({ data: content.filter((c) => c.kind === 'resources' && c.is_published && (!query.audience || [query.audience, 'all'].includes((c as { audience?: string }).audience ?? 'all'))) }))
router.get('/public/pages/:slug', ({ params }) => {
  // The Laravel seeder uses `privacy-policy`; the mock seed uses `privacy` — accept both. `home` carries hero images in meta.
  const slug = params.slug === 'privacy-policy' ? 'privacy' : params.slug
  const p = content.find((c) => c.kind === 'pages' && c.slug === slug)
  if (!p) throw apiError(404, 'Page not found')
  // meta.sections is always empty in mock mode, so the public pages render their i18n fallback copy.
  return { data: { ...p, version: p.version ?? '1.0', updated_at: p.updated_at ?? p.published_at, meta: { ...(p.meta ?? {}), sections: {} } } }
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
  if (body.website) return { reference_no: 'C-0000-0000' } // honeypot → silently accepted
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
  u.profile = Object.assign({ ...(u.profile ?? makeEmptyProfile()) }, flattenProfilePayload(body))
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
  return { data: nominations.filter((n) => n.investor_user_id === u.id).map((n) => nominationContract(serializeNomination(n))) }
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
  return { data: nominationContract(serializeNomination(n)) }
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
  // Laravel contract (#7): `{data: {url, expires_at}}`.
  return { data: { url: `/mock-files/${params.uuid}.pdf`, expires_at: daysAhead(0.01) } }
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
    if (o.status === 'draft') required.push({ id: 9000 + opps.indexOf(o), opportunity_uuid: o.uuid, title: 'إكمال ملف المشروع وإرساله للمراجعة', note: `الاكتمال ${checklistFor(o).completion_percent}%`, due_at: null, action: 'complete', kind: 'field' })
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
    // team / market / motivation start empty (never inherit the template row's answers)
    founders_count: null,
    team_size: null,
    founders_commitment: null,
    founders_commitment_note: null,
    prior_startup_experience: null,
    prior_startup_experience_desc: null,
    industry_experience: null,
    industry_experience_desc: null,
    founders_known_years: null,
    has_advisory_board: null,
    advisory_board_desc: null,
    dev_team_structure: null,
    founders_agreement_signed: null,
    founders: [],
    product_stage: null,
    target_customers: null,
    market_size_desc: null,
    competitors: null,
    competitive_advantage: null,
    go_to_market: null,
    technology_desc: null,
    traction_desc: null,
    scalability_desc: null,
    why_now: null,
    support_needed: [],
    program_expectations: null,
    how_heard_id: null,
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
/** Team / market / motivation request keys (a completion item naming the section reopens all of them). */
const SECTION_FIELDS: Record<string, string[]> = {
  team: ['founders_count', 'team_size', 'founders_commitment', 'founders_commitment_note', 'prior_startup_experience', 'prior_startup_experience_desc', 'industry_experience', 'industry_experience_desc', 'founders_known_years', 'has_advisory_board', 'advisory_board_desc', 'dev_team_structure', 'founders_agreement_signed', 'founders'],
  market: ['target_customers', 'market_size_desc', 'competitors', 'competitive_advantage', 'go_to_market', 'product_stage', 'technology_desc', 'traction_desc', 'scalability_desc'],
  motivation: ['why_now', 'support_needed', 'program_expectations', 'how_heard_id', 'how_heard'],
}
/** ENT-05 (mock): reopened field keys from open completion requests, `document:<kind>` for documents. */
const reopenedFields = (o: OpportunityRow): string[] => {
  const keys: string[] = []
  for (const c of o.completion_requests.filter((c) => !c.resolved_at)) {
    for (const it of c.items) {
      if (it.field && SECTION_FIELDS[it.field]) keys.push(...SECTION_FIELDS[it.field])
      if (it.field === 'how_heard' || it.field === 'how_heard_id') keys.push('how_heard', 'how_heard_id')
      if (it.field) keys.push(it.field, ...(it.field === 'regions' ? ['region_ids'] : it.field === 'investment_types' ? ['investment_type_keys', 'investment_type_ids'] : it.field === 'sdgs' ? ['sdg_ids'] : it.field === 'beneficiary_groups' ? ['beneficiary_group_ids'] : []))
      if (it.document_kind) keys.push(`document:${it.document_kind}`)
    }
  }
  return [...new Set(keys)]
}
const fullWithLock = (o: OpportunityRow) => ({ ...serializeFull(o), reopened_fields: reopenedFields(o) })

router.get('/entrepreneur/opportunities/:uuid', ({ event, params }) => ({ data: fullWithLock(ownOpp(requireEntrepreneur(event), params.uuid)) }))
router.patch('/entrepreneur/opportunities/:uuid', ({ event, params, body }) => {
  const u = requireEntrepreneur(event)
  const o = ownOpp(u, params.uuid)
  if (!['needs_completion', 'draft'].includes(o.status)) throw apiError(403, 'Opportunity locked', { status: ['لا يمكن التعديل في الحالة الحالية'] }, 'forbidden')
  // Laravel contract: nested sections {basics, organization, team, impact, market, financials, motivation, disclosures}; the flat legacy shape is still accepted.
  const flat: Record<string, unknown> = { ...body }
  for (const section of ['basics', 'team', 'impact', 'market', 'financials', 'motivation', 'disclosures'] as const) {
    if (body[section] && typeof body[section] === 'object') Object.assign(flat, body[section])
  }
  if (Array.isArray(flat.investment_type_ids) && !flat.investment_type_keys) flat.investment_type_keys = (flat.investment_type_ids as number[]).map((id) => txById('investment_types', id)?.key).filter(Boolean)
  // mirror the API: founders capped at 6 (named rows only), support needs de-duplicated
  if (Array.isArray(flat.founders)) flat.founders = (flat.founders as { name?: string }[]).filter((f) => f && String(f.name ?? '').trim()).slice(0, 6)
  if (Array.isArray(flat.support_needed)) flat.support_needed = [...new Set(flat.support_needed as string[])]
  const editable = ['name', 'short_description', 'sector_id', 'stage', 'website', 'social_links', 'region_ids', 'investment_type_keys', 'beneficiary_group_ids', 'sdg_ids', 'organization', 'problem_statement', 'solution', 'expected_change', 'beneficiaries_desc', 'beneficiaries_current', 'beneficiaries_target', 'revenue_model_type', 'revenue_model_desc', 'revenue_state', 'last_year_revenue_band_id', 'funding_amount', 'funding_range_id', 'use_of_funds', 'prior_funding', 'prior_funding_desc', 'legal_disputes', 'legal_disputes_desc', 'license_required', 'license_status', 'operational_risks', 'council_relationship', 'council_relationship_desc', 'teaser_publication_consent', 'indicators', ...SECTION_FIELDS.team, ...SECTION_FIELDS.market, ...SECTION_FIELDS.motivation.filter((k) => k !== 'how_heard')]
  // ENT-05 field-level lock while needs_completion: only reopened fields may change (unchanged values are tolerated).
  if (o.status === 'needs_completion') {
    const open = reopenedFields(o)
    const locked = editable.filter((k) => k in flat && !open.includes(k) && JSON.stringify((o as never as Record<string, unknown>)[k] ?? null) !== JSON.stringify(flat[k] ?? null))
    if (locked.length) throw apiError(422, `الحقول المقفلة: ${locked.join(', ')}`, Object.fromEntries([['locked_fields', [locked.join(', ')]], ...locked.map((k) => [k, ['هذا الحقل مقفل في الحالة الحالية']])]), 'validation_failed')
  }
  for (const k of editable) if (k in flat) (o as never as Record<string, unknown>)[k] = flat[k]
  if (flat.data_accuracy_declared === true && !o.data_accuracy_declared_at) o.data_accuracy_declared_at = new Date().toISOString()
  if (flat.data_accuracy_declared === false) o.data_accuracy_declared_at = null
  if (o.sector_id) o.sector_key = txById('sectors', o.sector_id)?.key ?? o.sector_key
  if (flat.name && !String(flat.name).includes(DEMO)) o.name = `${flat.name} ${DEMO}`
  touch(o)
  return { data: fullWithLock(o) }
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
  // Laravel contract (#14): `{data: {reference_no, status, status_label, review_due_at}}` — the full record is a superset.
  return { data: { ...fullWithLock(o), reference_no: o.reference_no, status: o.status, review_due_at: o.review_due_at } }
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
  // Laravel contract (#15): both access levels; `detail` kept as an alias of the before-grant view.
  const before = serializeDetail(o, null, 'approved_member')
  return { data: { card: serializeCard(o), detail: before, detail_before_grant: before, detail_after_grant: serializeDetail(o, null, 'granted') } }
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
      return { investor: { id: u.id, uuid: u.uuid, name: u.name, type: u.type, city: u.profile?.city ?? null }, matched_criteria: mc, matched: mc, match_score: Object.values(mc).filter(Boolean).length, already_nominated: nominations.some((n) => n.investor_user_id === u.id && n.opportunity_uuid === o.uuid), coi_excluded: coi }
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
      candidates_focus: candFocus ? { opportunity: { uuid: candFocus.uuid, name: candFocus.name, reference_no: candFocus.reference_no }, candidates: candidatesFor(candFocus), max_nominations: setting<number>('opportunity.max_nominations_per_opportunity') } : null,
      my_tasks: [
        ...myTasks,
        // MTG-02 — unfinished meeting actions owned by the user
        ...meetings.flatMap((m) => (m.actions ?? []).filter((a) => !a.done_at && (a.owner_user_id === u.id || a.owner === u.name)).map((a) => ({ id: `meeting_action:${m.id}:${a.description}`, title: `إجراء اجتماع: ${a.description}`, href: '/app/admin/meetings', due_at: a.due_at ?? null }))),
      ],
    },
  }
})

router.get('/admin/opportunities', ({ event, query }) => {
  requireStaff(event)
  let list = opportunities.filter((o) => o.status !== 'draft')
  // Filters accepted as `filter[x]` or top-level `x` (same as the Laravel API)
  const f = (k: string) => query[`filter[${k}]`] ?? query[k]
  if (f('status')) list = list.filter((o) => String(f('status')).split(',').includes(o.status))
  if (f('sector')) list = list.filter((o) => { const term = txById('sectors', o.sector_id ?? -1); return term?.key === f('sector') || String(term?.id) === f('sector') })
  if (f('assignee')) list = list.filter((o) => String(o.assigned_reviewer_id) === f('assignee'))
  // `owner {id,name}` mirrors the staff resource (used by the meeting participant picker)
  let items = list.map((o) => { const owner = userById(o.owner_user_id); return { ...queueItem(o), owner: owner ? { id: owner.id, uuid: owner.uuid, name: owner.name } : null } })
  if (f('overdue') === '1' || f('overdue') === 'true') items = items.filter((i) => i.is_overdue)
  if (f('search')) {
    const s = String(f('search')).toLowerCase()
    items = items.filter((i) => i.name.toLowerCase().includes(s) || (i.reference_no ?? '').toLowerCase().includes(s))
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
  // Same envelope as the Laravel API (#19)
  return { data: { criteria: ['sector', 'stage', 'region', 'investment_type', 'funding_range'], max_nominations: setting<number>('opportunity.max_nominations_per_opportunity'), current_nominations: nominations.filter((n) => n.opportunity_uuid === o.uuid).length, candidates: candidatesFor(o) } }
})
router.post('/admin/opportunities/:uuid/nominations', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer'])
  const o = oppByUuid(params.uuid)
  if (!o) throw apiError(404, 'Not found')
  const max = setting<number>('opportunity.max_nominations_per_opportunity')
  const existing = nominations.filter((n) => n.opportunity_uuid === o.uuid).length
  // Laravel takes integer user ids; the mock also tolerates uuids.
  const ids: (string | number)[] = body.investor_user_ids ?? []
  if (!ids.length || !body.rationale || String(body.rationale).length < 10) throw apiError(422, 'Validation', { investor_user_ids: ids.length ? [] : ['اختر مستثمرًا واحدًا على الأقل'], rationale: body.rationale && String(body.rationale).length >= 10 ? [] : ['مبرر الترشيح مطلوب (10 أحرف على الأقل)'] })
  if (existing + ids.length > max) throw apiError(422, 'Max nominations', { investor_user_ids: [`الحد الأقصى ${max} ترشيحات لكل فرصة`] })
  const created = ids.map((idOrUuid) => {
    const inv = users.find((x) => x.id === Number(idOrUuid) || x.uuid === idOrUuid)
    if (!inv) throw apiError(422, 'Validation', { investor_user_ids: ['مستثمر غير معروف'] })
    const mc = matchCriteria(inv, o)
    const row = { id: nextNominationId(), investor_user_id: inv.id, opportunity_uuid: o.uuid, opportunity: null as never, matched_criteria: mc, match_score: Object.values(mc).filter(Boolean).length, rationale: body.rationale ?? '', teaser_version: o.teaser?.version ?? 1, sent_at: new Date().toISOString(), response: null, responded_at: null }
    nominations.unshift(row)
    audit(u, 'nomination.sent', 'opportunity', o.reference_no ?? o.uuid, null, { investor: inv.uuid })
    return serializeNomination(row, true)
  })
  return { data: created }
})
/** Staff view of an interest: investor incl. `id` + `allowed_transitions` (#21). */
const staffInterest = (i: (typeof interests)[number]) => { const s = serializeInterest(i, true); return { ...s, investor: s.investor ? { id: i.investor_user_id, ...s.investor } : undefined, allowed_transitions: INTEREST_TRANSITIONS[i.status] } }
router.get('/admin/interests', ({ event, query }) => {
  requireStaff(event)
  const opp = query['filter[opportunity]'] ?? query.opportunity
  const status = query['filter[status]'] ?? query.status
  let list = interests
  if (opp) list = list.filter((i) => i.opportunity_uuid === opp)
  if (status) list = list.filter((i) => i.status === status)
  return paginate(list.map(staffInterest), query)
})
router.post('/admin/interests/:id/transition', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer'])
  const i = interests.find((x) => x.id === Number(params.id))
  if (!i) throw apiError(404, 'Not found')
  if (!INTEREST_TRANSITIONS[i.status].includes(body.to)) throw apiError(422, 'Transition not allowed', { to: ['الانتقال غير مسموح'] })
  if (body.to === 'closed' && !body.reason) throw apiError(422, 'Validation', { reason: ['سبب الإغلاق مطلوب'] })
  i.status = body.to
  if (body.to === 'connected') {
    i.next_step = 'جدولة اجتماع التعارف'
    const o = oppByUuid(i.opportunity_uuid)!
    if (o.status === 'eligible') doTransition(o, 'connected', u, 'موافقة مزدوجة')
  }
  if (body.to === 'meeting') i.next_step = 'اجتماع التعارف مجدول'
  if (body.to === 'closed') { i.closed_at = new Date().toISOString(); i.next_step = null; i.closed_reason = body.reason ?? 'أغلقه فريق المجلس' }
  audit(u, 'interest.transition', 'interest', i.id, null, { to: body.to, reason: body.reason ?? null })
  return { data: staffInterest(i) }
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
/** Staff view: participants carry `user_id` (like the Laravel MeetingResource) so minutes can record attendance / action owners. */
const staffMeeting = (m: (typeof meetings)[number]) => { const s = serializeMeeting(m); return { ...s, participants: s.participants.map((p, i) => ({ ...p, user_id: p.user_id ?? m.participant_user_ids[i] ?? null })) } }
router.get('/admin/meetings', ({ event, query }) => {
  requireStaff(event)
  return paginate(meetings.map(staffMeeting), query)
})
router.post('/admin/meetings', ({ event, body }) => {
  const u = requireStaff(event, ['matching_officer', 'impact_officer'])
  const o = oppByUuid(body.opportunity_uuid)
  if (!o) throw apiError(422, 'Validation', { opportunity_uuid: ['الفرصة مطلوبة'] })
  const ids: number[] = Array.isArray(body.participant_user_ids) ? body.participant_user_ids.map(Number) : []
  const errors: Record<string, string[]> = {}
  if (!body.title) errors.title = ['العنوان مطلوب']
  if (!body.starts_at) errors.starts_at = ['وقت البداية مطلوب']
  if (!body.ends_at) errors.ends_at = ['وقت النهاية مطلوب']
  else if (body.starts_at && new Date(body.ends_at) <= new Date(body.starts_at)) errors.ends_at = ['وقت النهاية يجب أن يكون بعد البداية']
  if (!ids.length) errors.participant_user_ids = ['اختر مشاركًا واحدًا على الأقل']
  if (Object.keys(errors).length) throw apiError(422, 'Validation', errors)
  const interest = body.interest_id ? interests.find((i) => i.id === Number(body.interest_id)) : null
  const roleFor = (id: number) => (id === o.owner_user_id ? ('owner' as const) : userById(id)?.type === 'staff' ? ('council' as const) : ('investor' as const))
  const participants = ids.map((id) => ({ user_id: id, name: userById(id)?.name ?? `#${id}`, role: roleFor(id) }))
  const row = { id: nextMeetingId(), opportunity_uuid: o.uuid, interest_id: interest?.id ?? null, opportunity: null as never, title: body.title, starts_at: body.starts_at, ends_at: body.ends_at, location: body.location ?? null, video_url: body.video_url ?? null, status: 'scheduled' as const, organizer: { name: u.name }, participants, participant_user_ids: ids, actions: [] }
  meetings.unshift(row)
  audit(u, 'meeting.created', 'meeting', row.id, null, { title: row.title, starts_at: row.starts_at })
  if (interest && interest.status === 'connected') { interest.status = 'meeting'; interest.next_step = 'اجتماع التعارف مجدول' }
  return { data: staffMeeting(row) }
})
router.patch('/admin/meetings/:id', ({ event, params, body }) => {
  requireStaff(event, ['matching_officer', 'impact_officer'])
  const m = meetings.find((x) => x.id === Number(params.id))
  if (!m) throw apiError(404, 'Not found')
  Object.assign(m, body)
  return { data: staffMeeting(m) }
})
router.post('/admin/meetings/:id/minutes', ({ event, params, body }) => {
  const u = requireStaff(event, ['matching_officer', 'impact_officer'])
  const m = meetings.find((x) => x.id === Number(params.id))
  if (!m) throw apiError(404, 'Not found')
  if (!body.minutes || String(body.minutes).length < 5) throw apiError(422, 'Validation', { minutes: ['نص المحضر مطلوب (5 أحرف على الأقل)'] })
  m.minutes = body.minutes
  m.status = 'held'
  if (Array.isArray(body.attended_user_ids)) {
    const attended = body.attended_user_ids.map(Number)
    m.participants = m.participants.map((p, i) => ({ ...p, attended: attended.includes(p.user_id ?? m.participant_user_ids[i]) }))
  }
  // Laravel contract: actions[].owner_user_id → resolved to the owner's name for display
  m.actions = ((body.actions ?? []) as { description: string; owner_user_id?: number | null; due_at?: string | null }[]).map((a, i) => ({ id: i + 1, description: a.description, owner_user_id: a.owner_user_id ?? null, owner: a.owner_user_id ? userById(Number(a.owner_user_id))?.name ?? null : null, due_at: a.due_at ?? null, done_at: null }))
  audit(u, 'meeting.minutes_recorded', 'meeting', m.id, null, { actions: m.actions.length })
  return { data: staffMeeting(m) }
})
router.get('/admin/memberships', ({ event, query }) => {
  requireStaff(event, ['membership_officer'])
  let list = investorUsers().map(serializeMembership)
  const statusFilter = query.status ?? query['filter[status]']
  if (statusFilter) list = list.filter((m) => m.status === statusFilter)
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
  // super_admin → full list; other staff may only use the picker form (`role=` or `search=`) and get a slim row (#18/#23)
  const me = requireStaff(event)
  const f = (k: string) => query[`filter[${k}]`] ?? query[k]
  const manages = me.roles.includes('super_admin')
  if (!manages && !f('role') && !f('search')) throw apiError(403, 'Forbidden', undefined, 'forbidden')
  let list = users.filter((u) => u.status === 'active' || manages)
  if (f('type')) list = list.filter((u) => u.type === f('type'))
  if (f('role')) { const roles = String(f('role')).split(','); list = list.filter((u) => u.roles.some((r) => roles.includes(r))) }
  if (f('status')) list = list.filter((u) => u.status === f('status'))
  if (f('search')) { const s = String(f('search')).toLowerCase(); list = list.filter((u) => u.name.toLowerCase().includes(s) || u.email.toLowerCase().includes(s)) }
  const rows = manages
    ? list.map((u) => ({ id: u.id, uuid: u.uuid, name: u.name, email: u.email, phone: u.phone, type: u.type, roles: u.roles, status: u.status, two_factor_enabled: u.two_factor_enabled, last_login_at: u.last_login_at }))
    : list.map((u) => ({ id: u.id, uuid: u.uuid, name: u.name, type: u.type, roles: u.roles }))
  return paginate(rows, query)
})
router.post('/admin/users', ({ event, body }) => {
  const staff = requireStaff(event, ['super_admin'])
  const errors: Record<string, string[]> = {}
  if (!body.name) errors.name = ['الاسم مطلوب']
  if (!body.email) errors.email = ['البريد مطلوب']
  else if (userByEmail(body.email)) errors.email = ['البريد مستخدم من قبل']
  if (!Array.isArray(body.roles) || !body.roles.length) errors.roles = ['اختر دورًا واحدًا على الأقل']
  if (Object.keys(errors).length) throw apiError(422, 'Validation', errors)
  // No password: a temporary one is generated and a reset link is "emailed" (#26)
  const u: MockUser = { id: nextUserId(), uuid: `u-${Date.now().toString(36)}`, type: 'staff', name: `${body.name} ${DEMO}`, email: body.email, phone: body.phone ?? '+9665000000XX', password: body.password ?? `Tmp-${Math.random().toString(36).slice(2)}A1!`, locale: 'ar', roles: body.roles ?? [], status: 'active', email_verified_at: new Date().toISOString(), phone_verified_at: new Date().toISOString(), two_factor_enabled: false, last_login_at: null, created_at: new Date().toISOString() }
  users.push(u)
  audit(staff, 'user.staff_created', 'user', u.uuid, null, { roles: u.roles, password_reset_sent: !body.password })
  return { data: { id: u.id, uuid: u.uuid, name: u.name, email: u.email, type: u.type, roles: u.roles, status: u.status, two_factor_enabled: false, last_login_at: null }, password_reset_sent: !body.password, message: body.password ? null : 'وضع المحاكاة: أُرسل رابط تعيين كلمة المرور (لا بريد فعلي)' }
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
// Same envelope as the Laravel API (#27): { settings: [...], features: {...} }; `is_public` mirrors /settings/public exposure
const PUBLIC_SETTING_KEYS = ['opportunity.access_policy', 'opportunity.funding_display', 'opportunity.max_nominations_per_opportunity', 'documents.max_size_mb', 'membership.fee_sar']
const settingsPayload = () => ({ settings: settings.map((s) => ({ ...s, is_public: PUBLIC_SETTING_KEYS.includes(s.key) || s.key.startsWith('platform.') || s.key.startsWith('legal.') })), features: { payments: false, english: true, browse_all: false, watermark: false, dark_mode: false } })
router.get('/admin/settings', ({ event }) => {
  requireStaff(event, ['super_admin'])
  return { data: settingsPayload() }
})
router.put('/admin/settings', ({ event, body }) => {
  const staff = requireStaff(event, ['super_admin'])
  const values = body.settings ?? body.values
  if (!values || typeof values !== 'object' || !Object.keys(values).length) throw apiError(422, 'Validation', { settings: ['حقل settings مطلوب'] })
  for (const [k, v] of Object.entries(values as Record<string, unknown>)) {
    const s = settings.find((x) => x.key === k)
    if (s) { audit(staff, 'setting.updated', 'setting', k, { value: s.value }, { value: v }); s.value = v }
  }
  return { data: settingsPayload() }
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

const REPORT_TITLES: Record<string, string> = { registrations_by_type: 'التسجيلات حسب النوع', memberships_by_status: 'العضويات حسب الحالة', opportunities_funnel: 'قمع الفرص', opportunities_by_sector_stage_region: 'الفرص حسب القطاع', first_time_completion_rate: 'نسبة الاكتمال من أول مرة', avg_review_time: 'متوسط زمن الفرز', avg_evaluation_time: 'متوسط زمن التقييم', overdue_items: 'العناصر المتأخرة', eligibility_rate: 'معدل الأهلية', interest_rate: 'معدل الاهتمام', connection_rate: 'معدل الربط', acquisition_channels: 'قنوات الاستقطاب' }
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
router.get('/admin/reports/:key', ({ event, params, query }) => {
  requireStaff(event, ['reports_viewer', 'impact_officer', 'membership_officer'])
  const fn = reports[params.key as ReportKey]
  if (!fn) throw apiError(404, 'Unknown report')
  const f = (k: string) => query[`filter[${k}]`] ?? query[k] ?? null
  const result = fn()
  const rows = ((result.series as { label: string; value: number }[]) ?? []).map((s) => ({ label: s.label, value: s.value }))
  return { data: { key: params.key, title: REPORT_TITLES[params.key] ?? params.key, columns: ['label', 'value'], rows, period: { from: f('from'), to: f('to') }, filters: { sector: f('sector'), region: f('region'), supports_sector_region: true }, generated_at: new Date().toISOString(), ...result } }
})
router.get('/admin/reports/:key/export.xlsx', ({ event, params }) => {
  const u = requireStaff(event, ['reports_viewer'])
  audit(u, 'report.export', 'report', params.key)
  return { ok: true, url: `/mock-files/report-${params.key}.xlsx`, note: 'وضع المحاكاة: لا يُنشأ ملف فعلي' }
})
const filterAuditLogs = (query: Record<string, string>) => {
  const f = (k: string) => query[`filter[${k}]`] ?? query[k]
  let list = auditLogs
  if (f('action')) list = list.filter((l) => l.action.includes(f('action')))
  if (f('type')) list = list.filter((l) => l.auditable_type === f('type'))
  // `user` matches by id, uuid, email or name (#31)
  if (f('user')) { const q = String(f('user')).toLowerCase(); list = list.filter((l) => { const u = l.user ? users.find((x) => x.name === l.user!.name) : null; return String(u?.id) === q || u?.uuid === q || u?.email.toLowerCase() === q || (l.user?.name ?? '').toLowerCase().includes(q) }) }
  return list
}
router.get('/admin/audit-logs/export.xlsx', ({ event, query }) => {
  const u = requireStaff(event, ['super_admin'])
  audit(u, 'audit_log.export', 'audit_log', 'export', null, { rows: filterAuditLogs(query).length })
  return { ok: true, url: '/mock-files/audit-logs.xlsx', note: 'وضع المحاكاة: لا يُنشأ ملف فعلي' }
})
router.get('/admin/audit-logs', ({ event, query }) => {
  requireStaff(event, ['super_admin'])
  return paginate(filterAuditLogs(query), query)
})

// ACC-03 — roles & permissions (spatie-like, in memory)
const PERMISSIONS = ['opportunities.view', 'opportunities.transition', 'evaluations.assign', 'evaluations.score', 'evaluations.approve', 'memberships.review', 'memberships.approve', 'nominations.manage', 'teasers.approve', 'meetings.manage', 'agreements.manage', 'impact.verify', 'reports.view', 'reports.export', 'settings.manage', 'users.manage', 'audit.view']
const roleRows: { id: number; name: string; permissions: string[]; is_system: boolean }[] = [
  { id: 1, name: 'super_admin', permissions: [...PERMISSIONS], is_system: true },
  { id: 2, name: 'membership_officer', permissions: ['memberships.review', 'memberships.approve', 'reports.view'], is_system: true },
  { id: 3, name: 'reviewer', permissions: ['opportunities.view', 'opportunities.transition', 'evaluations.assign', 'reports.view'], is_system: true },
  { id: 4, name: 'evaluator', permissions: ['opportunities.view', 'evaluations.score'], is_system: true },
  { id: 5, name: 'matching_officer', permissions: ['opportunities.view', 'nominations.manage', 'teasers.approve', 'meetings.manage', 'reports.view'], is_system: true },
  { id: 6, name: 'agreements_officer', permissions: ['opportunities.view', 'agreements.manage'], is_system: true },
  { id: 7, name: 'impact_officer', permissions: ['opportunities.view', 'impact.verify', 'reports.view'], is_system: true },
  { id: 8, name: 'advisory_member', permissions: ['opportunities.view'], is_system: true },
  { id: 9, name: 'reports_viewer', permissions: ['reports.view', 'reports.export'], is_system: true },
]
const roleOut = (r: (typeof roleRows)[number]) => ({ ...r, users_count: users.filter((u) => (u.roles as string[]).includes(r.name)).length })
router.get('/admin/roles', ({ event }) => {
  requireStaff(event, ['super_admin'])
  return { data: { roles: roleRows.map(roleOut), permissions: PERMISSIONS } }
})
router.post('/admin/roles', ({ event, body }) => {
  const u = requireStaff(event, ['super_admin'])
  const name = String(body.name ?? '')
  if (!/^[a-z][a-z0-9_]{2,59}$/.test(name)) throw apiError(422, 'Validation', { name: ['معرّف الدور: أحرف لاتينية صغيرة وأرقام و _ فقط'] })
  if (roleRows.some((r) => r.name === name)) throw apiError(422, 'Validation', { name: ['الدور موجود مسبقًا'] })
  const perms = ((body.permissions ?? []) as string[]).filter((p) => PERMISSIONS.includes(p))
  const row = { id: Math.max(...roleRows.map((r) => r.id)) + 1, name, permissions: perms, is_system: false }
  roleRows.push(row)
  audit(u, 'role.created', 'role', name, null, { permissions: perms })
  return { data: roleOut(row) }
})
router.patch('/admin/roles/:id', ({ event, params, body }) => {
  const u = requireStaff(event, ['super_admin'])
  const row = roleRows.find((r) => r.id === Number(params.id))
  if (!row) throw apiError(404, 'Not found')
  if (row.name === 'super_admin') throw apiError(422, 'دور مدير النظام يحمل جميع الصلاحيات دائمًا ولا يمكن تعديله.')
  const before = [...row.permissions]
  if (Array.isArray(body.permissions)) row.permissions = (body.permissions as string[]).filter((p) => PERMISSIONS.includes(p))
  audit(u, 'role.updated', 'role', row.name, { permissions: before }, { permissions: row.permissions })
  return { data: roleOut(row) }
})
const GROWTH_KINDS = ['stories', 'partners', 'resources']
// Knowledge-resource files mock (registered before `/admin/content/:kind`, which would otherwise match first).
router.post('/admin/content/files', ({ event }) => {
  requireStaff(event, ['super_admin'])
  return { data: { url: '/brand/logo.webp', path: `content/files/mock/${Date.now()}.pdf`, name: 'guide.pdf', size: 204800, mime: 'application/pdf' } }
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
  const c = { ...(GROWTH_KINDS.includes(params.kind) ? body : {}), id: nextContentId(), kind: params.kind as never, slug: body.slug ?? `item-${Date.now()}`, title_ar: body.title_ar, title_en: body.title_en ?? null, body_ar: body.body_ar ?? '', body_en: body.body_en ?? null, is_published: !!body.is_published, published_at: body.is_published ? new Date().toISOString() : null, starts_at: body.starts_at ?? null }
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
router.delete('/admin/content/:kind/:id', ({ event, params }) => {
  requireStaff(event, ['super_admin'])
  const i = content.findIndex((x) => x.id === Number(params.id) && x.kind === params.kind)
  if (!GROWTH_KINDS.includes(params.kind) || i === -1) throw apiError(404, 'Not found')
  content.splice(i, 1)
  return { message: 'تم الحذف بنجاح.' }
})
router.get('/admin/contact-messages', ({ event, query }) => {
  requireStaff(event)
  return paginate(contactMessages, query)
})
router.patch('/admin/contact-messages/:id', ({ event, params, body }) => {
  requireStaff(event)
  const m = contactMessages.find((x) => x.id === Number(params.id))
  if (!m) throw apiError(404, 'Not found')
  if (body.status) m.status = body.status
  return { message: 'تم الحفظ' }
})
// BRD-03 — media upload mock: the real API re-encodes to WebP and returns {url, path, width, height}.
router.post('/admin/content/media', ({ event }) => {
  requireStaff(event, ['super_admin'])
  const n = Date.now()
  return { data: { url: '/brand/logo.webp', path: `content/mock/${n}.webp`, width: 900, height: 306 } }
})

// ======================================================================== SYSTEM
router.get('/health', () => ({ status: 'ok', db: 'mock', redis: 'mock', storage: 'mock', queue_lag: 0 }))
router.get('/version', () => ({ version: '0.1.0-mock', spec: '3.0' }))

export { currentUser, COOKIE }
