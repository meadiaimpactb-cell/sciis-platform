<script setup lang="ts">
/**
 * Registration — doc §7.5 (ACC-01, ACC-02, ACC-06).
 * Investor: 5 steps (account type → basic data → investment interests → experience & declarations → review).
 * Entrepreneur: 4 steps (account type → basic data → project basics → review).
 *
 * NOTE (doc §7.5): the "accredited investor" declaration is intentionally NOT rendered.
 * The data model keeps `investor_profiles.accredited_declaration` (status: pending_legal) but the UI must not
 * show it until the legal review of the accreditation wording is complete. Do not add it here without that approval.
 */
import { z } from 'zod'
import { makeSchemas } from '~/utils/validators'
import type { UserType } from '~/types/enums'
import { INVESTMENT_TYPES, PROJECT_STAGES, RISK_APPETITES, INSTITUTION_TYPES, LEGAL_FORMS } from '~/types/enums'

definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.register.metaTitle') })
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const toast = useToast()
const { label, term } = useEnumLabel()
const S = makeSchemas(t)

const type = ref<UserType>((route.query.type as UserType) ?? 'investor_individual')
const isInvestor = computed(() => type.value.startsWith('investor'))
const isInstitutional = computed(() => type.value === 'investor_institutional')
const isEntity = computed(() => type.value === 'entrepreneur_entity')

const steps = computed(() =>
  isInvestor.value
    ? [{ key: 'type', label: t('auth.register.steps.type') }, { key: 'basics', label: t('auth.register.steps.basics') }, { key: 'interests', label: t('auth.register.steps.interests') }, { key: 'experience', label: t('auth.register.steps.experience') }, { key: 'review', label: t('auth.register.steps.review') }]
    : [{ key: 'type', label: t('auth.register.steps.type') }, { key: 'basics', label: t('auth.register.steps.basics') }, { key: 'project', label: t('auth.register.steps.project') }, { key: 'review', label: t('auth.register.steps.review') }],
)
const step = ref(0)
const stepKey = computed(() => steps.value[step.value].key)

// ---- step 2: basics
const basicsSchema = computed(() => {
  const base = { name: S.str(2), email: S.email, phone: S.phone, password: S.password, password_confirmation: z.string(), city: S.str(), org_name: z.string(), org_type: z.string(), legal_form: z.string(), cr_number: z.string(), job_title: z.string(), employer: z.string(), linkedin_url: S.url }
  let schema = z.object(base).refine((v) => v.password === v.password_confirmation, { message: t('validation.passwordMatch'), path: ['password_confirmation'] })
  if (isInstitutional.value || isEntity.value) schema = schema.refine((v) => v.org_name.trim().length > 1, { message: t('validation.required'), path: ['org_name'] }) as never
  return schema
})
const basics = useZodForm(basicsSchema.value as never, { name: '', email: '', phone: '', password: '', password_confirmation: '', city: '', org_name: '', org_type: '', legal_form: '', cr_number: '', job_title: '', employer: '', linkedin_url: '' })

// ---- step 3: interests
const interestsSchema = z.object({ sectors: S.nonEmptyArray(z.number()), stages: S.nonEmptyArray(z.string()), investment_types: S.nonEmptyArray(z.string()), regions: z.array(z.number()), causes: z.array(z.number()), investment_range_id: z.string().min(1, t('validation.required')), annual_investment_band_id: z.string(), risk_appetite: z.string().min(1, t('validation.required')) })
const interests = useZodForm(interestsSchema, { sectors: [] as number[], stages: [] as string[], investment_types: [] as string[], regions: [] as number[], causes: [] as number[], investment_range_id: '', annual_investment_band_id: '', risk_appetite: '' })

// ---- step 4: experience & declarations
const expSchema = z.object({ experience_years: z.number().nullable(), experience_desc: S.str(10), prior_investments_count: z.number().nullable(), prior_investments_desc: z.string(), has_social_investments: z.boolean(), social_investments_desc: z.string(), mentoring_available: z.boolean(), mentoring_areas: z.string(), conflicts_disclosure: S.str(2), commit_meetings: S.accept, how_heard_id: z.string(), preferred_contact: z.string(), consent_privacy: S.accept, consent_terms: S.accept, consent_accuracy: S.accept })
const exp = useZodForm(expSchema, { experience_years: null as number | null, experience_desc: '', prior_investments_count: null as number | null, prior_investments_desc: '', has_social_investments: false, social_investments_desc: '', mentoring_available: false, mentoring_areas: '', conflicts_disclosure: '', commit_meetings: false, how_heard_id: '', preferred_contact: 'email', consent_privacy: false, consent_terms: false, consent_accuracy: false })

// ---- entrepreneur: project basics + consents
const projSchema = z.object({ project_name: S.str(2), short_description: S.str(20).max(280, t('validation.maxLength', { n: 280 })), sector_id: z.string().min(1, t('validation.required')), stage: z.string().min(1, t('validation.required')), consent_privacy: S.accept, consent_terms: S.accept })
const proj = useZodForm(projSchema, { project_name: '', short_description: '', sector_id: '', stage: '', consent_privacy: false, consent_terms: false })

const typeOptions = (['investor_individual', 'investor_institutional', 'entrepreneur_individual', 'entrepreneur_entity'] as UserType[]).map((v) => ({ value: v, label: t(`auth.register.types.${v}.title`), hint: t(`auth.register.types.${v}.text`) }))
const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: x.id, label: term(x) })))
const regionOpts = computed(() => tax.list('regions').map((x) => ({ value: x.id, label: term(x) })))
const causeOpts = computed(() => tax.list('beneficiary_groups').map((x) => ({ value: x.id, label: term(x) })))
const rangeOpts = computed(() => tax.list('investment_ranges').map((x) => ({ value: String(x.id), label: term(x) })))
const howHeardOpts = computed(() => tax.list('how_heard').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))
const typeOpts = INVESTMENT_TYPES.map((s) => ({ value: s, label: label('investmentType', s) }))
const riskOpts = RISK_APPETITES.map((s) => ({ value: s, label: label('riskAppetite', s) }))
const instOpts = INSTITUTION_TYPES.map((s) => ({ value: s, label: label('institutionType', s) }))
const legalOpts = LEGAL_FORMS.map((s) => ({ value: s, label: label('legalForm', s) }))
const contactOpts = [{ value: 'email', label: t('common.email') }, { value: 'phone', label: t('common.phone') }, { value: 'whatsapp', label: t('contact.whatsapp') }]

const next = () => {
  if (stepKey.value === 'basics' && !basics.validate()) return
  if (stepKey.value === 'interests' && !interests.validate()) return
  if (stepKey.value === 'experience' && !exp.validate()) return
  if (stepKey.value === 'project' && !proj.validate()) return
  step.value = Math.min(step.value + 1, steps.value.length - 1)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
const prev = () => (step.value = Math.max(0, step.value - 1))
watch(type, () => (step.value = 0))

const submitting = ref(false)
const submitAll = async () => {
  submitting.value = true
  try {
    const b = basics.values
    await auth.register({
      type: type.value,
      name: b.name,
      email: b.email,
      phone: b.phone,
      password: b.password,
      locale: 'ar',
      consents: ['privacy_policy', 'terms'],
      preferred_contact: exp.values.preferred_contact,
      organization: isInstitutional.value || isEntity.value ? { name: b.org_name, institution_type: b.org_type || undefined, legal_form: b.legal_form || undefined, cr_number: b.cr_number || undefined, city: b.city } : undefined,
      profile: isInvestor.value
        ? { city: b.city, job_title: b.job_title, employer: b.employer, linkedin_url: b.linkedin_url, ...interests.values, investment_range_id: Number(interests.values.investment_range_id) || null, annual_investment_band_id: Number(interests.values.annual_investment_band_id) || null, ...exp.values, how_heard_id: Number(exp.values.how_heard_id) || null }
        : undefined,
      project: !isInvestor.value ? { name: proj.values.project_name, short_description: proj.values.short_description, sector_id: Number(proj.values.sector_id), stage: proj.values.stage } : undefined,
    })
    toast.success(t('auth.register.successTitle'))
    router.push(localePath('/auth/verify'))
  } catch (e) {
    if (!basics.applyApiError(e)) toast.error((e as Error).message)
    else step.value = 1
  } finally {
    submitting.value = false
  }
}
const reviewRows = computed(() => {
  const b = basics.values
  const rows: { label: string; value: string }[] = [
    { label: t('auth.register.typeTitle'), value: label('userType', type.value) },
    { label: t('common.name'), value: b.name },
    { label: t('common.email'), value: b.email },
    { label: t('common.phone'), value: b.phone },
    { label: t('auth.register.city'), value: b.city },
  ]
  if (isInstitutional.value || isEntity.value) rows.push({ label: t('auth.register.orgName'), value: b.org_name })
  if (isInvestor.value) {
    rows.push(
      { label: t('auth.register.sectors'), value: interests.values.sectors.map((id) => term(tax.byId('sectors', id))).join('، ') },
      { label: t('auth.register.stages'), value: interests.values.stages.map((s) => label('stage', s)).join('، ') },
      { label: t('auth.register.investmentTypes'), value: interests.values.investment_types.map((s) => label('investmentType', s)).join('، ') },
      { label: t('auth.register.investmentRange'), value: term(tax.byId('investment_ranges', Number(interests.values.investment_range_id))) },
      { label: t('auth.register.riskAppetite'), value: label('riskAppetite', interests.values.risk_appetite) },
      { label: t('auth.register.experienceDesc'), value: exp.values.experience_desc },
      { label: t('auth.register.conflicts'), value: exp.values.conflicts_disclosure },
    )
  } else {
    rows.push({ label: t('auth.register.projectName'), value: proj.values.project_name }, { label: t('auth.register.projectShort'), value: proj.values.short_description }, { label: t('auth.register.projectSector'), value: term(tax.byId('sectors', Number(proj.values.sector_id))) }, { label: t('auth.register.projectStage'), value: label('stage', proj.values.stage) })
  }
  return rows
})
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.register.title') }}</h1>
    <p class="text-muted mt-1">{{ $t('auth.register.sub') }}</p>
    <Stepper class="mt-5" :steps="steps" :current="step" clickable @go="step = $event" />

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
          <FormField v-model="basics.values.phone" name="phone" type="tel" :label="$t('common.phone')" :error="basics.errors.value.phone" required autocomplete="tel" ltr placeholder="05xxxxxxxx" />
          <FormField v-model="basics.values.password" name="password" type="password" :label="$t('common.password')" :error="basics.errors.value.password" required autocomplete="new-password" ltr />
          <FormField v-model="basics.values.password_confirmation" name="password_confirmation" type="password" :label="$t('common.passwordConfirm')" :error="basics.errors.value.password_confirmation" required autocomplete="new-password" ltr />
          <FormField v-model="basics.values.city" name="city" :label="$t('auth.register.city')" :error="basics.errors.value.city" required />
          <template v-if="isInstitutional || isEntity">
            <FormField v-model="basics.values.org_name" name="org_name" :label="$t('auth.register.orgName')" :error="basics.errors.value.org_name" required />
            <FormField v-if="isInstitutional" v-model="basics.values.org_type" name="org_type" type="select" :label="$t('auth.register.institutionType')" :options="instOpts" />
            <FormField v-else v-model="basics.values.legal_form" name="legal_form" type="select" :label="$t('auth.register.legalForm')" :options="legalOpts" />
            <FormField v-model="basics.values.cr_number" name="cr_number" :label="$t('auth.register.crNumber')" ltr />
          </template>
          <template v-if="isInvestor">
            <FormField v-model="basics.values.job_title" name="job_title" :label="$t('auth.register.jobTitle')" />
            <FormField v-model="basics.values.employer" name="employer" :label="$t('auth.register.employer')" />
            <FormField v-model="basics.values.linkedin_url" name="linkedin_url" type="url" :label="$t('auth.register.linkedin')" :error="basics.errors.value.linkedin_url" ltr class="sm:col-span-2" />
          </template>
        </div>
      </template>

      <!-- STEP: interests (investor) -->
      <template v-else-if="stepKey === 'interests'">
        <FormField v-model="interests.values.sectors" name="sectors" type="chips" :label="$t('auth.register.sectors')" :options="sectorOpts" :error="interests.errors.value.sectors" required />
        <FormField v-model="interests.values.stages" name="stages" type="chips" :label="$t('auth.register.stages')" :options="stageOpts" :error="interests.errors.value.stages" required />
        <FormField v-model="interests.values.investment_types" name="investment_types" type="chips" :label="$t('auth.register.investmentTypes')" :options="typeOpts" :error="interests.errors.value.investment_types" required />
        <FormField v-model="interests.values.regions" name="regions" type="chips" :label="$t('auth.register.regions')" :options="regionOpts" />
        <FormField v-model="interests.values.causes" name="causes" type="chips" :label="$t('auth.register.causes')" :options="causeOpts" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="interests.values.investment_range_id" name="investment_range_id" type="select" :label="$t('auth.register.investmentRange')" :options="rangeOpts" :help="$t('auth.register.investmentRangeHelp')" :error="interests.errors.value.investment_range_id" required />
          <FormField v-model="interests.values.annual_investment_band_id" name="annual_investment_band_id" type="select" :label="$t('auth.register.annualBand')" :options="rangeOpts" :help="$t('auth.register.annualBandHelp')" />
          <FormField v-model="interests.values.risk_appetite" name="risk_appetite" type="select" :label="$t('auth.register.riskAppetite')" :options="riskOpts" :error="interests.errors.value.risk_appetite" required />
        </div>
      </template>

      <!-- STEP: experience & declarations (investor) -->
      <template v-else-if="stepKey === 'experience'">
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="exp.values.experience_years" name="experience_years" type="number" :label="$t('auth.register.experienceYears')" :min="0" :max="60" />
          <FormField v-model="exp.values.prior_investments_count" name="prior_investments_count" type="number" :label="$t('auth.register.priorInvestmentsCount')" :min="0" />
          <FormField v-model="exp.values.experience_desc" name="experience_desc" type="textarea" :label="$t('auth.register.experienceDesc')" :error="exp.errors.value.experience_desc" required class="sm:col-span-2" :rows="3" />
          <FormField v-model="exp.values.prior_investments_desc" name="prior_investments_desc" type="textarea" :label="$t('auth.register.priorInvestmentsDesc')" class="sm:col-span-2" :rows="2" />
        </div>
        <FormField v-model="exp.values.has_social_investments" name="has_social_investments" type="checkbox" :label="$t('auth.register.hasSocialInvestments')" />
        <FormField v-if="exp.values.has_social_investments" v-model="exp.values.social_investments_desc" name="social_investments_desc" type="textarea" :label="$t('auth.register.socialInvestmentsDesc')" :rows="2" />
        <FormField v-model="exp.values.mentoring_available" name="mentoring_available" type="checkbox" :label="$t('auth.register.mentoring')" />
        <FormField v-if="exp.values.mentoring_available" v-model="exp.values.mentoring_areas" name="mentoring_areas" :label="$t('auth.register.mentoringAreas')" />
        <FormField v-model="exp.values.conflicts_disclosure" name="conflicts_disclosure" type="textarea" :label="$t('auth.register.conflicts')" :help="$t('auth.register.conflictsHelp')" :error="exp.errors.value.conflicts_disclosure" required :rows="2" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="exp.values.how_heard_id" name="how_heard_id" type="select" :label="$t('auth.register.howHeard')" :options="howHeardOpts" />
          <FormField v-model="exp.values.preferred_contact" name="preferred_contact" type="select" :label="$t('auth.register.preferredContact')" :options="contactOpts" />
        </div>
        <fieldset class="rounded-xl border border-border p-4 space-y-3">
          <legend class="px-1 text-sm font-medium">{{ $t('auth.register.declarations') }}</legend>
          <FormField v-model="exp.values.commit_meetings" name="commit_meetings" type="checkbox" :label="$t('auth.register.commitMeetings')" :error="exp.errors.value.commit_meetings" />
          <FormField v-model="exp.values.consent_accuracy" name="consent_accuracy" type="checkbox" :label="$t('auth.register.consentAccuracy')" :error="exp.errors.value.consent_accuracy" />
          <FormField v-model="exp.values.consent_privacy" name="consent_privacy" type="checkbox" :error="exp.errors.value.consent_privacy">
            <template #label>{{ $t('auth.register.consentPrivacy') }} (<NuxtLink :to="$localePath('/legal/privacy')" class="link" target="_blank">{{ $t('footer.privacy') }}</NuxtLink>)</template>
          </FormField>
          <FormField v-model="exp.values.consent_terms" name="consent_terms" type="checkbox" :error="exp.errors.value.consent_terms">
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
        <FormField v-model="proj.values.project_name" name="project_name" :label="$t('auth.register.projectName')" :error="proj.errors.value.project_name" required />
        <FormField v-model="proj.values.short_description" name="short_description" type="textarea" :label="$t('auth.register.projectShort')" :error="proj.errors.value.short_description" required :maxlength="280" :rows="3" />
        <div class="grid sm:grid-cols-2 gap-4">
          <FormField v-model="proj.values.sector_id" name="sector_id" type="select" :label="$t('auth.register.projectSector')" :options="sectorOpts.map((o) => ({ ...o, value: String(o.value) }))" :error="proj.errors.value.sector_id" required />
          <FormField v-model="proj.values.stage" name="stage" type="select" :label="$t('auth.register.projectStage')" :options="stageOpts" :error="proj.errors.value.stage" required />
        </div>
        <fieldset class="rounded-xl border border-border p-4 space-y-3">
          <legend class="px-1 text-sm font-medium">{{ $t('auth.register.declarations') }}</legend>
          <FormField v-model="proj.values.consent_privacy" name="consent_privacy" type="checkbox" :label="$t('auth.register.consentPrivacy')" :error="proj.errors.value.consent_privacy" />
          <FormField v-model="proj.values.consent_terms" name="consent_terms" type="checkbox" :label="$t('auth.register.consentTerms')" :error="proj.errors.value.consent_terms" />
        </fieldset>
      </template>

      <!-- STEP: review -->
      <template v-else>
        <h2 class="font-semibold">{{ $t('auth.register.reviewTitle') }}</h2>
        <p class="text-sm text-muted -mt-3">{{ $t('auth.register.reviewHint') }}</p>
        <dl class="divide-y divide-border rounded-xl border border-border">
          <div v-for="r in reviewRows" :key="r.label" class="grid sm:grid-cols-[180px_1fr] gap-1 px-4 py-2.5 text-sm"><dt class="text-muted">{{ r.label }}</dt><dd class="font-medium break-words">{{ r.value || '—' }}</dd></div>
        </dl>
        <Disclaimer variant="box" />
      </template>

      <div class="flex items-center justify-between gap-3 pt-2">
        <AppButton v-if="step > 0" type="button" variant="ghost" @click="prev">{{ $t('common.previous') }}</AppButton>
        <span v-else />
        <AppButton type="submit" :loading="submitting">{{ stepKey === 'review' ? $t('auth.register.submit') : $t('common.next') }}</AppButton>
      </div>
    </form>
    <p class="mt-5 text-sm text-muted text-center">{{ $t('auth.register.hasAccount') }} <NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></p>
  </div>
</template>
