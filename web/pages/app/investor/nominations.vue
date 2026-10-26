<script setup lang="ts">
import { z } from 'zod'
import type { MatchCriterionKey, Nomination } from '~/types/api'
import { optionalText, requiredSelect, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.nominations.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { term, label } = useEnumLabel()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Nomination[] }>('/investor/nominations').then((r) => r.data))
const criteria: MatchCriterionKey[] = ['sector', 'stage', 'region', 'investment_type', 'funding_range']
/** Laravel sends `[{key,label}]`; the mock sends a boolean record with the legacy `type` / `range` keys. */
const matched = (n: Nomination, key: MatchCriterionKey): boolean => {
  const mc = n.matched_criteria
  if (Array.isArray(mc)) return mc.some((c) => c.key === key)
  const legacy: Record<MatchCriterionKey, 'sector' | 'stage' | 'region' | 'type' | 'range'> = { sector: 'sector', stage: 'stage', region: 'region', investment_type: 'type', funding_range: 'range' }
  return !!mc?.[legacy[key]]
}

// "interested": one click (it opens an interest on the opportunity)
const busy = ref<number | null>(null)
const interested = async (n: Nomination) => {
  busy.value = n.id
  try {
    await api.post(`/investor/nominations/${n.id}/respond`, { response: 'interested' })
    toastSuccess(t('notify.sent', { item: t('notify.entities.interest') }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// decline: reason required (RespondNominationRequest: decline_reason_id required_if declined, note ≤ 2000) + confirmation
const declining = ref<Nomination | null>(null)
const declineForm = useZodForm(
  z.object({ decline_reason_id: requiredSelect('investor.nominations.declineModal.reason'), note: optionalText(RULES.noteMax) }),
  { decline_reason_id: '' as string | null, note: '' },
)
const openDecline = (n: Nomination) => { declineForm.reset(); declining.value = n }
const decline = () => declineForm.submit(async (v) => {
  const n = declining.value
  if (!n || !(await confirmDialog(t('investor.nominations.declineModal.title'), t('investor.nominations.declineModal.confirmText', { name: n.opportunity.name }), t('investor.nominations.decline')))) return
  await api.post(`/investor/nominations/${n.id}/respond`, { response: 'declined', decline_reason_id: Number(v.decline_reason_id) || undefined, note: v.note?.trim() || undefined })
  declining.value = null
  toastInfo(t('notify.rejectedNotified', { item: t('notify.entities.nomination') }))
  await refresh()
})
const reasonOpts = computed(() => tax.list('decline_reasons').map((r) => ({ value: String(r.id), label: term(r) })))
// ACC-08 — colleagues without "can act" see the organisation's nominations read-only.
const canAct = computed(() => useAuthStore().me?.organization_access?.can_act !== false)
// MTG-03 — questions to the council about one nomination.
const messagesFor = ref<Nomination | null>(null)
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.nominations.title')" :sub="$t('investor.nominations.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="grid md:grid-cols-2 gap-5">
      <article v-for="n in data" :key="n.id" class="card flex flex-col">
        <div class="p-5 flex-1">
          <div class="flex items-center justify-between gap-2"><span class="text-xs text-muted">{{ date(n.sent_at) }}</span><span class="text-xs rounded-full bg-blue-soft text-blue px-2 py-0.5 font-medium num">{{ $t('investor.detail.matchScore', { n: n.match_score }) }}</span></div>
          <h2 class="mt-2 text-lg font-semibold"><NuxtLink :to="$localePath(`/app/opportunities/${n.opportunity.uuid}`)" class="hover:text-blue">{{ n.opportunity.name }}</NuxtLink></h2>
          <p class="text-sm text-muted">{{ term(n.opportunity.sector) }} · {{ label('stage', n.opportunity.stage) }}</p>
          <p class="mt-3 text-sm leading-6 text-text/85 line-clamp-3">{{ n.opportunity.teaser_summary }}</p>
          <p class="mt-3 text-xs font-medium text-muted">{{ $t('investor.nominations.matched') }}</p>
          <ul class="mt-1.5 flex flex-wrap gap-1.5"><li v-for="c in criteria" :key="c" class="text-xs rounded-full px-2 py-0.5 border" :class="matched(n, c) ? 'bg-success/10 text-success border-success/30' : 'bg-bg text-muted border-border line-through'">{{ $t(`investor.nominations.criteria.${c}`) }}</li></ul>
          <p class="mt-3 text-xs font-medium text-muted">{{ $t('investor.nominations.rationale') }}</p>
          <p class="text-sm leading-6">{{ n.rationale }}</p>
        </div>
        <div class="px-5 py-3 border-t border-border bg-bg/40 rounded-b-xl flex items-center justify-between gap-2">
          <AppButton variant="ghost" size="sm" @click="messagesFor = n">{{ $t('collab.messages.button') }}</AppButton>
          <template v-if="n.response"><AppBadge kind="generic" :value="n.response" :label="$t('investor.nominations.responded', { response: label('nominationResponse', n.response) })" size="md" /></template>
          <template v-else-if="canAct"><span class="flex gap-2"><AppButton variant="ghost" size="sm" @click="openDecline(n)">{{ $t('investor.nominations.decline') }}</AppButton><AppButton size="sm" :loading="busy === n.id" @click="interested(n)">{{ $t('investor.nominations.interested') }}</AppButton></span></template>
          <span v-else class="text-xs text-muted">{{ $t('collab.team.readOnlyShort') }}</span>
        </div>
      </article>
    </div>
    <Modal :open="!!declining" :title="$t('investor.nominations.declineModal.title')" @close="declining = null">
      <form class="space-y-4" novalidate @submit.prevent="decline">
        <p class="text-sm text-muted">{{ declining?.opportunity.name }}</p>
        <FormField v-model="declineForm.values.decline_reason_id" name="decline_reason_id" type="select" :label="$t('investor.nominations.declineModal.reason')" :options="reasonOpts" :error="declineForm.errors.value.decline_reason_id" required />
        <FormField v-model="declineForm.values.note" name="note" type="textarea" :label="$t('investor.nominations.declineModal.note')" :error="declineForm.errors.value.note" :rows="3" :maxlength="RULES.noteMax" />
      </form>
      <template #footer><FormAlert :message="declineForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="declining = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="declineForm.submitting.value" @click="decline">{{ $t('investor.nominations.decline') }}</AppButton></template>
    </Modal>
    <Modal :open="!!messagesFor" :title="$t('collab.messages.withCouncil')" size="lg" @close="messagesFor = null">
      <p class="text-sm text-muted mb-3">{{ messagesFor?.opportunity.name }}</p>
      <LazyMessageThread v-if="messagesFor" subject-type="nomination" :subject-key="messagesFor.id" />
    </Modal>
  </div>
</template>
