<script setup lang="ts">
/** Uniform opportunity card (OPP-03) — public fields only (SPEC §5). */
import type { OpportunityCard } from '~/types/api'

const props = withDefaults(defineProps<{ opportunity: OpportunityCard; to?: string | null; saveable?: boolean; compact?: boolean; showStatus?: boolean }>(), { saveable: false, compact: false, showStatus: true, to: undefined })
const emit = defineEmits<{ (e: 'toggle-save', o: OpportunityCard): void }>()
const { term, label } = useEnumLabel()
const { sar, number } = useFormat()
const { t } = useI18n()

const funding = computed(() => {
  const f = props.opportunity.funding_display
  if (f.mode === 'hidden') return t('investor.opportunities.hidden')
  if (f.mode === 'exact') return sar(f.amount)
  return f.band ?? '—'
})
const link = computed(() => (props.to === null ? null : (props.to ?? `/app/opportunities/${props.opportunity.uuid}`)))
const variant = computed(() => ((props.opportunity.uuid.length % 3) + 1) as 1 | 2 | 3)
</script>

<template>
  <article class="card flex flex-col overflow-hidden transition hover:shadow-pop/40 hover:border-blue/40 h-full" :aria-label="opportunity.name">
    <div class="relative">
      <PlaceholderImage :variant="variant" ratio="aspect-[16/9]" compact :intent="term(opportunity.sector)" />
      <div class="absolute top-3 inset-x-3 flex items-start justify-between gap-2">
        <div class="flex flex-wrap gap-1.5">
          <span class="rounded-full bg-white/90 text-ink text-xs px-2.5 py-1 font-medium">{{ term(opportunity.sector) }}</span>
          <AppBadge v-if="showStatus && opportunity.status !== 'eligible'" kind="opportunity" :value="opportunity.status" :soft="false" />
        </div>
        <button
          v-if="saveable"
          type="button"
          class="h-8 w-8 rounded-full bg-white/90 flex items-center justify-center kbd-focus transition hover:bg-white"
          :class="opportunity.is_saved ? 'text-blue' : 'text-muted'"
          :aria-pressed="opportunity.is_saved"
          :aria-label="opportunity.is_saved ? $t('investor.opportunities.unsaveBtn') : $t('investor.opportunities.saveBtn')"
          @click.prevent="emit('toggle-save', opportunity)"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="opportunity.is_saved ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 4h12v17l-6-4-6 4V4z" /></svg>
        </button>
      </div>
    </div>
    <div class="p-4 sm:p-5 flex flex-col gap-3 flex-1">
      <div>
        <div class="flex items-center gap-2 text-xs text-muted mb-1">
          <span v-if="opportunity.reference_no" class="num">{{ opportunity.reference_no }}</span>
          <span v-if="opportunity.is_nominated" class="rounded-full bg-blue-soft text-blue px-2 py-0.5 font-medium">{{ $t('investor.detail.nominatedFor') }}</span>
        </div>
        <h3 class="text-lg font-semibold leading-snug">
          <NuxtLink v-if="link" :to="$localePath(link)" class="hover:text-blue focus-visible:text-blue outline-none">{{ opportunity.name }}</NuxtLink>
          <template v-else>{{ opportunity.name }}</template>
        </h3>
        <p v-if="opportunity.organization_name" class="text-sm text-muted">{{ opportunity.organization_name }}</p>
      </div>
      <p class="text-sm text-text/85 leading-6 line-clamp-2">{{ opportunity.teaser_summary }}</p>
      <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs mt-auto pt-2 border-t border-border">
        <div><dt class="text-muted">{{ $t('investor.detail.stage') }}</dt><dd class="font-medium text-ink">{{ label('stage', opportunity.stage) }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.need') }}</dt><dd class="font-medium text-ink num">{{ funding }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.type') }}</dt><dd class="font-medium text-ink truncate">{{ opportunity.investment_types.map((k) => label('investmentType', k)).join('، ') }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.beneficiaries') }}</dt><dd class="font-medium text-ink num">{{ number(opportunity.beneficiaries_target) }}</dd></div>
      </dl>
      <div class="flex flex-wrap gap-1 text-[11px] text-muted">
        <span v-for="r in opportunity.regions" :key="r.id" class="rounded bg-bg px-1.5 py-0.5">{{ term(r) }}</span>
      </div>
    </div>
  </article>
</template>
