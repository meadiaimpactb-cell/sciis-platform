<script setup lang="ts">
import type { InfoRequest } from '~/types/api'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.infoRequests.metaTitle') })
const api = useApi()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: InfoRequest[] }>('/investor/info-requests').then((r) => r.data))
/** §6.3 — the investor closes their own request once it is answered (cannot be reopened → confirmed first). */
const closing = ref<number | null>(null)
const close = async (r: InfoRequest) => {
  if (!(await confirmDialog(t('notify.closeTitle'), t('notify.closeText', { name: r.subject }), t('notify.closeConfirm')))) return
  closing.value = r.id
  try {
    await api.post(`/investor/info-requests/${r.id}/close`)
    toastSuccess(t('notify.closed', { item: `${t('notify.entities.infoRequest')} «${r.subject}»` }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { closing.value = null }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.infoRequests.title')" :sub="$t('investor.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2"><AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted num">{{ dateTime(r.created_at) }}</span><NuxtLink :to="$localePath(`/app/opportunities/${r.opportunity.uuid}`)" class="text-sm link ms-auto">{{ r.opportunity.name }}</NuxtLink></div>
        <h2 class="mt-2 card-title">{{ r.subject }}</h2>
        <p class="text-sm text-text leading-6 mt-1">{{ r.body }}</p>
        <div class="mt-4 rounded-xl border p-3 text-sm" :class="r.owner_answer ? 'bg-success/10 border-success/30' : 'bg-glass border-border'">
          <p class="text-xs font-medium text-muted mb-1">{{ $t('investor.infoRequests.answer') }}</p>
          <p v-if="r.owner_answer" class="leading-6">{{ r.owner_answer }}</p>
          <p v-else class="text-muted">{{ $t(r.status === 'closed' ? 'investor.infoRequests.closedUnanswered' : 'investor.infoRequests.noAnswer') }}</p>
        </div>
        <div v-if="r.can_close || r.closed_at" class="mt-3 flex flex-wrap items-center justify-between gap-2">
          <p v-if="r.closed_at" class="text-xs text-muted">{{ $t('investor.infoRequests.closedAt') }}: <span class="num">{{ dateTime(r.closed_at) }}</span></p>
          <AppButton v-if="r.can_close" size="sm" variant="ghost" class="ms-auto" :loading="closing === r.id" @click="close(r)">{{ $t('investor.infoRequests.close') }}</AppButton>
        </div>
      </article>
    </div>
  </div>
</template>
