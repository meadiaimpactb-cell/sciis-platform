<script setup lang="ts">
import type { InfoRequest } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.infoRequests.metaTitle') })
const api = useApi()
const toast = useToast()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: InfoRequest[] }>('/entrepreneur/info-requests').then((r) => r.data))
const answers = reactive<Record<number, string>>({})
const busy = ref<number | null>(null)
const answer = async (r: InfoRequest) => {
  busy.value = r.id
  try { await api.post(`/entrepreneur/info-requests/${r.id}/answer`, { answer: answers[r.id] }); toast.success(t('entrepreneur.infoRequests.answered')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.infoRequests.title')" :sub="$t('entrepreneur.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2"><AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted">{{ dateTime(r.created_at) }}</span><span class="text-xs text-muted ms-auto">{{ r.opportunity.name }}</span></div>
        <h2 class="mt-2 font-semibold">{{ r.subject }}</h2>
        <p class="text-sm leading-6 mt-1">{{ r.body }}</p>
        <div v-if="r.owner_answer" class="mt-3 rounded-lg bg-success/5 border border-success/20 p-3 text-sm"><p class="text-xs text-muted mb-1">{{ $t('entrepreneur.infoRequests.answer') }}</p>{{ r.owner_answer }}</div>
        <form v-else class="mt-3 space-y-2" @submit.prevent="answer(r)">
          <FormField v-model="answers[r.id]" :name="`answer-${r.id}`" type="textarea" :label="$t('entrepreneur.infoRequests.answer')" :placeholder="$t('entrepreneur.infoRequests.answerPlaceholder')" :rows="3" required />
          <AppButton type="submit" size="sm" :loading="busy === r.id">{{ $t('entrepreneur.infoRequests.sendAnswer') }}</AppButton>
        </form>
      </article>
    </div>
  </div>
</template>
