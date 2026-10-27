<script setup lang="ts">
/**
 * Uniform opportunity card (OPP-03) — public fields only (SPEC §5).
 * Tolerates both API shapes: `funding_display` as object or legacy mode string, `investment_types` as enum keys
 * or taxonomy terms, `teaser_summary` or nested `teaser.summary` / `short_description`.
 */
import type { OpportunityCard, FundingDisplay } from '~/types/api'

const props = withDefaults(defineProps<{ opportunity: OpportunityCard; to?: string | null; saveable?: boolean; compact?: boolean; showStatus?: boolean }>(), { saveable: false, compact: false, showStatus: true, to: undefined })
const emit = defineEmits<{ (e: 'toggle-save', o: OpportunityCard): void }>()
const { term, label } = useEnumLabel()
const { sar, number } = useFormat()
const { t } = useI18n()

const fundingDisplay = computed<FundingDisplay>(() => {
  const o = props.opportunity
  const f = o.funding_display
  if (f && typeof f === 'object') return f
  const mode = (typeof f === 'string' ? f : o.funding_display_mode) ?? 'band'
  return { mode, band: o.funding_band ? term(o.funding_band) : null, amount: o.funding_amount ?? null }
})
const funding = computed(() => {
  const f = fundingDisplay.value
  if (f.mode === 'hidden') return t('investor.opportunities.hidden')
  if (f.mode === 'exact') return f.amount != null ? sar(f.amount) : (f.band ?? '—')
  return f.band ?? '—'
})
const summary = computed(() => props.opportunity.teaser_summary || props.opportunity.teaser?.summary || props.opportunity.short_description || '')
const types = computed(() => (props.opportunity.investment_type_keys?.length ? props.opportunity.investment_type_keys : props.opportunity.investment_types) ?? [])
const typesText = computed(() => types.value.map((k) => label('investmentType', k)).join('، '))
const link = computed(() => (props.to === null ? null : (props.to ?? `/app/opportunities/${props.opportunity.uuid}`)))
const variant = computed(() => ((props.opportunity.uuid.length % 3) + 1) as 1 | 2 | 3)
</script>

<template>
  <article class="card card-hover flex flex-col overflow-hidden h-full group" :aria-label="opportunity.name">
    <div class="relative">
      <PlaceholderImage :variant="variant" ratio="aspect-[16/9]" compact :intent="term(opportunity.sector)" />
      <div class="absolute top-3 inset-x-3 flex items-start justify-between gap-2">
        <div class="flex flex-wrap gap-1.5">
          <!-- on the fixed brand gradient (PlaceholderImage): frosted chips with white text, as the placeholder's own label -->
          <span v-if="opportunity.sector" class="rounded-full border border-white/25 bg-white/15 backdrop-blur text-white text-xs px-2.5 py-1 font-medium">{{ term(opportunity.sector) }}</span>
          <AppBadge v-if="showStatus && opportunity.status !== 'eligible'" kind="opportunity" :value="opportunity.status" :soft="false" />
        </div>
        <button
          v-if="saveable"
          type="button"
          class="h-9 w-9 shrink-0 rounded-full border backdrop-blur flex items-center justify-center kbd-focus transition"
          :class="opportunity.is_saved ? 'border-accent bg-accent text-on-accent hover:bg-accent-hi' : 'border-white/25 bg-white/15 text-white hover:bg-white/25'"
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
          <span v-if="opportunity.is_nominated" class="rounded-full border border-accent/35 bg-accent/10 text-accent-text px-2.5 py-0.5 font-medium">{{ $t('investor.detail.nominatedFor') }}</span>
        </div>
        <h3 class="card-title !leading-snug">
          <NuxtLink v-if="link" :to="$localePath(link)" class="group-hover:text-accent-text focus-visible:text-accent-text outline-none transition">{{ opportunity.name }}</NuxtLink>
          <template v-else>{{ opportunity.name }}</template>
        </h3>
        <p v-if="opportunity.organization_name" class="text-sm text-muted">{{ opportunity.organization_name }}</p>
      </div>
      <p class="text-sm text-text leading-6 line-clamp-2">{{ summary }}</p>
      <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs mt-auto pt-3 border-t border-border">
        <div><dt class="text-muted">{{ $t('investor.detail.stage') }}</dt><dd class="font-medium text-ink">{{ label('stage', opportunity.stage) }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.need') }}</dt><dd class="font-medium text-ink num">{{ funding }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.type') }}</dt><dd class="font-medium text-ink truncate" :title="typesText">{{ typesText || '—' }}</dd></div>
        <div><dt class="text-muted">{{ $t('investor.detail.beneficiaries') }}</dt><dd class="font-medium text-ink num">{{ number(opportunity.beneficiaries_target) }}</dd></div>
      </dl>
      <div v-if="opportunity.regions?.length" class="flex flex-wrap gap-1.5 text-xs text-muted">
        <span v-for="r in opportunity.regions" :key="r.id" class="rounded-full border border-ink/15 px-2 py-0.5">{{ term(r) }}</span>
      </div>
      <!-- page-specific actions (e.g. OPP-12 "compare" in the catalogue) -->
      <slot name="footer" />
    </div>
  </article>
</template>
