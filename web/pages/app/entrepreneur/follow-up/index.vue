<script setup lang="ts">
/** PRJ-01..03 / IMP / ENT-10 — the owner's projects in negotiation, execution or operation (GET /entrepreneur/follow-up). */
import type { FollowUpSummary } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('followUp.owner.metaTitle') })
const api = useApi()
const { data, loading } = useAsyncResource(() => api.get<{ data: FollowUpSummary[] }>('/entrepreneur/follow-up').then((r) => r.data ?? []))
</script>

<template>
  <div>
    <PageHeader :title="$t('followUp.owner.title')" :sub="$t('followUp.owner.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('followUp.owner.emptyTitle')" :text="$t('followUp.owner.empty')"><AppButton size="sm" variant="secondary" to="/app/entrepreneur/dashboard">{{ $t('portal.nav.dashboard') }}</AppButton></EmptyState>
    <div v-else class="space-y-4">
      <FollowUpProjectCard v-for="p in data" :key="p.uuid" :project="p" :to="`/app/entrepreneur/follow-up/${p.uuid}`" audience="owner" />
    </div>
  </div>
</template>
