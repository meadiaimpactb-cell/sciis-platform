<script setup lang="ts">
import type { Interest } from '~/types/api'
import { INTEREST_STATUSES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.interests.metaTitle') })
const api = useApi()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const toast = useToast()
const { term } = useEnumLabel()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Interest[] }>('/investor/interests').then((r) => r.data))
const closing = ref<Interest | null>(null)
const reasonId = ref('')
const note = ref('')
const busy = ref(false)
const steps = INTEREST_STATUSES.filter((s) => s !== 'closed')
const stepIdx = (s: string) => steps.indexOf(s as never)
const close = async () => {
  if (!closing.value) return
  busy.value = true
  try { await api.post(`/investor/interests/${closing.value.id}/close`, { reason_id: Number(reasonId.value) || undefined, note: note.value }); closing.value = null; toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.interests.title')" :sub="$t('investor.interests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')"><AppButton to="/app/opportunities" size="sm">{{ $t('portal.nav.opportunities') }}</AppButton></EmptyState>
    <div v-else class="space-y-4">
      <article v-for="i in data" :key="i.id" class="card card-pad">
        <div class="flex flex-col md:flex-row md:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="interest" :value="i.status" size="md" /><span class="text-xs text-muted num">{{ date(i.created_at) }}</span></div>
            <h2 class="mt-2 text-lg font-semibold"><NuxtLink :to="$localePath(`/app/opportunities/${i.opportunity.uuid}`)" class="hover:text-blue">{{ i.opportunity.name }}</NuxtLink></h2>
            <p class="text-sm text-muted">{{ term(i.opportunity.sector) }} · <span class="num">{{ i.opportunity.reference_no }}</span></p>
            <ol v-if="i.status !== 'closed'" class="mt-4 flex items-center gap-1">
              <li v-for="(s, idx) in steps" :key="s" class="flex-1 flex flex-col gap-1 min-w-0">
                <div class="h-1.5 rounded-full" :class="idx <= stepIdx(i.status) ? 'bg-blue' : 'bg-border'" />
                <span class="text-[10px] sm:text-xs truncate" :class="idx === stepIdx(i.status) ? 'text-blue font-medium' : 'text-muted'">{{ $t(`investor.interests.steps.${s}`) }}</span>
              </li>
            </ol>
            <p v-if="i.next_step" class="mt-3 text-sm"><span class="text-muted">{{ $t('investor.dashboard.nextStep') }}:</span> {{ i.next_step }}</p>
            <p v-if="i.closed_reason" class="mt-2 text-sm text-muted">{{ $t('common.reason') }}: {{ i.closed_reason }}</p>
          </div>
          <div class="shrink-0 flex md:flex-col gap-2">
            <AppButton variant="secondary" size="sm" :to="`/app/opportunities/${i.opportunity.uuid}`">{{ $t('common.view') }}</AppButton>
            <AppButton v-if="i.status !== 'closed'" variant="ghost" size="sm" @click="closing = i">{{ $t('investor.interests.closeBtn') }}</AppButton>
          </div>
        </div>
      </article>
    </div>
    <Modal :open="!!closing" :title="$t('investor.interests.closeModal.title')" @close="closing = null">
      <div class="space-y-4">
        <FormField v-model="reasonId" name="reason" type="select" :label="$t('investor.interests.closeModal.reason')" :options="tax.list('decline_reasons').map((r) => ({ value: String(r.id), label: term(r) }))" />
        <FormField v-model="note" name="note" type="textarea" :label="$t('investor.interests.closeModal.note')" :rows="3" />
      </div>
      <template #footer><AppButton variant="ghost" @click="closing = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="busy" @click="close">{{ $t('investor.interests.closeBtn') }}</AppButton></template>
    </Modal>
  </div>
</template>
