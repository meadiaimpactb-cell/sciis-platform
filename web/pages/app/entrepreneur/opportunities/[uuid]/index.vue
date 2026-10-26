<script setup lang="ts">
/**
 * ENT-04 — the owner's project journey: current status and SLA, what is required now, the evaluation decision with its
 * improvement plan (EVL-03) and the full timeline (status changes + completion requests).
 * GET /entrepreneur/opportunities/{uuid} (+ …/checklist for the completion item labels).
 */
import type { Checklist, CompletionRequest, OpportunityFull } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.journey.metaTitle') })
const api = useApi()
const route = useRoute()
const { label } = useEnumLabel()
const { date, daysUntil } = useFormat()
const uuid = route.params.uuid as string
// ACC-08 — an entity colleague without "can act" sees the file read-only.
const canAct = computed(() => useAuthStore().me?.organization_access?.can_act !== false)

const { data: o, loading } = useAsyncResource(() => api.get<{ data: OpportunityFull }>(`/entrepreneur/opportunities/${uuid}`).then((r) => r.data))
const { data: checklist } = useAsyncResource(() => api.get<{ data: Checklist }>(`/entrepreneur/opportunities/${uuid}/checklist`).then((r) => r.data).catch(() => null))
watch(o, (v) => v && useHead({ title: `${t('entrepreneur.journey.metaTitle')} — ${v.name}` }))

const fieldLabels = computed<Record<string, string>>(() => Object.fromEntries((checklist.value?.items ?? []).map((i) => [i.key, i.label])))
const openRequests = computed(() => (o.value?.completion_requests ?? []).filter((c) => !c.resolved_at))
const itemLabel = (it: CompletionRequest['items'][number]) => (it.document_kind ? label('documentKind', it.document_kind) : it.field ? fieldLabels.value[it.field] ?? it.field : '—')
const slaLeft = computed(() => daysUntil(o.value?.sla_due_at ?? null))
</script>

<template>
  <div>
    <div v-if="loading && !o" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else-if="o">
      <PageHeader :title="o.name" :eyebrow="$t('entrepreneur.journey.title')" :sub="$t('entrepreneur.journey.sub')">
        <template #actions>
          <AppButton variant="ghost" size="sm" to="/app/entrepreneur/dashboard">{{ $t('common.back') }}</AppButton>
          <AppButton variant="secondary" size="sm" :to="`/app/entrepreneur/opportunities/${uuid}/preview`">{{ $t('entrepreneur.dashboard.fullPreview') }}</AppButton>
          <AppButton v-if="canAct" size="sm" :to="`/app/entrepreneur/opportunities/${uuid}/edit`">{{ $t('entrepreneur.dashboard.editProject') }}</AppButton>
        </template>
      </PageHeader>

      <div class="grid gap-6 lg:grid-cols-3 items-start">
        <!-- current status -->
        <SectionCard :title="$t('entrepreneur.dashboard.currentStatus')" class="lg:col-span-3">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <AppBadge kind="opportunity" :value="o.status" size="md" :soft="false" />
            <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
              <div v-if="o.reference_no"><dt class="inline">{{ $t('entrepreneur.dashboard.referenceNo') }}:</dt> <dd class="inline num text-ink font-medium">{{ o.reference_no }}</dd></div>
              <div v-if="o.submitted_at"><dt class="inline">{{ $t('entrepreneur.dashboard.submittedAt') }}:</dt> <dd class="inline text-ink font-medium num">{{ date(o.submitted_at) }}</dd></div>
              <div v-if="o.sla_due_at"><dt class="inline">{{ $t('entrepreneur.journey.nextStepBy') }}:</dt> <dd class="inline font-medium num" :class="(slaLeft ?? 0) < 0 ? 'text-danger' : 'text-ink'">{{ date(o.sla_due_at) }}</dd></div>
            </dl>
          </div>
          <StatusStrip :status="o.status" />
          <InlineAlert v-if="openRequests.length" tone="warning" class="mt-5" :title="$t('entrepreneur.journey.completionOpen')">
            <ul class="mt-1 space-y-0.5">
              <li v-for="c in openRequests" :key="c.id">
                {{ c.items.map(itemLabel).join('، ') }}<template v-if="c.due_at"> — {{ $t('entrepreneur.journey.dueAt') }}: <span class="num">{{ date(c.due_at) }}</span></template>
              </li>
            </ul>
            <AppButton size="sm" class="mt-3" :to="`/app/entrepreneur/opportunities/${uuid}/edit`">{{ $t('entrepreneur.journey.completeNow') }}</AppButton>
          </InlineAlert>
        </SectionCard>

        <!-- evaluation decision (EVL-03) -->
        <SectionCard :title="$t('components.evaluationDecision.title')" :class="o.evaluation_decision ? 'lg:col-span-2' : 'lg:col-span-1'">
          <EvaluationDecisionCard v-if="o.evaluation_decision" :decision="o.evaluation_decision" />
          <p v-else class="text-sm text-muted">{{ $t('components.evaluationDecision.pending') }}</p>
        </SectionCard>

        <!-- timeline (ENT-04) -->
        <SectionCard :title="$t('entrepreneur.journey.timelineTitle')" :sub="$t('entrepreneur.journey.timelineSub')" :class="o.evaluation_decision ? 'lg:col-span-1' : 'lg:col-span-2'">
          <OpportunityJourney :timeline="o.timeline ?? []" :completion-requests="o.completion_requests ?? []" :decision="o.evaluation_decision ?? null" :field-labels="fieldLabels" />
        </SectionCard>

        <!-- MTG-03 — messages with the council on this file -->
        <SectionCard :title="$t('collab.messages.withCouncil')" :sub="$t('collab.messages.withCouncilSub')" class="lg:col-span-3">
          <LazyMessageThread subject-type="opportunity" :subject-key="uuid" />
        </SectionCard>
      </div>
    </template>
    <EmptyState v-else :title="$t('common.noData')" />
  </div>
</template>
