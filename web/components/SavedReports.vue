<script setup lang="ts">
/** RPT-04 saved reports (shared with every `reports.view` holder; the author edits) + RPT-05 weekly / monthly
 *  e-mail schedule to chosen staff (xlsx or PDF). GET|DELETE /admin/saved-reports, PUT …/schedule. */
import { z } from 'zod'
import type { SavedReport } from '~/types/reporting'
import { atLeastOne, requiredChoice } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ canExport: boolean }>()
const emit = defineEmits<{ (e: 'open', r: SavedReport): void }>()
const { t } = useI18n()
const api = useApi()
const { date, relative } = useFormat()
const { download } = useReportDownload()

const items = ref<SavedReport[]>([])
const loading = ref(false)
const load = async () => {
  loading.value = true
  try { items.value = (await api.get<{ data: { items: SavedReport[] } }>('/admin/saved-reports')).data.items }
  catch (e) { toastApiError(e) }
  finally { loading.value = false }
}
onMounted(load)
defineExpose({ load })

const remove = async (r: SavedReport) => {
  if (!(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name: r.name }), t('notify.deleteConfirm')))) return
  try { await api.del(`/admin/saved-reports/${r.uuid}`); toastSuccess(t('notify.deleted', { name: r.name })); await load() }
  catch (e) { toastApiError(e) }
}

// ---------------------------------------------------------------- schedule (RPT-05)
const recipients = ref<{ id: number; name: string }[]>([])
const scheduling = ref<SavedReport | null>(null)
const scheduleForm = useZodForm(
  z.object({ frequency: requiredChoice('admin.savedReports.frequency'), format: requiredChoice('admin.savedReports.format'), recipient_ids: atLeastOne(z.string()) }),
  { frequency: 'weekly', format: 'xlsx', recipient_ids: [] as string[] },
  { aliases: { recipients: 'recipient_ids' } },
)
const openSchedule = async (r: SavedReport) => {
  scheduling.value = r
  scheduleForm.reset({ frequency: r.schedule.frequency ?? 'weekly', format: r.schedule.format ?? 'xlsx', recipient_ids: r.schedule.recipients.map((x) => String(x.id)) })
  if (!recipients.value.length) {
    try { recipients.value = (await api.get<{ data: { items: { id: number; name: string }[] } }>('/admin/report-recipients')).data.items } catch (e) { toastApiError(e) }
  }
}
const saveSchedule = () => scheduleForm.submit(async (v) => {
  const r = scheduling.value!
  await api.put(`/admin/saved-reports/${r.uuid}/schedule`, { frequency: v.frequency, format: v.format, recipients: v.recipient_ids.map(Number) })
  scheduling.value = null
  toastSuccess(t('admin.savedReports.scheduled', { name: r.name }))
  await load()
})
const unschedule = async (r: SavedReport) => {
  if (!(await confirmDialog(t('admin.savedReports.unscheduleTitle'), t('admin.savedReports.unscheduleText', { name: r.name }), t('notify.confirm')))) return
  try { await api.put(`/admin/saved-reports/${r.uuid}/schedule`, { frequency: null }); toastSuccess(t('admin.savedReports.unscheduled', { name: r.name })); await load() }
  catch (e) { toastApiError(e) }
}

const columns = computed(() => [
  { key: 'name', label: t('admin.savedReports.name') },
  { key: 'dataset_label', label: t('admin.reportBuilder.dataset') },
  { key: 'created_by', label: t('admin.savedReports.author') },
  { key: 'schedule', label: t('admin.savedReports.schedule') },
  { key: 'actions', label: '' },
])
</script>

<template>
  <div>
    <DataTable :columns="columns" :rows="items" row-key="uuid" :loading="loading" :empty-text="$t('admin.savedReports.empty')">
      <template #cell-name="{ row }"><button type="button" class="font-medium hover:text-blue text-start kbd-focus rounded" @click="emit('open', row)">{{ row.name }}</button><span class="block text-xs text-muted">{{ relative(row.updated_at) }}</span></template>
      <template #cell-created_by="{ value }">{{ value?.name ?? '—' }}</template>
      <template #cell-schedule="{ row }">
        <template v-if="row.schedule.frequency">
          <AppBadge kind="generic" value="approved" :label="`${$t(`admin.savedReports.frequencies.${row.schedule.frequency}`)} · ${row.schedule.format.toUpperCase()}`" />
          <span class="block text-xs text-muted mt-1">{{ $t('admin.savedReports.nextRun', { date: date(row.schedule.next_run_at) }) }} · {{ row.schedule.recipients.map((x) => x.name).filter(Boolean).join('، ') }}</span>
        </template>
        <span v-else class="text-muted">—</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-1">
          <AppButton size="sm" variant="outline" @click="emit('open', row)">{{ $t('admin.savedReports.open') }}</AppButton>
          <template v-if="props.canExport">
            <AppButton size="sm" variant="ghost" @click="download(`/admin/saved-reports/${row.uuid}/export`, { format: 'xlsx' })">XLSX</AppButton>
            <AppButton size="sm" variant="ghost" @click="download(`/admin/saved-reports/${row.uuid}/export`, { format: 'pdf' })">PDF</AppButton>
          </template>
          <template v-if="row.can_edit">
            <AppButton v-if="props.canExport" size="sm" variant="ghost" @click="openSchedule(row)">{{ $t('admin.savedReports.scheduleAction') }}</AppButton>
            <AppButton v-if="row.schedule.frequency" size="sm" variant="ghost" @click="unschedule(row)">{{ $t('admin.savedReports.unschedule') }}</AppButton>
            <AppButton size="sm" variant="ghost" class="text-danger" @click="remove(row)">{{ $t('common.delete') }}</AppButton>
          </template>
        </div>
      </template>
    </DataTable>
    <p class="mt-2 text-xs text-muted">{{ $t('admin.savedReports.hint') }}</p>

    <Modal :open="!!scheduling" :title="$t('admin.savedReports.scheduleTitle', { name: scheduling?.name ?? '' })" @close="scheduling = null">
      <form id="sr-schedule" class="space-y-4" novalidate @submit.prevent="saveSchedule">
        <FormField v-model="scheduleForm.values.frequency" name="frequency" type="radio-cards" :label="$t('admin.savedReports.frequency')" :options="[{ value: 'weekly', label: $t('admin.savedReports.frequencies.weekly'), hint: $t('admin.savedReports.weeklyHint') }, { value: 'monthly', label: $t('admin.savedReports.frequencies.monthly'), hint: $t('admin.savedReports.monthlyHint') }]" :error="scheduleForm.errors.value.frequency" required />
        <FormField v-model="scheduleForm.values.format" name="format" type="radio-cards" :label="$t('admin.savedReports.format')" :options="[{ value: 'xlsx', label: 'Excel (XLSX)' }, { value: 'pdf', label: 'PDF' }]" :error="scheduleForm.errors.value.format" required />
        <FormField v-model="scheduleForm.values.recipient_ids" name="recipient_ids" type="chips" :label="$t('admin.savedReports.recipients')" :help="$t('admin.savedReports.recipientsHint')" :options="recipients.map((u) => ({ value: String(u.id), label: u.name }))" :error="scheduleForm.errors.value.recipient_ids" required />
        <FormAlert :message="scheduleForm.formError.value" />
      </form>
      <template #footer>
        <AppButton variant="ghost" @click="scheduling = null">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" form="sr-schedule" :loading="scheduleForm.submitting.value">{{ $t('common.save') }}</AppButton>
      </template>
    </Modal>
  </div>
</template>
