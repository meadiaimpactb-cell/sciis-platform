<script setup lang="ts">
/** RPT-01 reports (kind/series/unit contract, period + sector/region filters) and RPT-02 xlsx export (#29/#30). */
import type { ReportResult } from '~/types/api'
import { REPORT_KEYS } from '~/types/enums'
import { toastApiError, toastInfo } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['reports_viewer', 'impact_officer', 'membership_officer'] })
const { t, te } = useI18n()
useHead({ title: t('admin.reports.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const { label, term } = useEnumLabel()
const { number, relative } = useFormat()
const filters = reactive({ from: '', to: '', sector: '', region: '' })
const query = computed(() => ({ from: filters.from || undefined, to: filters.to || undefined, sector: filters.sector || undefined, region: filters.region || undefined }))
const reports = ref<Record<string, ReportResult | null | undefined>>({})
const loadAll = async () => { reports.value = {}; await Promise.all(REPORT_KEYS.map(async (k) => { try { reports.value[k] = (await api.get<{ data: ReportResult }>(`/admin/reports/${k}`, query.value)).data } catch { reports.value[k] = null } })) }
onMounted(loadAll)
const title = (k: string) => reports.value[k]?.title ?? (te(`admin.reports.titles.${k}`) ? t(`admin.reports.titles.${k}`) : k)
// Series labels are enum values (user type / statuses) or metric keys; free-text labels (sector names, channels) pass through.
const translate = (s: string) => {
  for (const g of ['userType', 'membershipStatus', 'opportunityStatus']) { const l = label(g, s); if (l !== s) return l }
  return te(`admin.reports.metrics.${s}`) ? t(`admin.reports.metrics.${s}`) : s
}
const series = (r: ReportResult) => (r.series ?? []).map((s) => ({ ...s, label: translate(s.label) }))
const headline = (r: ReportResult) => r.series?.[0]?.value ?? null
const exportKey = async (k: string) => {
  const path = `/admin/reports/${k}/export.xlsx`
  try {
    if (api.mock) { const r = await api.get<{ url: string; note?: string }>(path, query.value); toastInfo(r.note ?? r.url); return }
    const qs = new URLSearchParams(Object.entries(query.value).filter(([, v]) => !!v) as [string, string][]).toString()
    // Binary download — open in a new tab with the Sanctum cookie session (audited server-side, RPT-02).
    window.open(`${api.baseURL}${path}${qs ? `?${qs}` : ''}`, '_blank', 'noopener'); toastInfo(t('notify.preparing'))
  } catch (e) { toastApiError(e) }
}
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.reports.title')" :sub="$t('admin.reports.sub')" />
    <form class="card p-3 mb-5 grid sm:grid-cols-5 gap-3 items-end" @submit.prevent="loadAll">
      <FormField v-model="filters.from" name="from" type="date" :label="$t('admin.reports.filters.from')" ltr />
      <FormField v-model="filters.to" name="to" type="date" :label="$t('admin.reports.filters.to')" ltr />
      <FormField v-model="filters.sector" name="sector" type="select" :label="$t('admin.reports.filters.sector')" :options="tax.list('sectors').map((s) => ({ value: s.key, label: term(s) }))" />
      <FormField v-model="filters.region" name="region" type="select" :label="$t('admin.reports.filters.region')" :options="tax.list('regions').map((s) => ({ value: s.key, label: term(s) }))" />
      <AppButton type="submit">{{ $t('admin.reports.filters.apply') }}</AppButton>
    </form>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
      <SectionCard v-for="k in REPORT_KEYS" :key="k" :title="title(k)" :sub="reports[k] ? $t('admin.reports.generated', { time: relative(reports[k]!.generated_at) }) : undefined">
        <template #actions><AppButton v-if="auth.canAny('reports.export')" size="sm" variant="ghost" @click="exportKey(k)">{{ $t('admin.reports.export') }}</AppButton></template>
        <template v-if="reports[k]">
          <div v-if="reports[k]!.kind === 'number'" class="py-4 text-center"><span class="text-5xl font-semibold num text-blue">{{ headline(reports[k]!) == null ? '—' : number(headline(reports[k]!)) }}</span><span class="text-muted ms-1">{{ reports[k]!.unit === '%' ? '%' : reports[k]!.unit === 'days' ? $t('common.days', 0) : '' }}</span><ul v-if="(reports[k]!.series?.length ?? 0) > 1" class="mt-3 text-xs text-muted flex flex-wrap justify-center gap-x-3 gap-y-1"><li v-for="s in series(reports[k]!).slice(1)" :key="s.label">{{ s.label }}: <span class="num">{{ number(s.value) }}</span></li></ul></div>
          <BarChart v-else :series="series(reports[k]!)" :funnel="reports[k]!.kind === 'funnel'" />
        </template>
        <p v-else-if="reports[k] === null" class="text-sm text-muted">{{ $t('admin.reports.unavailable') }}</p>
        <p v-else class="text-sm text-muted">{{ $t('common.loading') }}</p>
      </SectionCard>
    </div>
    <InlineAlert tone="info" class="mt-6">{{ $t('admin.reports.phase2') }}</InlineAlert>
  </div>
</template>
