<script setup lang="ts">
import type { Meeting } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.meetings.metaTitle') })
const api = useApi()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Meeting[] }>('/investor/meetings').then((r) => r.data))
</script>
<template>
  <div>
    <PageHeader :title="$t('investor.meetings.title')" :sub="$t('investor.meetings.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <MeetingList v-else :meetings="data ?? []" @changed="refresh" />
  </div>
</template>
