<script setup lang="ts">
/**
 * INV-06 — one linked project (GET /investor/portfolio/{uuid}): execution status (PRJ-03), the approved milestone
 * plan (PRJ-01), accepted progress reports (PRJ-02), verified impact values with progress and charts (IMP-01/02)
 * and the owner's updates (ENT-10). Attachments open through /investor/portfolio/documents/{uuid}/download-url.
 */
import type { FollowUpDetail } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
const route = useRoute()
const api = useApi()
const { term } = useEnumLabel()
const DOCS = '/investor/portfolio/documents'
const { data, loading, error } = useAsyncResource(() => api.get<{ data: FollowUpDetail }>(`/investor/portfolio/${String(route.params.uuid)}`).then((r) => r.data))
useHead(() => ({ title: data.value ? `${data.value.name} — ${t('followUp.investor.metaTitle')}` : t('followUp.investor.metaTitle') }))
const tab = ref('plan')
const tabs = computed(() => [
  { key: 'plan', label: t('followUp.tabs.plan'), count: data.value?.milestones_list.length ?? 0 },
  { key: 'reports', label: t('followUp.tabs.reports'), count: data.value?.reports.length ?? 0 },
  { key: 'impact', label: t('followUp.tabs.impact'), count: data.value?.indicators.length ?? 0 },
  { key: 'updates', label: t('followUp.tabs.updates'), count: data.value?.updates.length ?? 0 },
])
const sector = computed(() => (data.value?.sector ? term(data.value.sector as never) : null))
</script>

<template>
  <div>
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="error && !data" :title="$t('followUp.investor.notFound')"><AppButton size="sm" variant="secondary" to="/app/investor/portfolio">{{ $t('common.back') }}</AppButton></EmptyState>
    <template v-else-if="data">
      <PageHeader :title="data.name" :eyebrow="$t('followUp.investor.title')" :sub="[sector, data.reference_no].filter(Boolean).join(' · ')">
        <template #actions>
          <AppButton variant="ghost" size="sm" to="/app/investor/portfolio">{{ $t('common.back') }}</AppButton>
          <AppButton variant="secondary" size="sm" :to="`/app/opportunities/${data.uuid}`">{{ $t('followUp.investor.openOpportunity') }}</AppButton>
        </template>
      </PageHeader>
      <div class="space-y-6">
        <FollowUpStatusPanel v-if="data.in_follow_up" :detail="data" audience="investor" />
        <InlineAlert v-else>{{ $t('followUp.investor.notStarted') }}</InlineAlert>

        <div v-if="data.in_follow_up">
          <Tabs v-model="tab" :tabs="tabs" />
          <div v-show="tab === 'plan'" id="panel-plan" role="tabpanel" aria-labelledby="tab-plan" class="mt-5">
            <SectionCard :title="$t('followUp.plan.title')">
              <FollowUpMilestones v-if="data.milestones_list.length" :milestones="data.milestones_list" />
              <p v-else class="text-sm text-muted">{{ $t('followUp.investor.planPending') }}</p>
            </SectionCard>
          </div>
          <div v-show="tab === 'reports'" id="panel-reports" role="tabpanel" aria-labelledby="tab-reports" class="mt-5">
            <SectionCard :title="$t('followUp.reports.title')" :sub="$t('followUp.investor.reportsSub')">
              <div v-if="data.reports.length" class="space-y-4"><FollowUpReportCard v-for="r in data.reports" :key="r.id" :report="r" :download-base="DOCS" /></div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.reports.empty') }}</p>
            </SectionCard>
          </div>
          <div v-show="tab === 'impact'" id="panel-impact" role="tabpanel" aria-labelledby="tab-impact" class="mt-5">
            <SectionCard :title="$t('followUp.impact.title')" :sub="$t('followUp.impact.verifiedOnly')">
              <div v-if="data.indicators.length" class="space-y-5">
                <BarChart v-if="data.indicators.length > 1" :series="data.indicators.map((i) => ({ label: i.name, value: Math.max(0, i.progress_percent ?? 0), color: 'var(--scii-teal)' }))" :max="100" unit="%" />
                <FollowUpIndicatorCard v-for="i in data.indicators" :key="i.id" :indicator="i" />
              </div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.impact.noIndicators') }}</p>
            </SectionCard>
          </div>
          <div v-show="tab === 'updates'" id="panel-updates" role="tabpanel" aria-labelledby="tab-updates" class="mt-5">
            <SectionCard :title="$t('followUp.updates.investorsTitle')">
              <div v-if="data.updates.length" class="space-y-4"><FollowUpUpdateCard v-for="u in data.updates" :key="u.id" :update="u" :download-base="DOCS" /></div>
              <p v-else class="text-sm text-muted">{{ $t('followUp.updates.empty') }}</p>
            </SectionCard>
          </div>
        </div>
        <SectionCard v-else-if="data.updates.length" :title="$t('followUp.updates.investorsTitle')">
          <div class="space-y-4"><FollowUpUpdateCard v-for="u in data.updates" :key="u.id" :update="u" :download-base="DOCS" /></div>
        </SectionCard>
      </div>
    </template>
  </div>
</template>
