<script setup lang="ts">
/** ADM-06 — user file (GET /admin/users/{id}): basics, type, roles, status, verification, 2FA, membership + history,
 *  linked opportunities (owners) or interests / nominations (investors), and the user's audit activity.
 *  `users.manage` sees any user; `memberships.review` sees investors. Linked lists / activity follow the API's `can`. */
import type { AdminUserFile } from '~/types/api'
import { parseApiError } from '~/utils/apiError'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const { label } = useEnumLabel()
const { date, dateTime, percent } = useFormat()
const id = route.params.id as string
const failed = ref<number | null>(null)
const { data: u, loading } = useAsyncResource(() => api.get<{ data: AdminUserFile }>(`/admin/users/${id}`).then((r) => r.data).catch((e: unknown) => { failed.value = parseApiError(e).status ?? 500; return null }))
watch(u, (v) => v && useHead({ title: `${v.name} — ${t('admin.userFile.title')}` }))
const isInvestor = computed(() => !!u.value?.type.startsWith('investor'))
const isOwner = computed(() => !!u.value?.type.startsWith('entrepreneur'))
const listPath = computed(() => (auth.canAny('users.manage') ? '/app/admin/users' : '/app/admin/memberships'))
const listTitle = computed(() => (auth.canAny('users.manage') ? t('admin.users.title') : t('admin.memberships.title')))
const roleLabel = (name: string) => { const l = label('role', name); return l === name ? name : l }
const verified = (at: string | null) => (at ? `${t('common.yes')} — ${date(at)}` : t('common.no'))
const activityTab = ref<'all' | 'actor' | 'subject'>('all')
const activity = computed(() => (u.value?.activity ?? []).filter((a) => activityTab.value === 'all' || a.role === activityTab.value))
</script>

<template>
  <div>
    <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath(listPath)" class="hover:text-blue">{{ listTitle }}</NuxtLink> / <span class="num">#{{ id }}</span></nav>
    <div v-if="loading && !u" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="failed" :title="failed === 403 ? $t('admin.userFile.forbidden') : $t('admin.userFile.loadFailed')" />
    <template v-else-if="u">
      <PageHeader :title="u.name" :sub="[u.type_label ?? label('userType', u.type), u.organization?.name].filter(Boolean).join(' · ')">
        <template #actions>
          <AppBadge kind="generic" :value="u.status" :label="u.status === 'active' ? $t('admin.listFilters.active') : $t('admin.listFilters.suspended')" size="md" />
          <AppButton v-if="u.membership && u.can.review_membership" size="sm" variant="outline" :to="$localePath(`/app/admin/memberships/${u.membership.id}`)">{{ $t('admin.userFile.openMembership') }}</AppButton>
          <AppButton v-if="u.can.view_activity && auth.canAny('audit.view')" size="sm" variant="ghost" :to="$localePath({ path: '/app/admin/audit-logs', query: { user: String(u.id) } })">{{ $t('admin.userFile.fullAudit') }}</AppButton>
        </template>
      </PageHeader>
      <InlineAlert v-if="u.anonymized_at" tone="warning" class="mb-4">{{ $t('admin.userFile.anonymized', { date: date(u.anonymized_at) }) }}</InlineAlert>

      <div class="grid lg:grid-cols-[1fr_340px] gap-6 items-start">
        <div class="space-y-5">
          <SectionCard :title="$t('admin.userFile.basics')" :sub="label('sensitivity', 'sensitive_personal')">
            <KeyValue :items="[
              { label: $t('common.email'), value: u.email, ltr: true },
              { label: $t('common.phone'), value: u.phone, ltr: true, num: true },
              { label: $t('admin.memberships.columns.type'), value: u.type_label ?? label('userType', u.type) },
              { label: $t('admin.userFile.organization'), value: u.organization?.name },
              { label: $t('common.language'), value: u.locale === 'en' ? $t('common.english') : $t('common.arabic') },
              { label: $t('admin.userFile.preferredContact'), value: u.preferred_contact },
              { label: $t('admin.userFile.registeredAt'), value: date(u.created_at), num: true },
              { label: $t('admin.users.lastLogin'), value: dateTime(u.last_login_at), num: true },
            ]" />
          </SectionCard>

          <SectionCard v-if="isInvestor" :title="$t('admin.userFile.membershipHistory')">
            <ol class="relative border-s border-border ms-2 space-y-4">
              <li v-for="h in [...u.membership_history].reverse()" :key="h.id" class="ms-5">
                <span class="absolute -start-[5px] mt-1.5 h-2.5 w-2.5 rounded-full bg-teal" aria-hidden="true" />
                <div class="flex flex-wrap items-center gap-2 text-sm">
                  <AppBadge v-if="h.from" kind="membership" :value="h.from" /><span v-if="h.from" class="text-muted" aria-hidden="true">→</span><AppBadge v-if="h.to" kind="membership" :value="h.to" />
                  <span v-if="h.action === 'membership.submitted'" class="text-xs text-muted">{{ $t('admin.userFile.submittedByInvestor') }}</span>
                </div>
                <p class="text-xs text-muted mt-1"><span class="num">{{ dateTime(h.at) }}</span> · {{ h.actor?.name ?? $t('admin.audit.system') }}</p>
                <p v-if="h.note" class="text-sm text-text/85 mt-1">{{ h.note }}</p>
              </li>
              <li v-if="!u.membership_history.length" class="ms-5 text-sm text-muted">{{ $t('common.noData') }}</li>
            </ol>
          </SectionCard>

          <SectionCard v-if="isOwner" :title="$t('admin.userFile.opportunities')" flush>
            <p v-if="!u.can.view_opportunities" class="p-4 text-sm text-muted">{{ $t('admin.userFile.noPermission') }}</p>
            <div v-else class="overflow-x-auto"><table class="table-base">
              <thead><tr><th>{{ $t('admin.opportunities.columns.ref') }}</th><th>{{ $t('admin.opportunities.columns.name') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('entrepreneur.dashboard.submittedAt') }}</th></tr></thead>
              <tbody>
                <tr v-for="o in u.opportunities" :key="o.uuid"><td class="num text-muted">{{ o.reference_no ?? '—' }}</td><td><NuxtLink :to="$localePath(`/app/admin/opportunities/${o.uuid}`)" class="font-medium hover:text-blue">{{ o.name }}</NuxtLink></td><td><AppBadge kind="opportunity" :value="o.status" /></td><td class="num">{{ date(o.submitted_at) }}</td></tr>
                <tr v-if="!u.opportunities.length"><td colspan="4" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
              </tbody>
            </table></div>
          </SectionCard>

          <div v-if="isInvestor" class="grid md:grid-cols-2 gap-5">
            <SectionCard :title="$t('admin.interests.title')">
              <p v-if="!u.can.view_matching" class="text-sm text-muted">{{ $t('admin.userFile.noPermission') }}</p>
              <ul v-else class="space-y-2 text-sm">
                <li v-for="i in u.interests" :key="i.id" class="rounded-lg bg-bg p-3 flex justify-between gap-2"><span class="min-w-0"><NuxtLink v-if="i.opportunity" :to="$localePath(`/app/admin/opportunities/${i.opportunity.uuid}`)" class="font-medium hover:text-blue">{{ i.opportunity.name }}</NuxtLink><span class="block text-xs text-muted num">{{ i.opportunity?.reference_no ?? '' }} · {{ date(i.created_at) }}</span></span><AppBadge kind="interest" :value="i.status" /></li>
                <li v-if="!u.interests.length" class="text-muted">{{ $t('common.noData') }}</li>
              </ul>
            </SectionCard>
            <SectionCard :title="$t('admin.opportunity.tabs.nominations')">
              <p v-if="!u.can.view_matching" class="text-sm text-muted">{{ $t('admin.userFile.noPermission') }}</p>
              <ul v-else class="space-y-2 text-sm">
                <li v-for="n in u.nominations" :key="n.id" class="rounded-lg bg-bg p-3"><div class="flex justify-between gap-2"><NuxtLink v-if="n.opportunity" :to="$localePath(`/app/admin/opportunities/${n.opportunity.uuid}`)" class="font-medium hover:text-blue">{{ n.opportunity.name }}</NuxtLink><AppBadge kind="generic" :value="n.response ?? 'pending'" :label="n.response ? label('nominationResponse', n.response) : $t('common.new')" /></div><p class="text-xs text-muted mt-1"><span class="num">{{ date(n.sent_at) }}</span> · {{ $t('investor.detail.matchScore', { n: n.match_score }) }}</p></li>
                <li v-if="!u.nominations.length" class="text-muted">{{ $t('common.noData') }}</li>
              </ul>
            </SectionCard>
          </div>

          <SectionCard v-if="u.can.view_activity" :title="$t('admin.userFile.activity')" :sub="$t('admin.userFile.activitySub')">
            <template #actions>
              <div class="flex gap-1" role="group" :aria-label="$t('admin.userFile.activity')"><button v-for="k in (['all', 'actor', 'subject'] as const)" :key="k" type="button" class="chip text-xs" :class="activityTab === k && 'chip-active'" :aria-pressed="activityTab === k" @click="activityTab = k">{{ $t(`admin.userFile.activityFilter.${k}`) }}</button></div>
            </template>
            <ul class="divide-y divide-border">
              <li v-for="a in activity" :key="a.uuid" class="py-2.5 flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3 text-sm">
                <span class="text-xs text-muted num whitespace-nowrap sm:w-36 shrink-0">{{ dateTime(a.at) }}</span>
                <div class="flex-1 min-w-0">
                  <AuditAction :action="a.action" :after="a.after" :auditable-type="a.auditable_type" :auditable-id="a.auditable_id" show-subject />
                  <p class="text-xs text-muted mt-0.5">{{ a.role === 'actor' ? $t('admin.userFile.byUser') : `${$t('admin.userFile.aboutUser')} — ${a.actor?.name ?? $t('admin.audit.system')}` }}<template v-if="a.ip"> · <span class="ltr num">{{ a.ip }}</span></template></p>
                </div>
              </li>
              <li v-if="!activity.length" class="py-2.5 text-sm text-muted">{{ $t('common.noData') }}</li>
            </ul>
          </SectionCard>
        </div>

        <aside class="space-y-4 lg:sticky lg:top-6">
          <div class="card card-pad">
            <h3 class="font-semibold">{{ $t('admin.userFile.security') }}</h3>
            <KeyValue class="mt-3" :cols="1" :items="[
              { label: $t('admin.userFile.emailVerified'), value: verified(u.email_verified_at) },
              { label: $t('admin.userFile.phoneVerified'), value: verified(u.phone_verified_at) },
              { label: $t('admin.userFile.twoFactor'), value: u.two_factor_enabled ? verified(u.two_factor_confirmed_at) : $t('common.no') },
            ]" />
            <template v-if="u.roles.length">
              <p class="mt-4 text-xs text-muted">{{ $t('admin.users.roles') }}</p>
              <ul class="mt-1 flex flex-wrap gap-1"><li v-for="r in u.roles" :key="r" class="text-xs rounded-full bg-bg px-2 py-0.5">{{ roleLabel(r) }}</li></ul>
            </template>
          </div>
          <div v-if="isInvestor" class="card card-pad">
            <div class="flex items-center justify-between gap-2"><h3 class="font-semibold">{{ $t('admin.userFile.membership') }}</h3><AppBadge v-if="u.membership" kind="membership" :value="u.membership.status" /></div>
            <KeyValue v-if="u.membership" class="mt-3" :cols="1" :items="[
              { label: $t('admin.memberships.columns.submitted'), value: date(u.membership.submitted_at), num: true },
              { label: $t('admin.userFile.approvedAt'), value: date(u.membership.approved_at), num: true },
              { label: $t('admin.userFile.reviewedBy'), value: u.membership.reviewed_by },
              { label: $t('admin.userFile.approvedBy'), value: u.membership.approved_by },
              { label: $t('admin.memberships.columns.completion'), value: percent(u.completion_percent), num: true },
            ]" />
            <p v-else class="mt-2 text-sm text-muted">{{ $t('common.noData') }}</p>
            <p v-if="u.membership?.decision_note" class="mt-3 text-sm text-muted rounded-lg bg-bg p-2"><span class="block text-xs">{{ $t('admin.memberships.decisionNote') }}</span>{{ u.membership.decision_note }}</p>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>
