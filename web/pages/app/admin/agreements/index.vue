<script setup lang="ts">
/**
 * AGR-02 — agreements list: status chips (with counts), search, opportunity filter (?opportunity=uuid from the
 * opportunity file), and "draft an agreement" for a connection from an active template with its placeholder values
 * (StoreAgreementRequest: interest_id, template_id, title 3..190, placeholders.* ≤500).
 */
import { z } from 'zod'
import type { AgreementConnection, AgreementListItem, AgreementTemplateFamily, Paginated } from '~/types/api'
import { AGREEMENT_STATUSES, type AgreementStatus } from '~/types/enums'
import { optionalText, requiredChoice, RULES } from '~/utils/validation'
import { toastApiError, toastSuccess } from '~/utils/notify'

definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t, te } = useI18n()
useHead({ title: t('agreements.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { relative } = useFormat()
const canManage = computed(() => auth.canAny('agreements.manage'))

const qv = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '')
const status = ref<AgreementStatus | ''>(qv('status') as AgreementStatus | '')
const search = ref(qv('search'))
const opportunity = ref(qv('opportunity'))
const page = ref(Number(qv('page')) || 1)
const rows = ref<AgreementListItem[]>([])
const meta = ref({ total: 0, last_page: 1, counts: {} as Partial<Record<AgreementStatus, number>> })
const loading = ref(false)
const forbidden = ref(false)
const total = computed(() => Object.values(meta.value.counts).reduce((a, b) => a + (b ?? 0), 0))
const opportunityName = computed(() => rows.value.find((r) => r.opportunity?.uuid === opportunity.value)?.opportunity?.name ?? '')

const syncUrl = () => router.replace({ query: { ...(status.value ? { status: status.value } : {}), ...(search.value.trim() ? { search: search.value.trim() } : {}), ...(opportunity.value ? { opportunity: opportunity.value } : {}), ...(page.value > 1 ? { page: String(page.value) } : {}) } })
const load = async () => {
  loading.value = true
  try {
    const q: Record<string, unknown> = { page: page.value, per_page: 20 }
    if (status.value) q.status = status.value
    if (search.value.trim()) q.search = search.value.trim()
    if (opportunity.value) q.opportunity = opportunity.value
    const r = await api.get<Paginated<AgreementListItem> & { meta: { counts?: Partial<Record<AgreementStatus, number>> } }>('/admin/agreements', q)
    rows.value = r.data ?? []
    meta.value = { total: r.meta?.total ?? rows.value.length, last_page: r.meta?.last_page ?? 1, counts: r.meta?.counts ?? {} }
  } catch (e) {
    if ((e as { status?: number }).status === 403) forbidden.value = true
    else toastApiError(e)
  } finally { loading.value = false }
}
onMounted(load)
watch([status, opportunity], () => { page.value = 1; syncUrl(); load() })
watch(page, () => { syncUrl(); load() })
let st: ReturnType<typeof setTimeout>
watch(search, () => { clearTimeout(st); st = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300) })

const columns = [
  { key: 'number', label: t('agreements.columns.number'), sortable: true },
  { key: 'title', label: t('agreements.columns.title') },
  { key: 'parties', label: t('agreements.columns.parties') },
  { key: 'status', label: t('common.status') },
  { key: 'version', label: t('agreements.columns.version') },
  { key: 'updated_at', label: t('common.updatedAt'), sortable: true },
]

// ---------------------------------------------------------------- draft (AGR-02)
const createOpen = ref(false)
const connections = ref<AgreementConnection[]>([])
const templates = ref<AgreementTemplateFamily[]>([])
const fields = ref<{ key: string; value: string; auto: boolean }[]>([])
const preparing = ref(false)
const createForm = useZodForm(
  z.object({ interest_id: requiredChoice('agreements.create.connection'), template_id: requiredChoice('agreements.create.template'), title: optionalText(RULES.titleMax), placeholders: z.record(z.string(), optionalText(RULES.shortTextMax)) }),
  { interest_id: '' as string | number, template_id: '' as string | number, title: '', placeholders: {} as Record<string, string> },
)
const openCreate = async () => {
  createForm.reset({ interest_id: '', template_id: '', title: '', placeholders: {} })
  fields.value = []
  createOpen.value = true
  try {
    const [c, tpl] = await Promise.all([
      api.get<{ data: { connections: AgreementConnection[] } }>('/admin/agreements/connections').then((r) => r.data.connections),
      api.get<{ data: { templates: AgreementTemplateFamily[] } }>('/admin/agreement-templates', { active: 1 }).then((r) => r.data.templates),
    ])
    connections.value = c
    templates.value = tpl.filter((f) => f.active)
    const forOpp = opportunity.value ? c.filter((x) => x.opportunity.uuid === opportunity.value) : []
    if (forOpp.length === 1) createForm.values.interest_id = String(forOpp[0].interest_id)
    if (templates.value.length === 1) createForm.values.template_id = String(templates.value[0].active!.id)
  } catch (e) { toastApiError(e) }
}
const connectionLabel = (c: AgreementConnection) => `${c.opportunity.reference_no ?? '—'} — ${c.opportunity.name} · ${c.investor?.name ?? '—'}`
const fieldLabel = (key: string) => (te(`agreements.placeholders.${key}`) ? t(`agreements.placeholders.${key}`) : key)
// Placeholder values are fetched once both the connection and the template are chosen.
watch(() => [createForm.values.interest_id, createForm.values.template_id], async ([interest, template]) => {
  fields.value = []
  if (!interest || !template) return
  preparing.value = true
  try {
    const r = await api.get<{ data: { title: string; placeholders: { key: string; value: string; auto: boolean }[] } }>('/admin/agreements/draft-preview', { interest_id: interest, template_id: template })
    fields.value = r.data.placeholders
    createForm.values.placeholders = Object.fromEntries(r.data.placeholders.map((p) => [p.key, p.value]))
    if (!createForm.values.title) createForm.values.title = r.data.title
  } catch (e) { toastApiError(e) } finally { preparing.value = false }
})
const { locale } = useI18n()
const missing = computed(() => fields.value.filter((f) => !String(createForm.values.placeholders[f.key] ?? '').trim()).map((f) => fieldLabel(f.key)).join(locale.value === 'ar' ? '، ' : ', '))
const create = () => createForm.submit(async (v) => {
  const r = await api.post<{ data: AgreementListItem }>('/admin/agreements', {
    interest_id: Number(v.interest_id), template_id: Number(v.template_id), title: v.title?.trim() || undefined,
    placeholders: Object.fromEntries(Object.entries(v.placeholders).map(([k, val]) => [k, String(val ?? '').trim()])),
  })
  createOpen.value = false
  toastSuccess(t('agreements.toast.created', { number: r.data.number }))
  await navigateTo(localePath(`/app/admin/agreements/${r.data.uuid}`))
})
</script>

<template>
  <div>
    <PageHeader :title="$t('agreements.title')" :sub="$t('agreements.sub')">
      <template v-if="canManage" #actions>
        <AppButton variant="secondary" to="/app/admin/agreements/templates">{{ $t('agreements.templates.title') }}</AppButton>
        <AppButton @click="openCreate">{{ $t('agreements.create.open') }}</AppButton>
      </template>
    </PageHeader>

    <EmptyState v-if="forbidden" :title="$t('notify.forbidden')" />
    <template v-else>
      <div class="flex flex-wrap gap-2 mb-3" role="group" :aria-label="$t('agreements.filters.status')">
        <button type="button" class="chip" :class="!status && 'chip-active'" :aria-pressed="!status" @click="status = ''">{{ $t('common.all') }} <span class="num text-xs opacity-70">{{ total }}</span></button>
        <button v-for="s in AGREEMENT_STATUSES" :key="s" type="button" class="chip" :class="status === s && 'chip-active'" :aria-pressed="status === s" @click="status = s">{{ $t(`enums.agreementStatus.${s}`) }} <span class="num text-xs opacity-70">{{ meta.counts[s] ?? 0 }}</span></button>
      </div>
      <div class="card p-3 mb-4 flex flex-col sm:flex-row gap-3 sm:items-center">
        <input v-model="search" type="search" class="input sm:max-w-sm" :placeholder="$t('agreements.filters.search')" :aria-label="$t('common.search')" />
        <span v-if="opportunity" class="chip chip-active text-xs inline-flex items-center gap-1">{{ $t('agreements.filters.opportunity') }}<template v-if="opportunityName">: {{ opportunityName }}</template><button type="button" class="ms-1" :aria-label="$t('common.reset')" @click="opportunity = ''">×</button></span>
      </div>

      <DataTable :columns="columns" :rows="rows" row-key="uuid" :loading="loading" :empty-text="$t('agreements.empty')">
        <template #cell-number="{ row }"><NuxtLink :to="$localePath(`/app/admin/agreements/${row.uuid}`)" class="num font-medium hover:text-blue whitespace-nowrap">{{ row.number }}</NuxtLink></template>
        <template #cell-title="{ row }"><NuxtLink :to="$localePath(`/app/admin/agreements/${row.uuid}`)" class="font-medium hover:text-blue">{{ row.title ?? '—' }}</NuxtLink><span v-if="row.opportunity" class="block text-xs text-muted"><span class="num">{{ row.opportunity.reference_no ?? '' }}</span> {{ row.opportunity.name }}</span></template>
        <template #cell-parties="{ row }"><span class="block">{{ row.owner?.name ?? '—' }}</span><span class="block text-xs text-muted">{{ row.investor?.name ?? '—' }}</span></template>
        <template #cell-status="{ value }"><AppBadge kind="agreement" :value="value" /></template>
        <template #cell-version="{ row }"><span class="num text-muted">{{ row.current_version ? `v${row.current_version.version}` : '—' }}</span></template>
        <template #cell-updated_at="{ value }"><span class="text-muted whitespace-nowrap">{{ relative(value) }}</span></template>
        <template #footer><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></template>
      </DataTable>
    </template>

    <Modal :open="createOpen" :title="$t('agreements.create.title')" size="lg" @close="createOpen = false">
      <form class="space-y-4" novalidate @submit.prevent="create">
        <p class="text-sm text-muted">{{ $t('agreements.create.sub') }}</p>
        <FormField v-model="createForm.values.interest_id" name="interest_id" type="select" :label="$t('agreements.create.connection')" :options="connections.map((c) => ({ value: String(c.interest_id), label: connectionLabel(c) }))" :help="connections.length ? $t('agreements.create.connectionHint') : $t('agreements.create.noConnections')" :error="createForm.errors.value.interest_id" required />
        <FormField v-model="createForm.values.template_id" name="template_id" type="select" :label="$t('agreements.create.template')" :options="templates.map((f) => ({ value: String(f.active!.id), label: `${f.active!.title} (v${f.active!.version})` }))" :help="templates.length ? undefined : $t('agreements.create.noTemplates')" :error="createForm.errors.value.template_id" required />
        <FormField v-model="createForm.values.title" name="title" :label="$t('agreements.create.titleField')" :maxlength="RULES.titleMax" :error="createForm.errors.value.title" />
        <div v-if="preparing" class="text-sm text-muted">{{ $t('common.loading') }}</div>
        <fieldset v-else-if="fields.length" class="rounded-lg border border-border p-4">
          <legend class="px-1 text-sm font-semibold">{{ $t('agreements.create.fields') }}</legend>
          <p class="text-xs text-muted mb-3">{{ $t('agreements.create.fieldsHint') }}</p>
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-for="f in fields" :key="f.key" v-model="createForm.values.placeholders[f.key]" :name="`placeholders.${f.key}`" :label="fieldLabel(f.key)" :help="f.auto ? $t('agreements.create.auto') : $t('agreements.create.manual')" :maxlength="RULES.shortTextMax" :error="createForm.errors.value[`placeholders.${f.key}`]" />
          </div>
          <InlineAlert v-if="missing" tone="warning" class="mt-3">{{ $t('agreements.create.missing', { fields: missing }) }}</InlineAlert>
        </fieldset>
      </form>
      <template #footer><FormAlert :message="createForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="createOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="createForm.submitting.value" :disabled="preparing || !connections.length || !templates.length" @click="create">{{ $t('agreements.create.submit') }}</AppButton></template>
    </Modal>
  </div>
</template>
