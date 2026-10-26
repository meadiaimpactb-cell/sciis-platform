<script setup lang="ts">
import type { Meeting } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.meetings.metaTitle') })
const api = useApi()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: Meeting[] }>('/entrepreneur/meetings').then((r) => r.data))
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.meetings.title')" :sub="$t('entrepreneur.meetings.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <MeetingList v-else :meetings="data ?? []" @changed="refresh" />
  </div>
</template>
