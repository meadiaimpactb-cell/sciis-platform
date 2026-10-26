<script setup lang="ts">
/**
 * Team & founders (س) / market & product / motivation (د) answers of an opportunity, read-only (ADM-05, EVL-01..03).
 * Used on the staff file tab and in the evaluator's scoring view (readiness axis, doc §12.5).
 *
 * Accepts the Laravel full resource (nested `team` / `market` / `motivation` + flat aliases) or the flat mock shape.
 * `compact` renders one dense card with the readiness essentials (score modal).
 */
import type { OpportunityTeam, OpportunityMarket, OpportunityMotivation, TaxonomyTerm, TermLike } from '~/types/api'
import { displayUrl } from '~/utils/validation'

type Source = Partial<OpportunityTeam & OpportunityMarket & Omit<OpportunityMotivation, 'how_heard'>> & {
  team?: OpportunityTeam | null
  market?: Partial<OpportunityMarket> | null
  motivation?: OpportunityMotivation | null
  how_heard?: TermLike | TaxonomyTerm | null
}
type Section = 'team' | 'market' | 'motivation'

const props = withDefaults(defineProps<{ o: Source; sections?: Section[]; compact?: boolean }>(), { sections: () => ['team', 'market', 'motivation'], compact: false })
const { t } = useI18n()
const { label, term } = useEnumLabel()
const { number } = useFormat()
const tax = useTaxonomyStore()

const team = computed<Partial<OpportunityTeam>>(() => props.o.team ?? props.o)
const market = computed<Partial<OpportunityMarket>>(() => props.o.market ?? props.o)
const motivation = computed<Partial<OpportunityMotivation>>(() => props.o.motivation ?? props.o)
const founders = computed(() => team.value.founders ?? [])
const show = (s: Section) => props.sections.includes(s)

const yn = (v: boolean | null | undefined) => (v == null ? t('admin.opportunity.notAnswered') : v ? t('common.yes') : t('common.no'))
const withDesc = (v: boolean | null | undefined, desc?: string | null) => (v && desc ? `${yn(v)} — ${desc}` : yn(v))
const enumOr = (group: string, v: string | null | undefined) => (v ? label(group, v) : t('admin.opportunity.notAnswered'))
const commitment = computed(() => {
  const base = enumOr('foundersCommitment', team.value.founders_commitment)
  return team.value.founders_commitment_note ? `${base} — ${team.value.founders_commitment_note}` : base
})
const howHeard = computed(() => {
  const m = motivation.value
  if (m.how_heard) return term(m.how_heard)
  return m.how_heard_id ? term(tax.byId('how_heard', m.how_heard_id)) : t('admin.opportunity.notAnswered')
})

const teamFacts = computed(() => [
  { label: t('entrepreneur.form.team.foundersCount'), value: team.value.founders_count != null ? number(team.value.founders_count) : null, num: true },
  { label: t('entrepreneur.form.team.teamSize'), value: team.value.team_size != null ? number(team.value.team_size) : null, num: true },
  { label: t('entrepreneur.form.team.commitment'), value: commitment.value },
  { label: t('entrepreneur.form.team.knownYears'), value: enumOr('foundersKnownYears', team.value.founders_known_years) },
  { label: t('entrepreneur.form.team.devTeam'), value: enumOr('devTeamStructure', team.value.dev_team_structure) },
  { label: t('entrepreneur.form.team.foundersAgreement'), value: yn(team.value.founders_agreement_signed) },
])
const teamExperience = computed(() => [
  { label: t('entrepreneur.form.team.priorStartup'), value: withDesc(team.value.prior_startup_experience, team.value.prior_startup_experience_desc) },
  { label: t('entrepreneur.form.team.industryExperience'), value: withDesc(team.value.industry_experience, team.value.industry_experience_desc) },
  { label: t('entrepreneur.form.team.advisoryBoard'), value: withDesc(team.value.has_advisory_board, team.value.advisory_board_desc) },
])
const marketTexts = computed(() => [
  { key: 'target_customers', label: t('entrepreneur.form.market.targetCustomers'), value: market.value.target_customers, public: true },
  { key: 'competitive_advantage', label: t('entrepreneur.form.market.competitiveAdvantage'), value: market.value.competitive_advantage, public: true },
  { key: 'traction_desc', label: t('entrepreneur.form.market.traction'), value: market.value.traction_desc, public: true },
  { key: 'market_size_desc', label: t('entrepreneur.form.market.marketSize'), value: market.value.market_size_desc, public: false },
  { key: 'competitors', label: t('entrepreneur.form.market.competitors'), value: market.value.competitors, public: false },
  { key: 'go_to_market', label: t('entrepreneur.form.market.goToMarket'), value: market.value.go_to_market, public: false },
  { key: 'technology_desc', label: t('entrepreneur.form.market.technology'), value: market.value.technology_desc, public: false },
  { key: 'scalability_desc', label: t('entrepreneur.form.market.scalability'), value: market.value.scalability_desc, public: false },
])
/** Score-modal essentials: what the readiness criteria look at. */
const compactFacts = computed(() => [
  ...teamFacts.value.slice(0, 3),
  { label: t('entrepreneur.form.team.priorStartup'), value: yn(team.value.prior_startup_experience) },
  { label: t('entrepreneur.form.team.industryExperience'), value: yn(team.value.industry_experience) },
  { label: t('entrepreneur.form.team.devTeam'), value: enumOr('devTeamStructure', team.value.dev_team_structure) },
  { label: t('entrepreneur.form.market.productStage'), value: enumOr('productStage', market.value.product_stage) },
  { label: t('entrepreneur.form.market.traction'), value: market.value.traction_desc },
  { label: t('entrepreneur.form.market.scalability'), value: market.value.scalability_desc },
])
</script>

<template>
  <!-- compact: one dense card (evaluator score modal) -->
  <div v-if="compact" class="rounded-lg border border-border bg-bg/60 p-3">
    <p class="text-sm font-semibold">{{ $t('admin.opportunity.readinessProfile') }}</p>
    <p class="text-xs text-muted mb-3">{{ $t('admin.opportunity.readinessHint') }}</p>
    <KeyValue :cols="2" :items="compactFacts" />
    <p v-if="founders.length" class="mt-3 text-xs text-muted">
      {{ $t('admin.opportunity.founders') }}: <span class="text-ink">{{ founders.map((f) => (f.role ? `${f.name} (${f.role})` : f.name)).join('، ') }}</span>
    </p>
  </div>

  <div v-else class="contents">
    <!-- TEAM (confidential) -->
    <SectionCard v-if="show('team')" :title="$t('admin.opportunity.teamSection')" :sub="label('sensitivity', 'confidential')" class="lg:col-span-2">
      <div class="grid md:grid-cols-2 gap-6">
        <KeyValue :cols="2" :items="teamFacts" />
        <KeyValue :cols="1" :items="teamExperience" />
      </div>
      <h3 class="mt-5 text-sm font-semibold">{{ $t('admin.opportunity.founders') }} <span class="text-xs text-muted num">({{ founders.length }})</span></h3>
      <div v-if="founders.length" class="mt-2 overflow-x-auto">
        <table class="table-base">
          <thead><tr><th>{{ $t('entrepreneur.form.team.founderName') }}</th><th>{{ $t('entrepreneur.form.team.founderRole') }}</th><th>{{ $t('entrepreneur.form.team.founderLinkedin') }}</th><th>{{ $t('entrepreneur.form.team.founderFullTime') }}</th></tr></thead>
          <tbody>
            <tr v-for="(f, i) in founders" :key="`${f.name}-${i}`">
              <td class="font-medium">{{ f.name }}</td>
              <td>{{ f.role || '—' }}</td>
              <td><a v-if="f.linkedin_url" :href="f.linkedin_url" target="_blank" rel="noopener noreferrer nofollow" class="ltr inline-block text-blue hover:underline break-all text-xs">{{ displayUrl(f.linkedin_url) }}</a><span v-else class="text-muted">—</span></td>
              <td>{{ f.is_full_time ? $t('common.yes') : $t('common.no') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="mt-2 text-sm text-muted">{{ $t('admin.opportunity.noFounders') }}</p>
    </SectionCard>

    <!-- MARKET & PRODUCT (public subset flagged) -->
    <SectionCard v-if="show('market')" :title="$t('admin.opportunity.marketSection')" :sub="$t('admin.opportunity.publicSubsetHint')" class="lg:col-span-2">
      <dl class="grid md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
        <div class="md:col-span-2">
          <dt class="text-xs text-muted">{{ $t('entrepreneur.form.market.productStage') }} <span class="ms-1 text-[10px] rounded-full bg-success/10 text-success px-1.5">{{ label('sensitivity', 'public') }}</span></dt>
          <dd class="font-medium mt-0.5">{{ enumOr('productStage', market.product_stage) }}</dd>
        </div>
        <div v-for="m in marketTexts" :key="m.key" class="min-w-0">
          <dt class="text-xs text-muted">{{ m.label }} <span v-if="m.public" class="ms-1 text-[10px] rounded-full bg-success/10 text-success px-1.5">{{ label('sensitivity', 'public') }}</span></dt>
          <dd class="text-text/85 leading-6 mt-0.5 whitespace-pre-line break-words">{{ m.value || '—' }}</dd>
        </div>
      </dl>
    </SectionCard>

    <!-- MOTIVATION (internal) -->
    <SectionCard v-if="show('motivation')" :title="$t('admin.opportunity.motivationSection')" :sub="label('sensitivity', 'internal')" class="lg:col-span-2">
      <dl class="grid md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
        <div class="min-w-0"><dt class="text-xs text-muted">{{ $t('entrepreneur.form.motivation.whyNow') }}</dt><dd class="text-text/85 leading-6 mt-0.5 whitespace-pre-line break-words">{{ motivation.why_now || '—' }}</dd></div>
        <div class="min-w-0"><dt class="text-xs text-muted">{{ $t('entrepreneur.form.motivation.expectations') }}</dt><dd class="text-text/85 leading-6 mt-0.5 whitespace-pre-line break-words">{{ motivation.program_expectations || '—' }}</dd></div>
        <div class="min-w-0">
          <dt class="text-xs text-muted">{{ $t('entrepreneur.form.motivation.supportNeeded') }}</dt>
          <dd class="mt-1"><ul v-if="motivation.support_needed?.length" class="flex flex-wrap gap-1.5"><li v-for="s in motivation.support_needed" :key="s" class="chip text-xs !py-0.5">{{ label('supportNeed', s) }}</li></ul><span v-else>—</span></dd>
        </div>
        <div class="min-w-0"><dt class="text-xs text-muted">{{ $t('entrepreneur.form.motivation.howHeard') }}</dt><dd class="font-medium mt-0.5">{{ howHeard }}</dd></div>
      </dl>
    </SectionCard>
  </div>
</template>
