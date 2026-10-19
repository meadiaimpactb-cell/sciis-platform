<script setup lang="ts">
import type { InfoRequest, Paginated } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['matching_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.infoRequests.metaTitle') })
const api = useApi()
const toast = useToast()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<Paginated<InfoRequest>>('/admin/info-requests', { per_page: 50 }).then((r) => r.data))
const replies = reactive<Record<number, string>>({})
const busy = ref<string | null>(null)
const forward = async (r: InfoRequest) => { busy.value = `f${r.id}`; try { await api.post(`/admin/info-requests/${r.id}/forward`); toast.success(t('admin.infoRequests.forwarded')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null } }
const reply = async (r: InfoRequest) => { busy.value = `r${r.id}`; try { await api.post(`/admin/info-requests/${r.id}/reply`, { answer: replies[r.id] }); toast.success(t('common.success')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = null } }
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.infoRequests.title')" :sub="$t('admin.infoRequests.sub')" />
    <div v-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else class="space-y-4">
      <article v-for="r in data" :key="r.id" class="card card-pad">
        <div class="flex flex-wrap items-center gap-2"><AppBadge kind="info" :value="r.status" /><span class="text-xs text-muted">{{ dateTime(r.created_at) }}</span><span class="text-sm">{{ r.investor?.name }} → <NuxtLink :to="$localePath(`/app/admin/opportunities/${r.opportunity.uuid}`)" class="link">{{ r.opportunity.name }}</NuxtLink></span></div>
        <h2 class="mt-2 font-semibold">{{ r.subject }}</h2><p class="text-sm leading-6">{{ r.body }}</p>
        <div v-if="r.owner_answer" class="mt-3 rounded-lg bg-success/5 border border-success/20 p-3 text-sm">{{ r.owner_answer }}</div>
        <div class="mt-3 flex flex-col sm:flex-row gap-2 sm:items-end">
          <AppButton v-if="r.status === 'sent'" size="sm" variant="secondary" :loading="busy === `f${r.id}`" @click="forward(r)">{{ $t('admin.infoRequests.forward') }}</AppButton>
          <template v-if="r.status !== 'answered'"><FormField v-model="replies[r.id]" :name="`reply-${r.id}`" :label="$t('admin.infoRequests.replyText')" class="flex-1" /><AppButton size="sm" :disabled="!replies[r.id]" :loading="busy === `r${r.id}`" @click="reply(r)">{{ $t('admin.infoRequests.reply') }}</AppButton></template>
        </div>
      </article>
    </div>
  </div>
</template>
