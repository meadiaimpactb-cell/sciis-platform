<script setup lang="ts">
/** EVL-03 — the evaluation decision as the owner sees it: result, conditions / reasons, improvement plan, decision date. */
import type { EvaluationDecisionOwner } from '~/types/api'
defineProps<{ decision: EvaluationDecisionOwner }>()
const { date, daysUntil } = useFormat()
const late = (iso: string | null) => !!iso && (daysUntil(iso) ?? 0) < 0
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <AppBadge kind="eval" :value="decision.result" size="md" :soft="false" />
      <span class="text-xs text-muted">{{ $t('components.evaluationDecision.decidedAt') }}: <span class="num text-ink font-medium">{{ date(decision.decided_at) }}</span></span>
    </div>
    <p class="text-desc text-muted">{{ $t(`components.evaluationDecision.hints.${decision.result}`) }}</p>

    <section v-if="decision.conditions">
      <h3 class="text-sm font-semibold">{{ $t('components.evaluationDecision.conditions') }}</h3>
      <p class="mt-1 text-sm leading-7 text-text/85 whitespace-pre-line">{{ decision.conditions }}</p>
    </section>
    <section v-if="decision.reasons">
      <h3 class="text-sm font-semibold">{{ $t('components.evaluationDecision.reasons') }}</h3>
      <p class="mt-1 text-sm leading-7 text-text/85 whitespace-pre-line">{{ decision.reasons }}</p>
    </section>

    <section v-if="decision.improvement_plan.length">
      <h3 class="text-sm font-semibold">{{ $t('components.evaluationDecision.plan') }}</h3>
      <p class="text-xs text-muted mt-0.5">{{ $t('components.evaluationDecision.planSub') }}</p>
      <ol class="mt-3 space-y-2">
        <li v-for="(p, i) in decision.improvement_plan" :key="i" class="flex items-start gap-3 rounded-lg border border-border p-3">
          <span class="num h-6 w-6 shrink-0 rounded-full bg-blue-soft text-blue text-xs font-semibold flex items-center justify-center" aria-hidden="true">{{ i + 1 }}</span>
          <div class="min-w-0">
            <p class="text-sm leading-6">{{ p.text }}</p>
            <p v-if="p.due_at" class="text-xs mt-0.5" :class="late(p.due_at) ? 'text-danger' : 'text-muted'">{{ $t('components.evaluationDecision.planDue') }}: <span class="num">{{ date(p.due_at) }}</span></p>
          </div>
        </li>
      </ol>
    </section>
  </div>
</template>
