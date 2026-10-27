<script setup lang="ts">
/**
 * Registration — doc §7.5 (ACC-01, ACC-02, ACC-06).
 * Investor: 5 steps (account type → basic data → investment interests → experience & declarations → review).
 * Entrepreneur: 4 steps (account type → basic data → project basics → review).
 *
 * Payload = RegisterRequest: flat account fields (phone in E.164 from PhoneField, password ≥10 with upper/lower/digit/
 * symbol), `organization_name` (+ `organization` details), `consents` + `consents_version` (from /settings/public),
 * `preferred_contact`, the investor `profile` in the same sectioned shape as PUT /investor/profile, and for
 * entrepreneurs the first `project` draft. Every rule comes from utils/validation.ts; a 422 from the API is routed
 * back under the right field of the right step.
 *
 * NOTE (doc §7.5): the "accredited investor" declaration is intentionally NOT rendered.
 * The data model keeps `investor_profiles.accredited_declaration` (status: pending_legal) but the UI must not
 * show it until the legal review of the accreditation wording is complete. Do not add it here without that approval.
 */
import { z } from 'zod'
import {
  acceptField, atLeastOne, emailField, normalizePhone, optionalNumber, optionalText, optionalUrlField, parseApiError, passwordConfirmField,
  passwordField, phoneField, requiredSelect, requiredString, RULES, staffNameField, vmsg,
} from '~/utils/validation'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'
import type { UserType } from '~/types/enums'
import type { AnswerMap } from '~/types/platform'
import { INVESTMENT_TYPES, PROJECT_STAGES, RISK_APPETITES, INSTITUTION_TYPES, LEGAL_FORMS } from '~/types/enums'

definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t, locale } = useI18n()
useHead({ title: t('auth.register.metaTitle') })
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { label, term } = useEnumLabel()
const { toPayload } = useInvestorProfile()
// FRM-01 — admin-defined additional questions of the investor profile (experience step; 422s under profile.extra_answers.*).
const extraAnswers = ref<AnswerMap>({})
const extraErrors = ref<Record<string, string>>({})
const extraForm = ref<{ validate: () => boolean } | null>(null)
const withExtraAnswers = <T extends object>(profile: T) => ({ ...profile, extra_answers: extraAnswers.value })

// `?type=` preselects the account type (join chooser / tools CTAs); unknown values fall back to an individual investor.
const ACCOUNT_TYPES: UserType[] = ['investor_individual', 'investor_institutional', 'entrepreneur_individual', 'entrepreneur_entity']
const queryType = String(route.query.type ?? '') as UserType
const type = ref<UserType>(ACCOUNT_TYPES.includes(queryType) ? queryType : 'investor_individual')
const isInvestor = computed(() => type.value.startsWith('investor'))
const isInstitutional = computed(() => type.value === 'investor_institutional')
const isEntity = computed(() => type.value === 'entrepreneur_entity')
const needsOrg = () => isInstitutional.value || isEntity.value

const steps = computed(() =>
  isInvestor.value
    ? [{ key: 'type', label: t('auth.register.steps.type') }, { key: 'basics', label: t('auth.register.steps.basics') }, { key: 'interests', label: t('auth.register.steps.interests') }, { key: 'experience', label: t('auth.register.steps.experience') }, { key: 'review', label: t('auth.register.steps.review') }]
    : [{ key: 'type', label: t('auth.register.steps.type') }, { key: 'basics', label: t('auth.register.steps.basics') }, { key: 'project', label: t('auth.register.steps.project') }, { key: 'review', label: t('auth.register.steps.review') }],
)
const step = ref(0)
const stepKey = computed(() => steps.value[step.value].key)

// ---- step 2: basics (organization name required for institutions / entities — decided at validation time)
const basicsSchema = z
  .object({
    name: staffNameField,
    email: emailField,
    phone: phoneField,
    password: passwordField,
    password_confirmation: passwordConfirmField,
    city: requiredString('auth.register.city', 1, RULES.cityMax),
    org_name: optionalText(RULES.titleMax),
    org_type: z.string().nullish(),
    legal_form: z.string().nullish(),
    cr_number: optionalText(RULES.orgNumberMax),
    job_title: optionalText(RULES.titleMax),
    employer: optionalText(RULES.titleMax),
    linkedin_url: optionalUrlField,
  })
  .superRefine((v, ctx) => {
    if (v.password && v.password_confirmation && v.password !== v.password_confirmation) ctx.addIssue({ code: 'custom', path: ['password_confirmation'], message: vmsg('passwordMatch') })
    if (needsOrg() && !(v.org_name ?? '').trim()) ctx.addIssue({ code: 'custom', path: ['org_name'], message: vmsg('requiredNamed', { field: t('auth.register.orgName') }) })
  })
const basics = useZodForm(basicsSchema as never, { name: '', email: '', phone: '', password: '', password_confirmation: '', city: '', org_name: '', org_type: '', legal_form: '', cr_number: '', job_title: '', employer: '', linkedin_url: '' })

// ---- step 3: interests
const interestsSchema = z.object({
  sectors: atLeastOne(z.number()),
  stages: atLeastOne(z.string()),
  investment_types: atLeastOne(z.string()),
  regions: z.array(z.number()),
  causes: z.array(z.number()),
  investment_range_id: requiredSelect('auth.register.investmentRange'),
  annual_investment_band_id: z.string().nullish(),
  risk_appetite: requiredSelect('auth.register.riskAppetite'),
})
const interests = useZodForm(interestsSchema as never, { sectors: [] as number[], stages: [] as string[], investment_types: [] as string[], regions: [] as number[], causes: [] as number[], investment_range_id: '', annual_investment_band_id: '', risk_appetite: '' })

// ---- step 4: experience & declarations
const expSchema = z.object({
  experience_years: optionalNumber({ min: 0, max: RULES.experienceYearsMax, int: true }),
  experience_desc: requiredString('auth.register.experienceDesc', RULES.investorExperienceDescMin, RULES.descMax),
  prior_investments_count: optionalNumber({ min: 0, max: RULES.priorInvestmentsMax, int: true }),
  prior_investments_desc: optionalText(RULES.descMax),
  has_social_investments: z.boolean(),
  social_investments_desc: optionalText(RULES.descMax),
  mentoring_available: z.boolean(),
  mentoring_areas: optionalText(RULES.investorMentoringMax),
  impact_policy: optionalText(RULES.descMax),
  conflicts_disclosure: requiredString('auth.register.conflicts', RULES.investorConflictsMin, RULES.descMax),
  commit_meetings: acceptField,
  how_heard_id: z.string().nullish(),
  preferred_contact: requiredSelect('auth.register.preferredContact'),
  consent_privacy: acceptField,
  consent_terms: acceptField,
  consent_accuracy: acceptField,
})
const exp = useZodForm(expSchema as never, { experience_years: null as number | null, experience_desc: '', prior_investments_count: null as number | null, prior_investments_desc: '', has_social_investments: false, social_investments_desc: '', mentoring_available: false, mentoring_areas: '', impact_policy: '', conflicts_disclosure: '', commit_meetings: false, how_heard_id: '', preferred_contact: 'email', consent_privacy: false, consent_terms: false, consent_accuracy: false })

// ---- entrepreneur: project basics + consents (keys differ from the investor step so blur never validates both)
const projSchema = z.object({
  project_name: requiredString('auth.register.projectName', RULES.titleMin, RULES.titleMax),
  short_description: requiredString('auth.register.projectShort', RULES.shortDescMin, RULES.shortDescMax),
  sector_id: requiredSelect('auth.register.projectSector'),
  stage: requiredSelect('auth.register.projectStage'),
  project_consent_privacy: acceptField,
  project_consent_terms: acceptField,
})
const proj = useZodForm(projSchema as never, { project_name: '', short_description: '', sector_id: '', stage: '', project_consent_privacy: false, project_consent_terms: false })

const typeOptions = (['investor_individual', 'investor_institutional', 'entrepreneur_individual', 'entrepreneur_entity'] as UserType[]).map((v) => ({ value: v, label: t(`auth.register.types.${v}.title`), hint: t(`auth.register.types.${v}.text`) }))
const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: x.id, label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const causeOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const rangeOpts = computed(() => tax.list('investment_ranges').map((x) => ({ value: String(x.id), label: term(x) })))
// The API has a dedicated `annual_investment_bands` group; the mock reuses the ranges.
const bandOpts = computed(() => { const l = tax.list('annual_investment_bands' as never); return (l.length ? l : tax.list('investment_ranges')).map((x) => ({ value: String(x.id), label: term(x) })) })
const howHeardOpts = computed(() => tax.list('how_heard').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
const typeOpts = INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))
const riskOpts = RISK_APPETITES.map((s) => ({ value: s, label: label('riskAppetite', s) }))
const instOpts = INSTITUTION_TYPES.map((s) => ({ value: s, label: label('institutionType', s) }))
const legalOpts = LEGAL_FORMS.map((s) => ({ value: s, label: label('legalForm', s) }))
const contactOpts = [{ value: 'email', label: t('common.email') }, { value: 'phone', label: t('common.phone') }, { value: 'whatsapp', label: t('contact.whatsapp') }]

/** Form that validates a step (type / review have none). */
const formOf = (key: string) => ({ basics, interests, experience: exp, project: proj })[key as 'basics']
const validateStep = async (key: string) => {
  const f = formOf(key)
  if (!f) return true
  const ok = await f.validate()
  const extraOk = key !== 'experience' || (extraForm.value?.validate() ?? true)
  if (!ok || !extraOk) toastError(t('validation.fixErrors'))
  return ok && extraOk
}
const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })
const next = async () => {
  if (!(await validateStep(stepKey.value))) return
  step.value = Math.min(step.value + 1, steps.value.length - 1)
  scrollTop()
}
const prev = () => (step.value = Math.max(0, step.value - 1))
watch(type, () => (step.value = 0))

// ---- 422 routing: server key → [step, field]
const SERVER_FIELDS: Record<string, [string, string]> = {
  name: ['basics', 'name'], email: ['basics', 'email'], phone: ['basics', 'phone'], password: ['basics', 'password'], password_confirmation: ['basics', 'password_confirmation'],
  organization_name: ['basics', 'org_name'], 'organization.name': ['basics', 'org_name'], 'organization.institution_type': ['basics', 'org_type'], 'organization.legal_form': ['basics', 'legal_form'],
  'organization.cr_number': ['basics', 'cr_number'], 'organization.city': ['basics', 'city'], 'profile.basics.city': ['basics', 'city'], 'profile.basics.job_title': ['basics', 'job_title'],
  'profile.basics.employer': ['basics', 'employer'], 'profile.basics.linkedin_url': ['basics', 'linkedin_url'],
  'profile.interests.sector_ids': ['interests', 'sectors'], 'profile.interests.stage_ids': ['interests', 'stages'], 'profile.interests.investment_type_ids': ['interests', 'investment_types'],
  'profile.interests.region_ids': ['interests', 'regions'], 'profile.interests.cause_ids': ['interests', 'causes'], 'profile.interests.investment_range_id': ['interests', 'investment_range_id'],
  'profile.interests.annual_investment_band_id': ['interests', 'annual_investment_band_id'], 'profile.interests.risk_appetite': ['interests', 'risk_appetite'],
  'profile.experience.experience_years': ['experience', 'experience_years'], 'profile.experience.experience_desc': ['experience', 'experience_desc'],
  'profile.experience.prior_investments_count': ['experience', 'prior_investments_count'], 'profile.experience.prior_investments_desc': ['experience', 'prior_investments_desc'],
  'profile.experience.social_investments_desc': ['experience', 'social_investments_desc'], 'profile.experience.mentoring_areas': ['experience', 'mentoring_areas'],
  'profile.declarations.conflicts_disclosure': ['experience', 'conflicts_disclosure'], 'profile.declarations.commit_meetings': ['experience', 'commit_meetings'],
  'profile.experience.impact_policy': ['experience', 'impact_policy'], 'consents.data_accuracy': ['experience', 'consent_accuracy'],
  'profile.basics.how_heard_id': ['experience', 'how_heard_id'], 'profile.basics.preferred_contact': ['experience', 'preferred_contact'], preferred_contact: ['experience', 'preferred_contact'],
  'project.name': ['project', 'project_name'], 'project.short_description': ['project', 'short_description'], 'project.sector_id': ['project', 'sector_id'], 'project.stage': ['project', 'stage'],
}
/** Put each 422 message under its field, jump to the earliest step concerned; unknown keys → toast. */
const routeServerErrors = (errors: Record<string, string>) => {
  const byStep: Record<string, Record<string, string>> = {}
  const orphans: string[] = []
  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith('profile.extra_answers.')) { extraErrors.value = { ...extraErrors.value, [key]: message }; byStep.experience ??= {}; continue }
    let target = SERVER_FIELDS[key] ?? SERVER_FIELDS[key.replace(/\.\d+$/, '')]
    if (!target && key.startsWith('consents')) target = isInvestor.value ? ['experience', 'consent_terms'] : ['project', 'project_consent_terms']
    if (target && steps.value.some((s) => s.key === target![0])) (byStep[target[0]] ??= {})[target[1]] = message
    else orphans.push(message)
  }
  for (const [key, errs] of Object.entries(byStep)) formOf(key)?.setErrors(errs as never)
  const first = steps.value.findIndex((s) => byStep[s.key])
  if (first >= 0) { step.value = first; scrollTop(); toastError(t('validation.fixErrors')) }
  if (orphans.length) toastError(orphans[0])
}

/** Registration already sent both OTP codes — /auth/verify starts its 60 s resend countdown from this. */
const otpSentAt = useState<number | null>('otp-sent-at', () => null)
const submitting = ref(false)
const rateLimited = ref<string | null>(null)
const submitAll = async () => {
  if (submitting.value) return
  rateLimited.value = null
  extraErrors.value = {}
  // Every step is re-checked (a language switch or a back-and-forth may have left something invalid).
  for (const [i, s] of steps.value.entries()) {
    if (!(await validateStep(s.key))) { step.value = i; scrollTop(); return }
  }
  submitting.value = true
  try {
    const b = basics.values
    const ex = exp.values
    const it = interests.values
    const pj = proj.values
    const hasOrg = needsOrg()
    await auth.register({
      type: type.value,
      name: b.name.trim(),
      email: b.email.trim().toLowerCase(),
      phone: normalizePhone(b.phone) || b.phone,
      password: b.password,
      password_confirmation: b.password_confirmation,
      locale: (locale.value === 'en' ? 'en' : 'ar'),
      // INV-01 — the investor's data-accuracy declaration is stored as a consent (time, IP, UA) like the other two.
      consents: isInvestor.value ? ['privacy_policy', 'terms', 'data_accuracy'] : ['privacy_policy', 'terms'],
      consents_version: tax.settings?.legal_versions?.terms,
      preferred_contact: isInvestor.value ? ex.preferred_contact : undefined,
      organization_name: hasOrg ? b.org_name.trim() : undefined,
      organization: hasOrg ? { institution_type: b.org_type || undefined, legal_form: b.legal_form || undefined, cr_number: b.cr_number || undefined, city: b.city || undefined } : undefined,
      profile: isInvestor.value
        ? withExtraAnswers(toPayload({
          city: b.city, job_title: b.job_title, employer: b.employer, linkedin_url: b.linkedin_url || null, preferred_contact: ex.preferred_contact as 'email', how_heard_id: Number(ex.how_heard_id) || null,
          sectors: it.sectors, stages: it.stages, investment_types: it.investment_types, regions: it.regions, causes: it.causes,
          investment_range_id: Number(it.investment_range_id) || null, annual_investment_band_id: Number(it.annual_investment_band_id) || null, risk_appetite: (it.risk_appetite || null) as never,
          experience_years: ex.experience_years, experience_desc: ex.experience_desc, prior_investments_count: ex.prior_investments_count, prior_investments_desc: ex.prior_investments_desc,
          has_social_investments: ex.has_social_investments, social_investments_desc: ex.social_investments_desc, mentoring_available: ex.mentoring_available, mentoring_areas: ex.mentoring_areas,
          conflicts_disclosure: ex.conflicts_disclosure, commit_meetings: ex.commit_meetings,
          ...(isInstitutional.value ? { impact_policy: ex.impact_policy } : {}),
        }))
        : undefined,
      project: !isInvestor.value ? { name: pj.project_name.trim(), short_description: pj.short_description.trim(), sector_id: Number(pj.sector_id) || null, stage: pj.stage || null } : undefined,
    })
    otpSentAt.value = Date.now()
    toastSuccess(t('notify.registered'))
    router.push(localePath('/auth/verify'))
  } catch (e) {
    const p = parseApiError(e)
    if (p.status === 429) rateLimited.value = p.message || t('notify.tooMany')
    else if (Object.keys(p.errors).length) routeServerErrors(p.errors)
    else toastApiError(e)
  } finally {
    submitting.value = false
  }
}
const reviewRows = computed(() => {
  const b = basics.values
  const it = interests.values
  const ex = exp.values
  const pj = proj.values
  const rows: { label: string; value: string }[] = [
    { label: t('auth.register.typeTitle'), value: label('userType', type.value) },
    { label: t('common.name'), value: b.name },
    { label: t('common.email'), value: b.email },
    { label: t('common.phone'), value: normalizePhone(b.phone) || b.phone },
    { label: t('auth.register.city'), value: b.city },
  ]
  if (needsOrg()) rows.push({ label: t('auth.register.orgName'), value: b.org_name })
  if (isInvestor.value) {
    rows.push(
      { label: t('auth.register.sectors'), value: it.sectors.map((id) => term(tax.byId('sectors', id))).join('، ') },
      { label: t('auth.register.stages'), value: it.stages.map((s) => label('stage', s)).join('، ') },
      { label: t('auth.register.investmentTypes'), value: it.investment_types.map((s) => label('investmentType', s)).join('، ') },
      { label: t('auth.register.investmentRange'), value: term(tax.byId('investment_ranges', Number(it.investment_range_id))) },
      { label: t('auth.register.riskAppetite'), value: label('riskAppetite', it.risk_appetite) },
      { label: t('auth.register.experienceDesc'), value: ex.experience_desc },
      { label: t('auth.register.conflicts'), value: ex.conflicts_disclosure },
    )
  } else {
    rows.push({ label: t('auth.register.projectName'), value: pj.project_name }, { label: t('auth.register.projectShort'), value: pj.short_description }, { label: t('auth.register.projectSector'), value: term(tax.byId('sectors', Number(pj.sector_id))) }, { label: t('auth.register.projectStage'), value: label('stage', pj.stage) })
  }
  return rows
})
</script>

<template>
  <div class="card p-6 sm:p-8">
    <h1 class="text-2xl">{{ $t('auth.register.title') }}</h1>
    <p class="mt-2 text-base text-muted">{{ $t('auth.register.sub') }}</p>
    <Stepper class="mt-6 border-y border-border py-4" :steps="steps" :current="step" clickable @go="step = $event" />

    <form class="mt-6 space-y-5" novalidate @submit.prevent="stepKey === 'review' ? submitAll() : next()">
      <!-- STEP: account type -->
      <template v-if="stepKey === 'type'">
        <FormField v-model="type" name="type" type="radio-cards" :label="$t('auth.register.typeTitle')" :options="typeOptions" />
      </template>

      <!-- STEP: basics -->
      <template v-else-if="stepKey === 'basics'">
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="basics.values.name" name="name" :label="$t('common.name')" :error="basics.errors.value.name" required autocomplete="name" class="sm:col-span-2" />
          <FormField v-model="basics.values.email" name="email" type="email" :label="$t('common.email')" :error="basics.errors.value.email" required autocomplete="email" ltr />
          <PhoneField v-model="basics.values.phone" name="phone" :label="$t('common.phone')" :error="basics.errors.value.phone" required />
          <div>
            <FormField v-model="basics.values.password" name="password" type="password" :label="$t('common.password')" :error="basics.errors.value.password" required autocomplete="new-password" ltr />
            <PasswordChecklist :password="basics.values.password" />
          </div>
          <FormField v-model="basics.values.password_confirmation" name="password_confirmation" type="password" :label="$t('common.passwordConfirm')" :error="basics.errors.value.password_confirmation" required autocomplete="new-password" ltr />
          <FormField v-model="basics.values.city" name="city" :label="$t('auth.register.city')" :error="basics.errors.value.city" required :maxlength="RULES.cityMax" />
          <template v-if="isInstitutional || isEntity">
            <FormField v-model="basics.values.org_name" name="org_name" :label="$t('auth.register.orgName')" :error="basics.errors.value.org_name" required :maxlength="RULES.titleMax" />
            <FormField v-if="isInstitutional" v-model="basics.values.org_type" name="org_type" type="select" :label="$t('auth.register.institutionType')" :options="instOpts" :error="basics.errors.value.org_type" />
            <FormField v-else v-model="basics.values.legal_form" name="legal_form" type="select" :label="$t('auth.register.legalForm')" :options="legalOpts" :error="basics.errors.value.legal_form" />
            <FormField v-model="basics.values.cr_number" name="cr_number" :label="$t('auth.register.crNumber')" :error="basics.errors.value.cr_number" ltr :maxlength="RULES.orgNumberMax" />
          </template>
          <template v-if="isInvestor">
            <FormField v-model="basics.values.job_title" name="job_title" :label="$t('auth.register.jobTitle')" :error="basics.errors.value.job_title" />
            <FormField v-model="basics.values.employer" name="employer" :label="$t('auth.register.employer')" :error="basics.errors.value.employer" />
            <FormField v-model="basics.values.linkedin_url" name="linkedin_url" type="url" :label="$t('auth.register.linkedin')" :error="basics.errors.value.linkedin_url" ltr class="sm:col-span-2" />
          </template>
        </div>
      </template>

      <!-- STEP: interests (investor) -->
      <template v-else-if="stepKey === 'interests'">
        <FormField v-model="interests.values.sectors" name="sectors" type="chips" :label="$t('auth.register.sectors')" :options="sectorOpts" :error="interests.errors.value.sectors" required />
        <FormField v-model="interests.values.stages" name="stages" type="chips" :label="$t('auth.register.stages')" :options="stageOpts" :error="interests.errors.value.stages" required />
        <FormField v-model="interests.values.investment_types" name="investment_types" type="chips" :label="$t('auth.register.investmentTypes')" :options="typeOpts" :error="interests.errors.value.investment_types" required />
        <FormField v-model="interests.values.regions" name="regions" type="chips" :label="$t('auth.register.regions')" :options="regionOpts" :error="interests.errors.value.regions" />
        <FormField v-model="interests.values.causes" name="causes" type="chips" :label="$t('auth.register.causes')" :options="causeOpts" :error="interests.errors.value.causes" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="interests.values.investment_range_id" name="investment_range_id" type="select" :label="$t('auth.register.investmentRange')" :options="rangeOpts" :help="$t('auth.register.investmentRangeHelp')" :error="interests.errors.value.investment_range_id" required />
          <FormField v-model="interests.values.annual_investment_band_id" name="annual_investment_band_id" type="select" :label="$t('auth.register.annualBand')" :options="bandOpts" :help="$t('auth.register.annualBandHelp')" :error="interests.errors.value.annual_investment_band_id" />
          <FormField v-model="interests.values.risk_appetite" name="risk_appetite" type="select" :label="$t('auth.register.riskAppetite')" :options="riskOpts" :error="interests.errors.value.risk_appetite" required />
        </div>
      </template>

      <!-- STEP: experience & declarations (investor) -->
      <template v-else-if="stepKey === 'experience'">
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="exp.values.experience_years" name="experience_years" type="number" :label="$t('auth.register.experienceYears')" :min="0" :max="RULES.experienceYearsMax" :error="exp.errors.value.experience_years" />
          <FormField v-model="exp.values.prior_investments_count" name="prior_investments_count" type="number" :label="$t('auth.register.priorInvestmentsCount')" :min="0" :max="RULES.priorInvestmentsMax" :error="exp.errors.value.prior_investments_count" />
          <FormField v-model="exp.values.experience_desc" name="experience_desc" type="textarea" :label="$t('auth.register.experienceDesc')" :error="exp.errors.value.experience_desc" required class="sm:col-span-2" :rows="3" :maxlength="RULES.descMax" />
          <FormField v-model="exp.values.prior_investments_desc" name="prior_investments_desc" type="textarea" :label="$t('auth.register.priorInvestmentsDesc')" :error="exp.errors.value.prior_investments_desc" class="sm:col-span-2" :rows="2" />
        </div>
        <FormField v-model="exp.values.has_social_investments" name="has_social_investments" type="checkbox" :label="$t('auth.register.hasSocialInvestments')" />
        <FormField v-if="exp.values.has_social_investments" v-model="exp.values.social_investments_desc" name="social_investments_desc" type="textarea" :label="$t('auth.register.socialInvestmentsDesc')" :error="exp.errors.value.social_investments_desc" :rows="2" />
        <FormField v-model="exp.values.mentoring_available" name="mentoring_available" type="checkbox" :label="$t('auth.register.mentoring')" />
        <FormField v-if="exp.values.mentoring_available" v-model="exp.values.mentoring_areas" name="mentoring_areas" :label="$t('auth.register.mentoringAreas')" :error="exp.errors.value.mentoring_areas" />
        <FormField v-if="isInstitutional" v-model="exp.values.impact_policy" name="impact_policy" type="textarea" :label="$t('investor.profile.impactPolicy')" :help="$t('investor.profile.impactPolicyHelp')" :error="exp.errors.value.impact_policy" :rows="3" :maxlength="RULES.descMax" />
        <!-- INV-01 — the membership file (CV / CR + authorization letter + impact policy) is uploaded from the profile once the account is verified -->
        <InlineAlert tone="info">{{ $t(isInstitutional ? 'auth.register.documentsLaterInstitution' : 'auth.register.documentsLaterIndividual') }}</InlineAlert>
        <FormField v-model="exp.values.conflicts_disclosure" name="conflicts_disclosure" type="textarea" :label="$t('auth.register.conflicts')" :help="$t('auth.register.conflictsHelp')" :error="exp.errors.value.conflicts_disclosure" required :rows="2" :maxlength="RULES.descMax" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="exp.values.how_heard_id" name="how_heard_id" type="select" :label="$t('auth.register.howHeard')" :options="howHeardOpts" :error="exp.errors.value.how_heard_id" />
          <FormField v-model="exp.values.preferred_contact" name="preferred_contact" type="select" :label="$t('auth.register.preferredContact')" :options="contactOpts" :error="exp.errors.value.preferred_contact" required />
        </div>
        <DynamicQuestions ref="extraForm" v-model="extraAnswers" form-key="investor_profile" :errors="extraErrors" error-prefix="profile.extra_answers." />
        <fieldset class="rounded-xl border border-border p-4 sm:p-5 space-y-3">
          <legend class="px-1 text-sm font-semibold text-ink">{{ $t('auth.register.declarations') }}</legend>
          <FormField v-model="exp.values.commit_meetings" name="commit_meetings" type="checkbox" :label="$t('auth.register.commitMeetings')" :error="exp.errors.value.commit_meetings" required />
          <FormField v-model="exp.values.consent_accuracy" name="consent_accuracy" type="checkbox" :label="$t('auth.register.consentAccuracy')" :error="exp.errors.value.consent_accuracy" required />
          <FormField v-model="exp.values.consent_privacy" name="consent_privacy" type="checkbox" :error="exp.errors.value.consent_privacy" required>
            <template #label>{{ $t('auth.register.consentPrivacy') }} (<NuxtLink :to="$localePath('/legal/privacy')" class="link" target="_blank">{{ $t('footer.privacy') }}</NuxtLink>)</template>
          </FormField>
          <FormField v-model="exp.values.consent_terms" name="consent_terms" type="checkbox" :error="exp.errors.value.consent_terms" required>
            <template #label>{{ $t('auth.register.consentTerms') }} (<NuxtLink :to="$localePath('/legal/terms')" class="link" target="_blank">{{ $t('footer.terms') }}</NuxtLink>)</template>
          </FormField>
          <!--
            Accredited-investor declaration: intentionally not rendered (doc §7.5 — pending legal review).
            Field exists in the API model as `accredited_declaration` {status: pending_legal}.
          -->
        </fieldset>
      </template>

      <!-- STEP: project (entrepreneur) -->
      <template v-else-if="stepKey === 'project'">
        <InlineAlert tone="info">{{ $t('auth.register.entrepreneurNote') }}</InlineAlert>
        <FormField v-model="proj.values.project_name" name="project_name" :label="$t('auth.register.projectName')" :error="proj.errors.value.project_name" required :maxlength="RULES.titleMax" />
        <FormField v-model="proj.values.short_description" name="short_description" type="textarea" :label="$t('auth.register.projectShort')" :error="proj.errors.value.short_description" required :maxlength="RULES.shortDescMax" :rows="3" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="proj.values.sector_id" name="sector_id" type="select" :label="$t('auth.register.projectSector')" :options="sectorOpts.map((o) => ({ ...o, value: String(o.value) }))" :error="proj.errors.value.sector_id" required />
          <FormField v-model="proj.values.stage" name="stage" type="select" :label="$t('auth.register.projectStage')" :options="stageOpts" :error="proj.errors.value.stage" required />
        </div>
        <fieldset class="rounded-xl border border-border p-4 sm:p-5 space-y-3">
          <legend class="px-1 text-sm font-semibold text-ink">{{ $t('auth.register.declarations') }}</legend>
          <FormField v-model="proj.values.project_consent_privacy" name="project_consent_privacy" type="checkbox" :error="proj.errors.value.project_consent_privacy" required>
            <template #label>{{ $t('auth.register.consentPrivacy') }} (<NuxtLink :to="$localePath('/legal/privacy')" class="link" target="_blank">{{ $t('footer.privacy') }}</NuxtLink>)</template>
          </FormField>
          <FormField v-model="proj.values.project_consent_terms" name="project_consent_terms" type="checkbox" :error="proj.errors.value.project_consent_terms" required>
            <template #label>{{ $t('auth.register.consentTerms') }} (<NuxtLink :to="$localePath('/legal/terms')" class="link" target="_blank">{{ $t('footer.terms') }}</NuxtLink>)</template>
          </FormField>
        </fieldset>
      </template>

      <!-- STEP: review -->
      <template v-else>
        <h2 class="text-xl">{{ $t('auth.register.reviewTitle') }}</h2>
        <p class="text-sm text-muted -mt-3">{{ $t('auth.register.reviewHint') }}</p>
        <dl class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-ink/5">
          <div v-for="r in reviewRows" :key="r.label" class="grid sm:grid-cols-[180px_1fr] gap-1 px-4 py-3 text-sm"><dt class="text-muted">{{ r.label }}</dt><dd class="font-medium text-ink break-words">{{ r.value || '—' }}</dd></div>
        </dl>
        <Disclaimer variant="box" />
      </template>

      <FormAlert :message="rateLimited" />
      <div class="flex items-center justify-between gap-3 border-t border-border pt-5">
        <AppButton v-if="step > 0" type="button" variant="secondary" size="lg" @click="prev">{{ $t('common.previous') }}</AppButton>
        <span v-else />
        <AppButton type="submit" size="lg" class="min-w-[8rem]" :loading="submitting">{{ stepKey === 'review' ? $t('auth.register.submit') : $t('common.next') }}</AppButton>
      </div>
    </form>
    <p class="mt-6 text-sm text-muted text-center">{{ $t('auth.register.hasAccount') }} <NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></p>
  </div>
</template>
