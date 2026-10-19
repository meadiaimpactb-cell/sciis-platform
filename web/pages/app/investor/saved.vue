<script setup lang="ts">
import type { OpportunityCard } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['investor'] })
const { t } = useI18n()
useHead({ title: t('investor.saved.metaTitle') })
const api = useApi()
const toast = useToast()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: OpportunityCard[] }>('/investor/saved').then((r) => r.data))
const unsave = async (o: OpportunityCard) => { await api.del(`/opportunities/${o.uuid}/save`); toast.success(t('investor.opportunities.unsaveBtn')); await refresh() }
</script>
<template>
  <div>
    <PageHeader :title="$t('investor.saved.title')" :sub="$t('investor.saved.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="!data?.length" :title="$t('investor.saved.empty')"><AppButton to="/app/opportunities" size="sm">{{ $t('portal.nav.opportunities') }}</AppButton></EmptyState>
    <div v-else class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5"><OpportunityCard v-for="o in data" :key="o.uuid" :opportunity="o" saveable @toggle-save="unsave" /></div>
  </div>
</template>
