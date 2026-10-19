<script setup lang="ts">
import type { InfoRequest } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.infoRequests.metaTitle') })
const api = useApi()
const { dateTime } = useFormat()
const { data, loading } = useAsyncResource(() => api.get<{ data: InfoRequest[] }>('/investor/info-requests').then((r) => r.data))
</script>

<template>
  <div>
    <PageHeader :title="$t('investor.infoRequests.title')" :sub="$t('investor.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('common.noData')" />
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2"><AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted">{{ dateTime(r.created_at) }}</span><NuxtLink :to="$localePath(`/app/opportunities/${r.opportunity.uuid}`)" class="text-sm link ms-auto">{{ r.opportunity.name }}</NuxtLink></div>
        <h2 class="mt-2 font-semibold">{{ r.subject }}</h2>
        <p class="text-sm text-text/85 leading-6 mt-1">{{ r.body }}</p>
        <div class="mt-4 rounded-lg p-3 text-sm" :class="r.owner_answer ? 'bg-success/5 border border-success/20' : 'bg-bg'">
          <p class="text-xs font-medium text-muted mb-1">{{ $t('investor.infoRequests.answer') }}</p>
          <p v-if="r.owner_answer" class="leading-6">{{ r.owner_answer }}</p>
          <p v-else class="text-muted">{{ $t('investor.infoRequests.noAnswer') }}</p>
        </div>
      </article>
    </div>
  </div>
</template>
