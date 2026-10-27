<script setup lang="ts">
/** Investor dashboard — doc §7.4 (INV-01). Membership submission requires accepting the terms (INV-02). */
import { z } from 'zod'
import type { DashboardInvestor } from '~/types/api'
import { acceptField } from '~/utils/validation'
import { toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t, te } = useI18n()
useHead({ title: t('investor.dashboard.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
const { label } = useEnumLabel()
const { number, relative, dateTime, date, daysUntil } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: DashboardInvestor }>('/investor/dashboard').then((r) => r.data))
const missingLabels: Record<string, string> = {
  country: t('auth.register.country'), city: t('auth.register.city'), job_title: t('auth.register.jobTitle'), sectors: t('auth.register.sectors'), stages: t('auth.register.stages'), investment_types: t('auth.register.investmentTypes'), regions: t('auth.register.regions'), investment_range: t('auth.register.investmentRange'), experience_years: t('auth.register.experienceYears'), experience_desc: t('auth.register.experienceDesc'), prior_investments_count: t('auth.register.priorInvestmentsCount'), risk_appetite: t('auth.register.riskAppetite'), preferred_contact: t('auth.register.preferredContact'), how_heard: t('auth.register.howHeard'), conflicts_disclosure: t('auth.register.conflicts'), commit_meetings: t('auth.register.commitMeetings'),
}
const kindBadge = (kind: string) => (kind === 'interest' ? 'interest' : kind === 'info_request' ? 'info' : 'generic')
const requestLink = (kind: string) => (kind === 'interest' ? '/app/investor/interests' : kind === 'nomination' ? '/app/investor/nominations' : '/app/investor/info-requests')
/** API: `next_step_key` (e.g. `interest.sent`) → localized text; falls back to the API's own `next_step` label. */
const nextStep = (r: DashboardInvestor['requests'][number]) => {
  const key = `investor.dashboard.nextSteps.${(r.next_step_key ?? `${r.kind}.${r.status}`).replace(/\./g, '_')}`
  return te(key) ? t(key) : r.next_step
}
const toggleSave = async (o: { uuid: string; name?: string; is_saved?: boolean }) => {
  try {
    if (o.is_saved) await api.del(`/opportunities/${o.uuid}/save`)
    else await api.post(`/opportunities/${o.uuid}/save`)
    o.is_saved = !o.is_saved
    toastSuccess(t(o.is_saved ? 'notify.savedToList' : 'notify.removedFromList', { name: o.name ?? t('notify.entities.opportunity') }))
  } catch (e) { toastApiError(e) }
}
// INV-02 — POST /investor/membership/submit { accept_terms, terms_version } (SubmitMembershipRequest: accept_terms accepted)
const termsOpen = ref(false)
const termsForm = useZodForm(z.object({ accept_terms: acceptField }), { accept_terms: false })
const termsVersion = computed(() => tax.settings?.legal_versions?.terms ?? '1.0')
const canSubmit = computed(() => ['registered', 'needs_completion'].includes(String(data.value?.membership_status ?? auth.me?.membership?.status)))
const openTerms = () => { termsForm.reset(); termsOpen.value = true }
const submitMembership = () => termsForm.submit(async () => {
  await api.post('/investor/membership/submit', { accept_terms: true, terms_version: termsVersion.value })
  termsOpen.value = false
  toastSuccess(t('notify.submitted', { item: t('notify.entities.membership') }))
  await auth.fetchMe(true)
  await refresh()
})
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.dashboard.welcome', { name: auth.me?.name })" :eyebrow="$t('portal.investor')" />
    <InlineAlert v-if="route.query.gate === 'membership'" tone="warning" :title="$t('portal.membershipGate.title')" class="mb-6">
      {{ $t('portal.membershipGate.text', { status: label('membershipStatus', auth.me?.membership?.status) }) }}
      <div class="mt-2 flex gap-2"><AppButton size="sm" to="/app/investor/profile">{{ $t('portal.membershipGate.cta') }}</AppButton><AppButton v-if="canSubmit" size="sm" variant="outline" @click="openTerms">{{ $t('investor.dashboard.submitMembership') }}</AppButton></div>
    </InlineAlert>

    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="data" class="grid gap-6 lg:grid-cols-3 items-start">
      <!-- main column: counters (template C stat row) + requests -->
      <div class="lg:col-span-2 space-y-6 min-w-0">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <StatCard :label="$t('investor.dashboard.counters.saved')" :value="number(data.counters.saved)" to="/app/investor/saved" />
          <StatCard :label="$t('investor.dashboard.counters.interests')" :value="number(data.counters.interests)" to="/app/investor/interests" tone="blue" />
          <StatCard :label="$t('investor.dashboard.counters.info_requests')" :value="number(data.counters.info_requests)" to="/app/investor/info-requests" />
          <StatCard :label="$t('investor.dashboard.counters.meetings')" :value="number(data.counters.meetings)" to="/app/investor/meetings" tone="success" />
        </div>

        <!-- requests table -->
        <SectionCard :title="$t('investor.dashboard.requestsTitle')" flush>
          <div class="overflow-x-auto">
            <table class="table-base">
              <thead><tr><th>{{ $t('investor.dashboard.requestKind.interest') }}/{{ $t('investor.dashboard.requestKind.nomination') }}</th><th>{{ $t('admin.interests.opportunity') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('investor.dashboard.nextStep') }}</th><th>{{ $t('common.updatedAt') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in data.requests ?? []" :key="`${r.kind}-${r.id}`">
                  <td><NuxtLink :to="$localePath(requestLink(r.kind))" class="link text-xs">{{ $t(`investor.dashboard.requestKind.${r.kind}`) }}</NuxtLink></td>
                  <td><NuxtLink :to="$localePath(`/app/opportunities/${r.opportunity.uuid}`)" class="font-medium text-ink hover:text-accent-text">{{ r.opportunity.name }}</NuxtLink><span class="block text-xs text-muted num">{{ r.opportunity.reference_no }}</span></td>
                  <td><AppBadge :kind="kindBadge(r.kind) as never" :value="r.status" :label="r.kind === 'nomination' ? $t('common.new') : undefined" /></td>
                  <td class="text-muted max-w-xs">{{ nextStep(r) }}</td>
                  <td class="text-muted whitespace-nowrap">{{ relative(r.updated_at) }}</td>
                </tr>
                <tr v-if="!data.requests?.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
              </tbody>
            </table>
          </div>
        </SectionCard>
      </div>

      <!-- side column: completion + membership, tasks, next meeting, updates -->
      <div class="space-y-6 min-w-0">
        <SectionCard :title="$t('investor.dashboard.completion')">
          <div class="flex items-center gap-5">
            <CompletionRing :percent="data.completion?.percent ?? data.completion_percent ?? 0" />
            <div class="min-w-0 flex-1">
              <p class="text-xs text-muted">{{ $t('investor.dashboard.membership') }}</p>
              <AppBadge kind="membership" :value="data.membership_status" size="md" class="mt-1" />
              <p v-if="auth.me?.membership?.completion_note" class="mt-2 text-xs text-warning">{{ auth.me.membership.completion_note }}</p>
              <AppButton v-if="canSubmit" size="sm" class="mt-3" @click="openTerms">{{ $t('investor.dashboard.submitMembership') }}</AppButton>
            </div>
          </div>
          <div v-if="data.completion?.missing_items?.length" class="mt-4">
            <p class="text-xs font-medium text-muted mb-2">{{ $t('investor.dashboard.missingItems') }}</p>
            <ul class="flex flex-wrap gap-1.5"><li v-for="m in data.completion.missing_items" :key="m" class="text-xs rounded-full border border-warning/30 bg-warning/10 text-warning px-2.5 py-0.5">{{ missingLabels[m] ?? m }}</li></ul>
            <AppButton to="/app/investor/profile" variant="outline" size="sm" class="mt-3">{{ $t('investor.dashboard.completeProfile') }}</AppButton>
          </div>
        </SectionCard>
        <!-- MTG-02 — meeting follow-up actions assigned to the investor -->
        <SectionCard v-if="data.tasks?.length" :title="$t('components.meetingActions.tasksTitle')" :sub="$t('components.meetingActions.tasksSub')">
          <ul class="divide-y divide-border">
            <li v-for="task in data.tasks" :key="task.id" class="py-2.5 first:pt-0 last:pb-0 flex flex-wrap items-center gap-2">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">{{ task.description }}</p>
                <p class="text-xs text-muted">{{ task.meeting?.title ?? '' }}<template v-if="task.opportunity"> · {{ task.opportunity.name }}</template></p>
                <p v-if="task.due_at" class="text-xs mt-0.5" :class="(daysUntil(task.due_at) ?? 0) < 0 ? 'text-danger' : 'text-muted'">{{ $t('components.meetingActions.dueAt') }}: <span class="num">{{ date(task.due_at) }}</span></p>
              </div>
              <MeetingActionToggle :id="task.id" :done="!!task.done_at" :description="task.description" @changed="refresh" />
            </li>
          </ul>
        </SectionCard>
        <SectionCard :title="$t('investor.dashboard.nextMeeting')">
          <div v-if="data.next_meeting">
            <p class="font-medium">{{ data.next_meeting.title }}</p>
            <p class="text-sm text-muted mt-1">{{ dateTime(data.next_meeting.starts_at) }}</p>
            <p class="text-xs text-muted mt-1">{{ data.next_meeting.participants.map((p) => p.name).join('، ') }}</p>
            <AppButton v-if="data.next_meeting.video_url" :href="data.next_meeting.video_url" size="sm" variant="outline" class="mt-3">{{ $t('investor.dashboard.joinMeeting') }}</AppButton>
          </div>
          <p v-else class="text-sm text-muted">{{ $t('investor.dashboard.noMeeting') }}</p>
        </SectionCard>
        <SectionCard :title="$t('investor.dashboard.updatesTitle')">
          <ul class="space-y-3">
            <li v-for="u in data.updates" :key="u.id" class="text-sm">
              <NuxtLink v-if="u.href" :to="$localePath(u.href)" class="font-medium text-ink hover:text-accent-text">{{ u.title }}</NuxtLink>
              <p v-else class="font-medium text-ink">{{ u.title }}</p>
              <p class="text-muted text-xs mt-0.5">{{ u.body }}</p>
              <p class="text-muted text-xs mt-0.5">{{ relative(u.created_at) }}</p>
            </li>
            <li v-if="!data.updates?.length" class="text-sm text-muted">{{ $t('common.noData') }}</li>
          </ul>
        </SectionCard>
      </div>

      <!-- suggested -->
      <section class="lg:col-span-3">
        <div class="flex items-end justify-between gap-3 mb-4">
          <div><h2 class="card-title">{{ $t('investor.dashboard.suggestedTitle') }}</h2><p class="text-sm text-muted mt-0.5">{{ $t('investor.dashboard.suggestedSub') }}</p></div>
          <AppButton to="/app/opportunities" variant="outline" size="sm">{{ $t('investor.dashboard.viewAll') }}</AppButton>
        </div>
        <div v-if="data.suggested.length" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <OpportunityCard v-for="o in data.suggested" :key="o.uuid" :opportunity="o" :saveable="auth.isApprovedMember" @toggle-save="toggleSave" />
        </div>
        <EmptyState v-else compact :title="$t('investor.opportunities.empty')" />
        <Disclaimer variant="box" class="mt-5" />
      </section>
    </div>

    <!-- INV-02: terms acceptance before submitting the application -->
    <Modal :open="termsOpen" :title="$t('investor.dashboard.termsTitle')" @close="termsOpen = false">
      <form class="space-y-4 text-sm leading-7" novalidate @submit.prevent="submitMembership">
        <p>{{ $t('investor.dashboard.termsText', { version: termsVersion }) }}</p>
        <p><NuxtLink :to="$localePath('/legal/terms')" class="link" target="_blank">{{ $t('footer.terms') }}</NuxtLink></p>
        <FormField v-model="termsForm.values.accept_terms" name="accept_terms" type="checkbox" :label="$t('investor.dashboard.acceptTerms')" :error="termsForm.errors.value.accept_terms" required />
      </form>
      <template #footer><FormAlert :message="termsForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="termsOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="termsForm.submitting.value" @click="submitMembership">{{ $t('common.submit') }}</AppButton></template>
    </Modal>
  </div>
</template>
