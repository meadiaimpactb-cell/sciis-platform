<script setup lang="ts">
/**
 * DOC-02 — follow-up files (milestone evidence, report attachments, indicator evidence, update attachments) opened
 * through a short-lived signed URL: `{downloadBase}/{uuid}/download-url` (access logged server-side).
 */
import type { DocumentResource } from '~/types/api'
import { toastApiError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ documents: DocumentResource[]; downloadBase: string }>()
const { t } = useI18n()
const api = useApi()
const { fileSize } = useFormat()
const opening = ref<string | null>(null)
const open = async (d: DocumentResource) => {
  opening.value = d.uuid
  try {
    const r = await api.get<{ data?: { url: string }; url?: string }>(`${props.downloadBase}/${d.uuid}/download-url`)
    const url = r.data?.url ?? r.url
    if (!url) throw new Error(t('common.noData'))
    window.open(url, '_blank', 'noopener')
    toastSuccess(t('components.docVersions.opened', { name: d.title }))
  } catch (e) { toastApiError(e) } finally { opening.value = null }
}
</script>

<template>
  <ul v-if="documents.length" class="flex flex-wrap gap-2">
    <li v-for="d in documents" :key="d.uuid">
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-full border border-ink/15 bg-surface px-3 py-1.5 text-xs transition hover:border-accent/60 hover:bg-accent/10 kbd-focus disabled:opacity-60"
        :disabled="d.downloadable === false || opening === d.uuid"
        :title="d.downloadable === false ? $t('components.docVersions.notReady') : undefined"
        @click="open(d)"
      >
        <svg class="h-4 w-4 text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5zM14 3v5h5" /></svg>
        <span class="max-w-[14rem] truncate font-medium text-ink">{{ d.title }}</span>
        <span v-if="d.size_bytes" class="num text-muted">{{ fileSize(d.size_bytes) }}</span>
        <span v-if="d.scan_result === 'pending'" class="text-warning">{{ $t('entrepreneur.form.documents.scanPending') }}</span>
      </button>
    </li>
  </ul>
</template>
