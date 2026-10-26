<script setup lang="ts">
/**
 * IMP-01/02 — an indicator carried forward from the registration: baseline, target, latest verified value, progress
 * and trend chart, and the value history (investors receive verified values only).
 */
import type { FollowUpIndicator, FollowUpIndicatorValue } from '~/types/api'

const props = defineProps<{ indicator: FollowUpIndicator; downloadBase?: string | null }>()
defineSlots<{ header?(): unknown; 'value-actions'?(p: { value: FollowUpIndicatorValue }): unknown }>()
const { number, date, percent } = useFormat()
const { label } = useEnumLabel()
const bar = computed(() => Math.max(0, Math.min(100, props.indicator.progress_percent ?? 0)))
const unit = computed(() => (props.indicator.unit ? ` ${props.indicator.unit}` : ''))
const history = computed(() => [...props.indicator.values].sort((a, b) => b.period_end.localeCompare(a.period_end) || b.id - a.id))
</script>

<template>
  <article class="rounded-xl border border-border p-4 sm:p-5">
    <header class="flex flex-col sm:flex-row sm:items-start gap-3">
      <div class="flex-1 min-w-0">
        <h3 class="font-semibold text-ink">{{ indicator.name }} <span v-if="indicator.is_primary" class="ms-1 align-middle text-[10px] rounded-full bg-teal/10 text-teal-text px-1.5 py-0.5">{{ $t('followUp.impact.primary') }}</span></h3>
        <p v-if="indicator.definition" class="text-sm text-muted mt-0.5">{{ indicator.definition }}</p>
      </div>
      <div v-if="$slots.header" class="shrink-0"><slot name="header" /></div>
    </header>

    <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted">{{ $t('followUp.impact.baseline') }}</dt><dd class="num font-semibold">{{ number(indicator.baseline) }}{{ indicator.baseline != null ? unit : '' }}</dd></div>
      <div class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted">{{ $t('followUp.impact.target') }}</dt><dd class="num font-semibold">{{ number(indicator.target) }}{{ indicator.target != null ? unit : '' }}</dd></div>
      <div class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted">{{ $t('followUp.impact.latest') }}</dt><dd class="num font-semibold">{{ indicator.latest_verified ? `${number(indicator.latest_verified.value)}${unit}` : '—' }}</dd></div>
      <div class="rounded-lg bg-bg p-3"><dt class="text-xs text-muted">{{ $t('followUp.impact.progress') }}</dt><dd class="num font-semibold">{{ percent(indicator.progress_percent) }}</dd></div>
    </dl>
    <div class="mt-3 h-2.5 rounded-full bg-bg overflow-hidden" role="progressbar" :aria-valuenow="bar" aria-valuemin="0" aria-valuemax="100" :aria-label="$t('followUp.impact.progress')">
      <div class="h-full rounded-full bg-teal transition-all duration-500" :style="{ width: `${bar}%` }" />
    </div>
    <p class="mt-1 text-xs text-muted">
      <span v-if="indicator.frequency">{{ $t('followUp.impact.frequency') }}: {{ label('frequency', indicator.frequency) }}</span>
      <span v-if="indicator.verification_method"> · {{ $t('followUp.impact.verification') }}: {{ indicator.verification_method }}</span>
    </p>

    <ImpactTrendChart class="mt-4" :name="indicator.name" :values="indicator.values" :baseline="indicator.baseline" :target="indicator.target" :unit="indicator.unit" />

    <div v-if="history.length" class="mt-4 overflow-x-auto rounded-lg border border-border">
      <table class="table-base">
        <thead><tr><th>{{ $t('followUp.reports.period') }}</th><th>{{ $t('followUp.impact.value') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.notes') }}</th><th><span class="sr-only">{{ $t('common.actions') }}</span></th></tr></thead>
        <tbody>
          <tr v-for="v in history" :key="v.id" class="align-top">
            <td class="num whitespace-nowrap text-xs">{{ date(v.period_start) }} — {{ date(v.period_end) }}</td>
            <td class="num font-medium whitespace-nowrap">{{ number(v.value) }}{{ unit }}</td>
            <td><FollowUpBadge group="value" :value="v.status" /></td>
            <td class="text-xs max-w-xs">
              <p v-if="v.note">{{ v.note }}</p>
              <p v-if="v.review_note" class="mt-1" :class="v.status === 'needs_clarification' ? 'text-warning' : 'text-muted'">{{ $t('followUp.impact.reviewNote') }}: {{ v.review_note }}</p>
              <FollowUpAttachments v-if="downloadBase && v.evidence" class="mt-1.5" :documents="[v.evidence]" :download-base="downloadBase" />
              <span v-if="!v.note && !v.review_note && !v.evidence" class="text-muted">—</span>
            </td>
            <td class="text-end whitespace-nowrap"><slot name="value-actions" :value="v" /></td>
          </tr>
        </tbody>
      </table>
    </div>
  </article>
</template>
