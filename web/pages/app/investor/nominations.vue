<script setup lang="ts">
import type { Nomination } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.nominations.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const toast = useToast()
const { term, label } = useEnumLabel()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Nomination[] }>('/investor/nominations').then((r) => r.data))
const declining = ref<Nomination | null>(null)
const reasonId = ref(''); const note = ref(''); const busy = ref(false)
const criteria = ['sector', 'stage', 'region', 'type', 'range'] as const
const respond = async (n: Nomination, response: 'interested' | 'declined') => {
  busy.value = true
  try { await api.post(`/investor/nominations/${n.id}/respond`, { response, decline_reason_id: Number(reasonId.value) || undefined, note: note.value || undefined }); declining.value = null; toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false }
}
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
          <ul class="mt-1.5 flex flex-wrap gap-1.5"><li v-for="c in criteria" :key="c" class="text-xs rounded-full px-2 py-0.5 border" :class="n.matched_criteria[c] ? 'bg-success/10 text-success border-success/30' : 'bg-bg text-muted border-border line-through'">{{ $t(`investor.nominations.criteria.${c}`) }}</li></ul>
          <p class="mt-3 text-xs font-medium text-muted">{{ $t('investor.nominations.rationale') }}</p>
          <p class="text-sm leading-6">{{ n.rationale }}</p>
        </div>
        <div class="px-5 py-3 border-t border-border bg-bg/40 rounded-b-xl flex items-center justify-between gap-2">
          <template v-if="n.response"><AppBadge kind="generic" :value="n.response" :label="$t('investor.nominations.responded', { response: label('nominationResponse', n.response) })" size="md" /></template>
          <template v-else><AppButton variant="ghost" size="sm" @click="declining = n">{{ $t('investor.nominations.decline') }}</AppButton><AppButton size="sm" :loading="busy" @click="respond(n, 'interested')">{{ $t('investor.nominations.interested') }}</AppButton></template>
        </div>
      </article>
    </div>
    <Modal :open="!!declining" :title="$t('investor.nominations.declineModal.title')" @close="declining = null">
      <div class="space-y-4">
        <FormField v-model="reasonId" name="reason" type="select" :label="$t('investor.nominations.declineModal.reason')" :options="tax.list('decline_reasons').map((r) => ({ value: String(r.id), label: term(r) }))" required />
        <FormField v-model="note" name="note" type="textarea" :label="$t('investor.nominations.declineModal.note')" :rows="3" />
      </div>
      <template #footer><AppButton variant="ghost" @click="declining = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="busy" @click="declining && respond(declining, 'declined')">{{ $t('investor.nominations.decline') }}</AppButton></template>
    </Modal>
  </div>
</template>
