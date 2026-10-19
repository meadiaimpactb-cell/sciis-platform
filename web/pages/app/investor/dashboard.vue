<script setup lang="ts">
/** Investor dashboard — doc §7.4 (INV-01) */
import type { DashboardInvestor } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.dashboard.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const { label } = useEnumLabel()
const { number, relative, dateTime } = useFormat()
const toast = useToast()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: DashboardInvestor }>('/investor/dashboard').then((r) => r.data))
const missingLabels: Record<string, string> = {
  city: t('auth.register.city'), job_title: t('auth.register.jobTitle'), sectors: t('auth.register.sectors'), stages: t('auth.register.stages'), investment_types: t('auth.register.investmentTypes'), regions: t('auth.register.regions'), investment_range: t('auth.register.investmentRange'), experience_desc: t('auth.register.experienceDesc'), prior_investments_count: t('auth.register.priorInvestmentsCount'), risk_appetite: t('auth.register.riskAppetite'), conflicts_disclosure: t('auth.register.conflicts'), commit_meetings: t('auth.register.commitMeetings'),
}
const statusGroup = (kind: string) => (kind === 'interest' ? 'interestStatus' : kind === 'info_request' ? 'infoRequestStatus' : 'generic')
const kindBadge = (kind: string) => (kind === 'interest' ? 'interest' : kind === 'info_request' ? 'info' : 'generic')
const requestLink = (kind: string) => (kind === 'interest' ? '/app/investor/interests' : kind === 'nomination' ? '/app/investor/nominations' : '/app/investor/info-requests')
const toggleSave = async (o: { uuid: string; is_saved?: boolean }) => {
  try {
    if (o.is_saved) await api.del(`/opportunities/${o.uuid}/save`)
    else await api.post(`/opportunities/${o.uuid}/save`)
    o.is_saved = !o.is_saved
    toast.success(t('common.saved'))
  } catch (e) { toast.error((e as Error).message) }
}
const submitMembership = async () => {
  await api.post('/investor/membership/submit')
  await auth.fetchMe(true)
  await refresh()
  toast.success(t('common.success'))
}
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.dashboard.welcome', { name: auth.me?.name })" :eyebrow="$t('portal.investor')" />
    <InlineAlert v-if="route.query.gate === 'membership'" tone="warning" :title="$t('portal.membershipGate.title')" class="mb-6">
      {{ $t('portal.membershipGate.text', { status: label('membershipStatus', auth.me?.membership?.status) }) }}
      <div class="mt-2 flex gap-2"><AppButton size="sm" to="/app/investor/profile">{{ $t('portal.membershipGate.cta') }}</AppButton><AppButton v-if="auth.me?.membership?.status === 'registered'" size="sm" variant="outline" @click="submitMembership">{{ $t('common.submit') }}</AppButton></div>
    </InlineAlert>

    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="data" class="grid gap-6 lg:grid-cols-3">
      <!-- completion + membership -->
      <SectionCard :title="$t('investor.dashboard.completion')" class="lg:col-span-1">
        <div class="flex items-center gap-5">
          <CompletionRing :percent="data.completion.percent" />
          <div class="min-w-0 flex-1">
            <p class="text-xs text-muted">{{ $t('investor.dashboard.membership') }}</p>
            <AppBadge kind="membership" :value="data.membership_status" size="md" class="mt-1" />
            <AppButton v-if="data.membership_status === 'registered'" size="sm" class="mt-3" @click="submitMembership">{{ $t('common.submit') }}</AppButton>
          </div>
        </div>
        <div v-if="data.completion.missing_items.length" class="mt-4">
          <p class="text-xs font-medium text-muted mb-2">{{ $t('investor.dashboard.missingItems') }}</p>
          <ul class="flex flex-wrap gap-1.5"><li v-for="m in data.completion.missing_items" :key="m" class="text-xs rounded-full bg-warning/10 text-warning px-2 py-0.5">{{ missingLabels[m] ?? m }}</li></ul>
          <AppButton to="/app/investor/profile" variant="outline" size="sm" class="mt-3">{{ $t('investor.dashboard.completeProfile') }}</AppButton>
        </div>
      </SectionCard>
      <!-- counters -->
      <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-4 gap-4">
        <StatCard :label="$t('investor.dashboard.counters.saved')" :value="number(data.counters.saved)" to="/app/investor/saved" />
        <StatCard :label="$t('investor.dashboard.counters.interests')" :value="number(data.counters.interests)" to="/app/investor/interests" tone="blue" />
        <StatCard :label="$t('investor.dashboard.counters.info_requests')" :value="number(data.counters.info_requests)" to="/app/investor/info-requests" />
        <StatCard :label="$t('investor.dashboard.counters.meetings')" :value="number(data.counters.meetings)" to="/app/investor/meetings" tone="success" />
      </div>

      <!-- requests table -->
      <SectionCard :title="$t('investor.dashboard.requestsTitle')" class="lg:col-span-2" flush>
        <div class="overflow-x-auto">
          <table class="table-base">
            <thead><tr><th>{{ $t('investor.dashboard.requestKind.interest') }}/{{ $t('investor.dashboard.requestKind.nomination') }}</th><th>{{ $t('admin.interests.opportunity') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('investor.dashboard.nextStep') }}</th><th>{{ $t('common.updatedAt') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in data.requests" :key="`${r.kind}-${r.id}`">
                <td><NuxtLink :to="$localePath(requestLink(r.kind))" class="link text-xs">{{ $t(`investor.dashboard.requestKind.${r.kind}`) }}</NuxtLink></td>
                <td><NuxtLink :to="$localePath(`/app/opportunities/${r.opportunity.uuid}`)" class="font-medium hover:text-blue">{{ r.opportunity.name }}</NuxtLink><span class="block text-xs text-muted num">{{ r.opportunity.reference_no }}</span></td>
                <td><AppBadge :kind="kindBadge(r.kind) as never" :value="r.status" :label="r.kind === 'nomination' ? $t('common.new') : undefined" /></td>
                <td class="text-muted max-w-xs">{{ r.next_step }}</td>
                <td class="text-muted whitespace-nowrap">{{ relative(r.updated_at) }}</td>
              </tr>
              <tr v-if="!data.requests.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
            </tbody>
          </table>
        </div>
      </SectionCard>

      <!-- next meeting + updates -->
      <div class="space-y-6">
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
              <NuxtLink v-if="u.href" :to="$localePath(u.href)" class="font-medium hover:text-blue">{{ u.title }}</NuxtLink>
              <p v-else class="font-medium">{{ u.title }}</p>
              <p class="text-muted text-xs mt-0.5">{{ u.body }}</p>
              <p class="text-muted text-[11px] mt-0.5">{{ relative(u.created_at) }}</p>
            </li>
          </ul>
        </SectionCard>
      </div>

      <!-- suggested -->
      <section class="lg:col-span-3">
        <div class="flex items-end justify-between gap-3 mb-4">
          <div><h2 class="text-lg font-semibold">{{ $t('investor.dashboard.suggestedTitle') }}</h2><p class="text-sm text-muted">{{ $t('investor.dashboard.suggestedSub') }}</p></div>
          <AppButton to="/app/opportunities" variant="outline" size="sm">{{ $t('investor.dashboard.viewAll') }}</AppButton>
        </div>
        <div v-if="data.suggested.length" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <OpportunityCard v-for="o in data.suggested" :key="o.uuid" :opportunity="o" :saveable="auth.isApprovedMember" @toggle-save="toggleSave" />
        </div>
        <EmptyState v-else compact :title="$t('investor.opportunities.empty')" />
        <Disclaimer variant="box" class="mt-5" />
      </section>
    </div>
  </div>
</template>
