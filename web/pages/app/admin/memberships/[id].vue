<script setup lang="ts">
/**
 * ADM-06 — membership review. `m.profile` is the sectioned Laravel resource (or flat in mock mode) → `fromApi()`.
 * INV-01 — the membership file (CV / CR / authorization letter / impact policy, every version) opens through
 * GET /admin/documents/{uuid}/download-url (membership reviewers only, access logged); INV-02 — profile last update.
 */
import { z } from 'zod'
import type { Membership } from '~/types/api'
import { INVESTOR_DOCUMENT_KINDS, type MembershipStatus } from '~/types/enums'
import { optionalText, RULES } from '~/utils/validation'
import { confirmDialog, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['membership_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.memberships.detailTitle') })
const api = useApi()
const route = useRoute()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { date, dateTime } = useFormat()
const { fromApi } = useInvestorProfile()
const id = route.params.id as string
const { data: m, loading, refresh } = useAsyncResource(() => api.get<{ data: Membership & { allowed_transitions: MembershipStatus[] } }>(`/admin/memberships/${id}`).then((r) => r.data))
const profile = computed(() => fromApi(m.value?.profile))
const isInstitution = computed(() => m.value?.user?.type === 'investor_institutional')
const expectedDocs = computed<readonly string[]>(() => (isInstitution.value ? INVESTOR_DOCUMENT_KINDS.institutional : INVESTOR_DOCUMENT_KINDS.individual))
const missingDocs = computed(() => expectedDocs.value.filter((k) => !(profile.value.documents ?? []).some((d) => d.kind === k)))
const missing = computed(() => m.value?.missing_items ?? profile.value.missing_items ?? [])
const missingLabels: Record<string, string> = {
  country: t('auth.register.country'), city: t('auth.register.city'), job_title: t('auth.register.jobTitle'), sectors: t('auth.register.sectors'), stages: t('auth.register.stages'), investment_types: t('auth.register.investmentTypes'), regions: t('auth.register.regions'), investment_range: t('auth.register.investmentRange'), experience_years: t('auth.register.experienceYears'), experience_desc: t('auth.register.experienceDesc'), prior_investments_count: t('auth.register.priorInvestmentsCount'), risk_appetite: t('auth.register.riskAppetite'), preferred_contact: t('auth.register.preferredContact'), how_heard: t('auth.register.howHeard'), conflicts_disclosure: t('auth.register.conflicts'), commit_meetings: t('auth.register.commitMeetings'),
}
/** MembershipService::transition requires a note for these targets (TransitionMembershipRequest: required_if, ≤2000). */
const NOTE_REQUIRED: MembershipStatus[] = ['rejected', 'suspended', 'needs_completion']
const decision = useZodForm(
  z.object({ to: z.string(), decision_note: optionalText(RULES.noteMax) }).refine((v) => !NOTE_REQUIRED.includes(v.to as MembershipStatus) || !!v.decision_note?.trim(), { path: ['decision_note'], error: () => t('admin.memberships.noteRequired') }),
  { to: '', decision_note: '' },
  { aliases: { note: 'decision_note' } },
)
const target = ref<MembershipStatus | null>(null)
const applicant = computed(() => m.value?.user?.name ?? `#${id}`)
const transition = async (to: MembershipStatus) => {
  decision.values.to = to
  target.value = to
  await decision.submit(async (v) => {
    // Reject / suspend are hard to undo → explicit consent naming the applicant.
    if (to === 'rejected' && !(await confirmDialog(t('notify.rejectTitle'), t('notify.rejectText', { name: applicant.value }), t('notify.rejectConfirm')))) return
    if (to === 'suspended' && !(await confirmDialog(t('notify.suspendTitle'), t('notify.suspendText', { name: applicant.value }), t('notify.suspendConfirm')))) return
    await api.post(`/admin/memberships/${id}/transition`, { to, note: v.decision_note?.trim() || undefined })
    const item = t('notify.entities.membership')
    if (to === 'approved') toastSuccess(t('notify.approved', { item }))
    else if (to === 'rejected') toastInfo(t('notify.rejectedNotified', { item }))
    else toastSuccess(t('notify.statusChangedTo', { item, status: label('membershipStatus', to) }))
    decision.reset()
    await refresh()
  })
  target.value = null
}
const btn: Record<string, { label: string; variant: 'primary' | 'secondary' | 'danger' | 'ghost' }> = { approved: { label: t('admin.memberships.approve'), variant: 'primary' }, needs_completion: { label: t('admin.memberships.needsCompletion'), variant: 'secondary' }, rejected: { label: t('admin.memberships.reject'), variant: 'danger' }, suspended: { label: t('admin.memberships.suspend'), variant: 'danger' }, under_review: { label: label('membershipStatus', 'under_review'), variant: 'secondary' } }
</script>
<template>
  <div>
    <nav class="text-xs text-muted mb-3"><NuxtLink :to="$localePath('/app/admin/memberships')" class="hover:text-accent-text">{{ $t('admin.memberships.title') }}</NuxtLink> / <span class="num">#{{ id }}</span></nav>
    <div v-if="loading && !m" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="m">
      <PageHeader :title="m.user?.name ?? ''" :sub="`${label('userType', m.user?.type)} · ${m.user?.organization?.name ?? ''}`"><template #actions><AppBadge kind="membership" :value="m.status" size="md" :soft="false" /><AppButton v-if="m.user_id" size="sm" variant="outline" :to="$localePath(`/app/admin/users/${m.user_id}`)">{{ $t('admin.userFile.open') }}</AppButton></template></PageHeader>
      <div class="grid lg:grid-cols-[1fr_340px] gap-6 items-start">
        <div class="space-y-5">
          <SectionCard :title="$t('investor.profile.sections.basics')"><KeyValue :items="[{ label: $t('common.email'), value: m.user?.email, ltr: true }, { label: $t('common.phone'), value: m.user?.phone, ltr: true, num: true }, { label: $t('auth.register.city'), value: profile.city }, { label: $t('auth.register.jobTitle'), value: profile.job_title }, { label: $t('auth.register.employer'), value: profile.employer }, { label: $t('admin.memberships.columns.submitted'), value: date(m.submitted_at) }, { label: $t('investor.profile.lastUpdated'), value: profile.updated_at ? dateTime(profile.updated_at) : null, num: true }]" /></SectionCard>
          <SectionCard :title="$t('investor.profile.sections.interests')">
            <div class="space-y-3 text-sm">
              <div><p class="text-xs text-muted">{{ $t('auth.register.sectors') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.sectors" :key="s" class="chip text-xs !py-0.5">{{ term(tax.byId('sectors', s)) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.stages') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.stages" :key="s" class="chip text-xs !py-0.5">{{ label('stage', s) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.investmentTypes') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.investment_types" :key="s" class="chip text-xs !py-0.5">{{ label('investmentType', s) }}</li></ul></div>
              <div><p class="text-xs text-muted">{{ $t('auth.register.regions') }}</p><ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="s in profile.regions" :key="s" class="chip text-xs !py-0.5">{{ term(tax.byId('regions', s)) }}</li></ul></div>
              <InlineAlert tone="warning">{{ $t('admin.memberships.confidentialHint') }}</InlineAlert>
              <KeyValue :items="[{ label: $t('auth.register.investmentRange'), value: term(tax.byId('investment_ranges', profile.investment_range_id)) }, { label: $t('auth.register.annualBand'), value: term(tax.byId('annual_investment_bands' as never, profile.annual_investment_band_id) ?? tax.byId('investment_ranges', profile.annual_investment_band_id)) }, { label: $t('auth.register.riskAppetite'), value: label('riskAppetite', profile.risk_appetite) }]" />
            </div>
          </SectionCard>
          <SectionCard :title="$t('investor.profile.sections.experience')"><KeyValue :cols="1" :items="[{ label: $t('auth.register.experienceYears'), value: profile.experience_years, num: true }, { label: $t('auth.register.experienceDesc'), value: profile.experience_desc }, { label: $t('auth.register.priorInvestmentsCount'), value: profile.prior_investments_count, num: true }, { label: $t('auth.register.priorInvestmentsDesc'), value: profile.prior_investments_desc }, { label: $t('auth.register.hasSocialInvestments'), value: profile.has_social_investments ? `${$t('common.yes')} — ${profile.social_investments_desc ?? ''}` : $t('common.no') }, { label: $t('auth.register.conflicts'), value: profile.conflicts_disclosure }, { label: $t('auth.register.commitMeetings'), value: profile.commit_meetings ? $t('common.yes') : $t('common.no') }, ...(isInstitution ? [{ label: $t('investor.profile.impactPolicy'), value: profile.impact_policy }] : []), { label: $t('investor.profile.dataAccuracy'), value: profile.data_accuracy_declared_at ? $t('investor.profile.declaredOn', { date: dateTime(profile.data_accuracy_declared_at) }) : $t('admin.memberships.notDeclared') }]" /></SectionCard>
          <!-- FRM-01 — the investor's answers to the additional questions (labelled with the answered version) -->
          <SectionCard v-if="profile.extra_answers?.items?.length" :title="$t('platform.extra.title')"><ExtraAnswersView :data="profile.extra_answers" /></SectionCard>
          <!-- INV-01 membership file: every version, opened through a short-lived signed URL (access logged) -->
          <SectionCard :title="$t('investor.profile.documents.title')" :sub="$t('admin.memberships.documentsHint')" flush>
            <ul v-if="missingDocs.length" class="flex flex-wrap items-center gap-1.5 px-5 pt-4 text-xs">
              <li class="text-muted">{{ $t('investor.profile.documents.missing') }}:</li>
              <li v-for="k in missingDocs" :key="k" class="rounded-full border border-warning/30 bg-warning/10 text-warning px-2.5 py-0.5">{{ label('documentKind', k) }}</li>
            </ul>
            <DocumentVersions :documents="profile.documents ?? []" download-base="/admin/documents" show-notes show-size :caption="$t('investor.profile.documents.title')" />
          </SectionCard>
          <!-- FRM-01 — reviewer answers to the membership review questions -->
          <MembershipReviewQuestions :membership-id="id" :data="m.extra_answers" @saved="refresh" />
        </div>
        <aside class="space-y-4 lg:sticky lg:top-6">
          <div class="card card-pad">
            <div class="flex items-center justify-between"><h3 class="card-title">{{ $t('admin.memberships.columns.completion') }}</h3><CompletionRing :percent="profile.completion_percent ?? 0" :size="56" :stroke="6" /></div>
            <template v-if="missing.length">
              <p class="mt-3 text-xs text-muted">{{ $t('admin.memberships.missingItems') }}</p>
              <ul class="mt-1 flex flex-wrap gap-1.5"><li v-for="x in missing" :key="x" class="text-xs rounded-full border border-warning/30 bg-warning/10 text-warning px-2.5 py-0.5">{{ missingLabels[x] ?? x }}</li></ul>
            </template>
          </div>
          <div class="card card-pad">
            <h3 class="card-title">{{ $t('admin.memberships.decision') }}</h3>
            <p v-if="m.completion_note" class="mt-3 text-sm text-text rounded-xl border border-border bg-ink/5 px-3 py-2"><span class="block text-xs text-muted">{{ $t('admin.memberships.completionNote') }}</span>{{ m.completion_note }}</p>
            <p v-if="m.decision_note" class="mt-3 text-sm text-text rounded-xl border border-border bg-ink/5 px-3 py-2"><span class="block text-xs text-muted">{{ $t('admin.memberships.decisionNote') }}</span>{{ m.decision_note }}</p>
            <FormField v-model="decision.values.decision_note" name="decision_note" type="textarea" :label="$t('admin.memberships.decisionNote')" :help="$t('admin.memberships.noteRequired')" :error="decision.errors.value.decision_note" :rows="3" :maxlength="RULES.noteMax" class="mt-3" />
            <FormAlert :message="decision.formError.value" class="mt-3" />
            <div class="mt-3 flex flex-col gap-2">
              <AppButton v-for="s in m.allowed_transitions" :key="s" :variant="btn[s]?.variant ?? 'secondary'" :loading="decision.submitting.value && target === s" :disabled="decision.submitting.value && target !== s" @click="transition(s)">{{ s === 'approved' && m.status === 'suspended' ? $t('admin.memberships.reactivate') : btn[s]?.label ?? label('membershipStatus', s) }}</AppButton>
            </div>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>
