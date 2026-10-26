<script setup lang="ts">
/** Info requests (MSG-01) — forward to the owner, or answer on the owner's behalf (ReplyInfoRequestRequest: answer 5..5000);
 *  §6.3 close a request (any open status; an unanswered one notifies the investor). */
import type { InfoRequest, Paginated } from '~/types/api'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.infoRequests.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<InfoRequest>>('/admin/info-requests', { per_page: 50 }).then((r) => r.data))
const busy = ref<string | null>(null)
const forward = async (r: InfoRequest) => {
  busy.value = `forward-${r.id}`
  try { await api.post(`/admin/info-requests/${r.id}/forward`); toastSuccess(t('admin.infoRequests.forwarded')); await refresh() } catch (e) { toastApiError(e) } finally { busy.value = null }
}
/** TextReplyForm validates and maps a 422 on `answer` under the textarea; success → specific toast + reload. */
const reply = (r: InfoRequest) => async (answer: string) => {
  await api.post(`/admin/info-requests/${r.id}/reply`, { answer })
  toastSuccess(t('notify.sent', { item: t('notify.entities.answer') }))
  await refresh()
}
/** §6.3 — closing ends the request (no more forward / reply / owner answer) → confirmed first, naming the subject. */
const canClose = computed(() => auth.canAny('nominations.manage'))
const close = async (r: InfoRequest) => {
  if (!(await confirmDialog(t('notify.closeTitle'), t('notify.closeText', { name: r.subject }), t('notify.closeConfirm')))) return
  busy.value = `close-${r.id}`
  try {
    await api.post(`/admin/info-requests/${r.id}/close`)
    toastSuccess(t('notify.closed', { item: `${t('notify.entities.infoRequest')} «${r.subject}»` }))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.infoRequests.title')" :sub="$t('admin.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2">
          <AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted num">{{ dateTime(r.created_at) }}</span><span class="text-sm">{{ r.investor?.name }} → <NuxtLink :to="$localePath(`/app/admin/opportunities/${r.opportunity.uuid}`)" class="link">{{ r.opportunity.name }}</NuxtLink></span>
          <AppButton v-if="canClose && r.can_close" size="sm" variant="ghost" class="ms-auto" :loading="busy === `close-${r.id}`" @click="close(r)">{{ $t('admin.infoRequests.close') }}</AppButton>
        </div>
        <h2 class="mt-2 font-semibold">{{ r.subject }}</h2><p class="text-sm leading-6">{{ r.body }}</p>
        <div v-if="r.owner_answer" class="mt-3 rounded-lg bg-success/5 border border-success/20 p-3 text-sm">{{ r.owner_answer }}</div>
        <p v-if="r.status === 'closed' && r.closed_at" class="mt-3 text-xs text-muted">{{ $t('admin.infoRequests.closedAt') }}: <span class="num">{{ dateTime(r.closed_at) }}</span></p>
        <div v-else-if="r.status !== 'answered' && r.status !== 'closed'" class="mt-3 space-y-3">
          <AppButton v-if="r.status === 'sent'" size="sm" variant="secondary" :loading="busy === `forward-${r.id}`" @click="forward(r)">{{ $t('admin.infoRequests.forward') }}</AppButton>
          <TextReplyForm label-key="admin.infoRequests.replyText" :submit-label="$t('admin.infoRequests.reply')" :action="reply(r)" :rows="2" />
        </div>
      </article>
      <EmptyState v-if="data && !data.length" compact :title="$t('common.noData')" />
    </div>
  </div>
</template>
