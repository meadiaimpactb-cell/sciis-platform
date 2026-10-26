<script setup lang="ts">
/** INV-06 — "my linked projects": projects the investor is connected to (GET /investor/portfolio). */
import type { FollowUpSummary } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('followUp.investor.metaTitle') })
const api = useApi()
const { data, loading } = useAsyncResource(() => api.get<{ data: FollowUpSummary[] }>('/investor/portfolio').then((r) => r.data ?? []))
</script>

<template>
  <div>
    <PageHeader :title="$t('followUp.investor.title')" :sub="$t('followUp.investor.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('followUp.investor.emptyTitle')" :text="$t('followUp.investor.empty')"><AppButton size="sm" variant="secondary" to="/app/investor/interests">{{ $t('portal.nav.interests') }}</AppButton></EmptyState>
    <div v-else class="space-y-4">
      <FollowUpProjectCard v-for="p in data" :key="p.uuid" :project="p" :to="`/app/investor/portfolio/${p.uuid}`" audience="investor" />
    </div>
  </div>
</template>
