<script setup lang="ts">
import type { InfoRequest } from '~/types/api'
import { toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.infoRequests.metaTitle') })
const api = useApi()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: InfoRequest[] }>('/entrepreneur/info-requests').then((r) => r.data))
/** Validated by TextReplyForm (AnswerInfoRequestRequest: 5..5000); a 422 lands under the textarea. */
const answer = async (r: InfoRequest, text: string) => {
  await api.post(`/entrepreneur/info-requests/${r.id}/answer`, { answer: text })
  toastSuccess(t('notify.sent', { item: t('notify.entities.answer') }))
}
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.infoRequests.title')" :sub="$t('entrepreneur.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2"><AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted num">{{ dateTime(r.created_at) }}</span><span class="text-xs text-muted ms-auto">{{ r.opportunity.name }}</span></div>
        <h2 class="mt-2 card-title">{{ r.subject }}</h2>
        <p class="text-sm leading-6 mt-1">{{ r.body }}</p>
        <div v-if="r.owner_answer" class="mt-3 rounded-xl bg-success/10 border border-success/30 p-3 text-sm"><p class="text-xs text-muted mb-1">{{ $t('entrepreneur.infoRequests.answer') }}</p>{{ r.owner_answer }}</div>
        <!-- §6.3 — a request the council closed takes no more answers -->
        <p v-else-if="r.status === 'closed'" class="mt-3 text-sm text-muted">{{ $t('entrepreneur.infoRequests.closedByCouncil') }}</p>
        <TextReplyForm
          v-else
          class="mt-3"
          label-key="entrepreneur.infoRequests.answer"
          :placeholder="$t('entrepreneur.infoRequests.answerPlaceholder')"
          :submit-label="$t('entrepreneur.infoRequests.sendAnswer')"
          :action="(text: string) => answer(r, text)"
          @done="refresh"
        />
      </article>
    </div>
  </div>
</template>
