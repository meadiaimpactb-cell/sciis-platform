<script setup lang="ts">
/**
 * DOC-03 — document list as a version history: one row per document (its version chain) showing the latest
 * version; older versions expand underneath. Each version shows its number, upload date and uploader (staff /
 * owner views only — the API hides staff names from non-staff). With `downloadBase`, every downloadable version
 * opens through a short-lived signed URL (`{downloadBase}/{uuid}/download-url`, access logged — DOC-02).
 * The `actions` slot renders per-version controls (e.g. the staff accept / reject review).
 */
import type { DocumentResource } from '~/types/api'
import { toastApiError, toastSuccess } from '~/utils/notify'

const props = withDefaults(
  defineProps<{
    documents: DocumentResource[]
    /** `/me/documents` (owner) or `/admin/documents` (staff); null → no open button */
    downloadBase?: string | null
    showNotes?: boolean
    showSize?: boolean
    /** ARIA label of the table */
    caption?: string
  }>(),
  { downloadBase: null, showNotes: false, showSize: false, caption: undefined },
)
defineSlots<{ actions?(p: { doc: DocumentResource; latest: boolean }): unknown }>()

const { t } = useI18n()
const api = useApi()
const { label } = useEnumLabel()
const { date, fileSize } = useFormat()

type Chain = { key: string; latest: DocumentResource; older: DocumentResource[] }
const time = (d: DocumentResource) => Date.parse(d.uploaded_at ?? d.created_at) || 0

/** Versions are linked by `previous_version_uuid`; payloads without the link (older API / mock) group by kind. */
const chains = computed<Chain[]>(() => {
  const byUuid = new Map(props.documents.map((d) => [d.uuid, d]))
  const rootKey = (d: DocumentResource) => {
    if (d.previous_version_uuid === undefined) return `kind:${d.kind}`
    let cur = d
    for (let i = 0; i < 200 && cur.previous_version_uuid && byUuid.has(cur.previous_version_uuid); i++) cur = byUuid.get(cur.previous_version_uuid)!
    return `doc:${cur.uuid}`
  }
  const groups = new Map<string, DocumentResource[]>()
  for (const d of props.documents) {
    const key = rootKey(d)
    groups.set(key, [...(groups.get(key) ?? []), d])
  }
  return [...groups.entries()]
    .map(([key, list]) => {
      const sorted = [...list].sort((a, b) => b.version - a.version || time(b) - time(a))
      return { key, latest: sorted[0], older: sorted.slice(1) }
    })
    .sort((a, b) => time(b.latest) - time(a.latest))
})

const open = reactive<Record<string, boolean>>({})
const columns = computed(() => 3 + (props.showNotes ? 1 : 0) + 1)

const opening = ref<string | null>(null)
const download = async (d: DocumentResource) => {
  if (!props.downloadBase) return
  opening.value = d.uuid
  try {
    const r = await api.get<{ data?: { url: string }; url?: string }>(`${props.downloadBase}/${d.uuid}/download-url`)
    const url = r.data?.url ?? r.url
    if (!url) throw new Error(t('common.noData'))
    window.open(url, '_blank', 'noopener')
    toastSuccess(t('components.docVersions.opened', { name: d.title || label('documentKind', d.kind) }))
  } catch (e) { toastApiError(e) } finally { opening.value = null }
}
const meta = (d: DocumentResource) => [
  `v${d.version}`,
  date(d.uploaded_at ?? d.created_at),
  d.uploaded_by?.name ? t('components.docVersions.by', { name: d.uploaded_by.name }) : null,
  props.showSize && d.size_bytes != null ? fileSize(d.size_bytes) : null,
].filter(Boolean).join(' · ')
</script>

<template>
  <div class="overflow-x-auto">
    <table class="table-base" :aria-label="caption">
      <thead><tr><th>{{ $t('entrepreneur.form.documents.kind') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('common.status') }}</th><th v-if="showNotes">{{ $t('common.notes') }}</th><th><span class="sr-only">{{ $t('components.docVersions.actions') }}</span></th></tr></thead>
      <tbody>
        <template v-for="c in chains" :key="c.key">
          <tr class="align-top">
            <td class="whitespace-nowrap">{{ label('documentKind', c.latest.kind) }}</td>
            <td class="min-w-[14rem]">
              <p class="font-medium break-words">{{ c.latest.title }}</p>
              <p class="text-xs text-muted num">{{ meta(c.latest) }}<span v-if="c.latest.scan_result === 'pending'"> · {{ $t('entrepreneur.form.documents.scanPending') }}</span></p>
              <button v-if="c.older.length" type="button" class="mt-1 text-xs text-blue hover:underline kbd-focus rounded" :aria-expanded="!!open[c.key]" :aria-controls="`dv-${c.latest.uuid}`" @click="open[c.key] = !open[c.key]">
                {{ open[c.key] ? $t('components.docVersions.hideHistory') : $t('components.docVersions.showHistory', { n: c.older.length }) }}
              </button>
            </td>
            <td><AppBadge kind="document" :value="c.latest.status" /></td>
            <td v-if="showNotes" class="text-xs text-muted max-w-xs">{{ c.latest.reviewer_note || '—' }}</td>
            <td class="text-end whitespace-nowrap">
              <slot name="actions" :doc="c.latest" :latest="true" />
              <AppButton v-if="downloadBase" size="sm" variant="outline" :disabled="c.latest.downloadable === false" :loading="opening === c.latest.uuid" :title="c.latest.downloadable === false ? $t('components.docVersions.notReady') : undefined" @click="download(c.latest)">{{ $t('components.docVersions.open') }}</AppButton>
            </td>
          </tr>
          <tr v-if="c.older.length && open[c.key]" :id="`dv-${c.latest.uuid}`" class="bg-bg/50">
            <td :colspan="columns" class="!pt-1">
              <p class="text-xs font-medium text-muted mb-1">{{ $t('components.docVersions.history') }}</p>
              <ol class="divide-y divide-border rounded-lg border border-border bg-surface">
                <li v-for="v in c.older" :key="v.uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
                  <span class="min-w-0 flex-1"><span class="break-words">{{ v.title }}</span> <span class="block text-xs text-muted num">{{ meta(v) }}</span></span>
                  <AppBadge kind="document" :value="v.status" />
                  <span v-if="showNotes && v.reviewer_note" class="text-xs text-muted max-w-xs">{{ v.reviewer_note }}</span>
                  <slot name="actions" :doc="v" :latest="false" />
                  <AppButton v-if="downloadBase" size="sm" variant="ghost" :disabled="v.downloadable === false" :loading="opening === v.uuid" @click="download(v)">{{ $t('components.docVersions.open') }}</AppButton>
                </li>
              </ol>
            </td>
          </tr>
        </template>
        <tr v-if="!chains.length"><td :colspan="columns" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
      </tbody>
    </table>
  </div>
</template>
