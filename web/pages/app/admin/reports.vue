<script setup lang="ts">
import type { ReportResult } from '~/types/api'
import { REPORT_KEYS } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['reports_viewer', 'impact_officer', 'membership_officer'] })
const { t } = useI18n()
useHead({ title: t('admin.reports.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { label } = useEnumLabel()
const { number, relative } = useFormat()
const reports = ref<Record<string, ReportResult | null>>({})
onMounted(async () => { await Promise.all(REPORT_KEYS.map(async (k) => { try { reports.value[k] = (await api.get<{ data: ReportResult }>(`/admin/reports/${k}`)).data } catch { reports.value[k] = null } })) })
const titles: Record<string, string> = { registrations_by_type: 'التسجيلات حسب النوع', memberships_by_status: 'العضويات حسب الحالة', opportunities_funnel: 'قمع الفرص', opportunities_by_sector_stage_region: 'الفرص حسب القطاع', first_time_completion_rate: 'نسبة الاكتمال من أول مرة', avg_review_time: 'متوسط زمن الفرز', avg_evaluation_time: 'متوسط زمن التقييم', overdue_items: 'العناصر المتأخرة', eligibility_rate: 'معدل الأهلية', interest_rate: 'معدل الاهتمام', connection_rate: 'معدل الربط', acquisition_channels: 'قنوات الاستقطاب' }
const translate = (s: string) => { for (const g of ['userType', 'membershipStatus', 'opportunityStatus']) { const l = label(g, s); if (l !== s) return l } return ({ submitted: 'مقدّمة', review: 'الفرز', completion: 'الاستكمال', evaluation: 'التقييم', rate: 'النسبة', days: 'أيام' } as Record<string, string>)[s] ?? s }
const exportKey = async (k: string) => { try { const r = await api.get<{ url: string; note?: string }>(`/admin/reports/${k}/export.xlsx`); toast.info(r.note ?? r.url) } catch (e) { toast.error((e as Error).message) } }
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.reports.title')" :sub="$t('admin.reports.sub')" />
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
      <SectionCard v-for="k in REPORT_KEYS" :key="k" :title="titles[k]" :sub="reports[k] ? $t('admin.reports.generated', { time: relative(reports[k]!.generated_at) }) : undefined">
        <template #actions><AppButton v-if="auth.hasRole('reports_viewer')" size="sm" variant="ghost" @click="exportKey(k)">{{ $t('admin.reports.export') }}</AppButton></template>
        <template v-if="reports[k]">
          <div v-if="reports[k]!.kind === 'number'" class="py-4 text-center"><span class="text-5xl font-semibold num text-blue">{{ number(reports[k]!.series[0]?.value) }}</span><span class="text-muted ms-1">{{ reports[k]!.unit === '%' ? '%' : $t('common.days', 0) }}</span></div>
          <BarChart v-else :series="reports[k]!.series.map((s) => ({ ...s, label: translate(s.label) }))" :funnel="reports[k]!.kind === 'funnel'" />
        </template>
        <p v-else class="text-sm text-muted">{{ $t('common.loading') }}</p>
      </SectionCard>
    </div>
    <InlineAlert tone="info" class="mt-6">{{ $t('admin.reports.phase2') }}</InlineAlert>
  </div>
</template>
