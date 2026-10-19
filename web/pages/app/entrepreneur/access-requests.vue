<script setup lang="ts">
import type { Interest } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.accessRequests.metaTitle') })
const api = useApi()
const toast = useToast()
const { date } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Interest[] }>('/entrepreneur/access-requests').then((r) => r.data))
const declining = ref<Interest | null>(null)
const reason = ref('')
const busy = ref<number | null>(null)
const decide = async (i: Interest, decision: 'approve' | 'decline') => {
  busy.value = i.id
  try { await api.post(`/entrepreneur/access-requests/${i.id}/decide`, { decision, reason: reason.value || undefined }); declining.value = null; reason.value = ''; toast.success(t('entrepreneur.accessRequests.decided')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.accessRequests.title')" :sub="$t('entrepreneur.accessRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="i in data" :key="i.id" class="card card-pad flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-2"><AppBadge kind="interest" :value="i.status" size="md" /><span class="text-xs text-muted">{{ date(i.created_at) }}</span></div>
          <p class="mt-2 font-semibold">{{ i.investor?.name ?? $t('entrepreneur.dashboard.investorLabel') }}</p>
          <p class="text-sm text-muted">{{ i.opportunity.name }}</p>
          <p v-if="i.note" class="mt-2 text-sm text-text/85">{{ i.note }}</p>
          <p v-if="i.owner_decision" class="mt-2 text-xs text-muted">{{ $t('admin.interests.ownerDecision') }}: {{ $t(`enums.ownerDecision.${i.owner_decision}`) }} · {{ date(i.owner_decided_at) }}</p>
        </div>
        <div v-if="i.status === 'awaiting_owner_approval'" class="flex gap-2 shrink-0"><AppButton :loading="busy === i.id" @click="decide(i, 'approve')">{{ $t('entrepreneur.accessRequests.approve') }}</AppButton><AppButton variant="ghost" @click="declining = i">{{ $t('entrepreneur.accessRequests.decline') }}</AppButton></div>
      </article>
    </div>
    <Modal :open="!!declining" :title="$t('entrepreneur.accessRequests.decline')" @close="declining = null">
      <FormField v-model="reason" name="reason" type="textarea" :label="$t('entrepreneur.accessRequests.declineReason')" :rows="3" />
      <template #footer><AppButton variant="ghost" @click="declining = null">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="!!busy" @click="declining && decide(declining, 'decline')">{{ $t('common.decline') }}</AppButton></template>
    </Modal>
  </div>
</template>
