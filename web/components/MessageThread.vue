<script setup lang="ts">
/**
 * MTG-03 — messages tied to a record: an opportunity file (council staff ↔ owner) or an interest / nomination
 * (council staff ↔ investor). Never owner ↔ investor (MSG-01). Opening the thread marks it read; attachments go
 * through the secure document pipeline and open with a short-lived signed URL. Staff-only internal notes stay
 * separate (opportunity "notes" tab). Party routes: /messages/…, staff: /admin/messages/….
 */
import { z } from 'zod'
import type { ThreadMessage, ThreadSubjectType, ThreadView } from '~/types/collab'
import { optionalText, RULES, vmsg } from '~/utils/validation'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = withDefaults(defineProps<{ subjectType: ThreadSubjectType; subjectKey: string | number; staff?: boolean; showSubject?: boolean }>(), { staff: false, showSubject: false })
const emit = defineEmits<{ (e: 'read'): void; (e: 'posted', m: ThreadMessage): void }>()

const MAX_FILES = 5
const MAX_MB = 25
const ACCEPT = '.pdf,.png,.jpg,.jpeg,.docx,.xlsx,.pptx'

const api = useApi()
const { t } = useI18n()
const { dateTime, fileSize } = useFormat()
const prefix = computed(() => (props.staff ? '/admin' : ''))
const path = computed(() => `${prefix.value}/messages/${props.subjectType}/${encodeURIComponent(String(props.subjectKey))}`)

const view = ref<ThreadView | null>(null)
const loading = ref(false)
const failed = ref(false)
const scroller = ref<HTMLElement | null>(null)
const toBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight })

const load = async () => {
  loading.value = true
  failed.value = false
  try {
    view.value = (await api.get<{ data: ThreadView }>(path.value)).data
    emit('read')
    toBottom()
  } catch (e) {
    failed.value = true
    view.value = null
    toastApiError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(path, load)

/** Who wrote it, from the viewer's point of view (staff see the counterpart's role; parties see "the council"). */
const sideLabel = (m: ThreadMessage) => (m.author.side === 'staff' ? t('collab.messages.sides.staff') : t(`collab.messages.sides.${props.subjectType === 'opportunity' ? 'owner' : 'investor'}`))

// ---- composer: text and/or up to 5 files (same mimes / size as documents — DOC-01)
const form = useZodForm(z.object({ body: optionalText(RULES.longTextMax) }), { body: '' as string | null })
const files = ref<File[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const pickFiles = (ev: Event) => {
  const input = ev.target as HTMLInputElement
  for (const f of Array.from(input.files ?? [])) {
    if (files.value.length >= MAX_FILES) { toastError(t('collab.messages.maxFiles', { n: MAX_FILES })); break }
    if (f.size > MAX_MB * 1024 * 1024) { toastError(t('components.upload.maxSize', { n: MAX_MB })); continue }
    files.value.push(f)
  }
  input.value = ''
}
const removeFile = (i: number) => files.value.splice(i, 1)
const send = async () => {
  if (!(form.values.body ?? '').trim() && !files.value.length) {
    form.setErrors({ body: vmsg('required') } as never)
    return
  }
  await form.submit(async (v) => {
    const fd = new FormData()
    if (v.body?.trim()) fd.append('body', v.body.trim())
    for (const f of files.value) fd.append('files[]', f)
    const r = await api.post<{ data: ThreadMessage }>(path.value, fd)
    view.value?.messages.push(r.data)
    if (view.value && !view.value.thread) view.value.thread = { uuid: '' }
    form.reset()
    files.value = []
    toastSuccess(t('notify.sent', { item: t('notify.entities.message') }))
    emit('posted', r.data)
    toBottom()
  })
}

// ---- attachments: signed URL (DOC-02, access logged)
const opening = ref<string | null>(null)
const openAttachment = async (uuid: string) => {
  opening.value = uuid
  try {
    const r = await api.get<{ data: { url: string } }>(`${prefix.value}/messages/attachments/${uuid}/download-url`)
    window.open(r.data.url, '_blank', 'noopener')
  } catch (e) {
    toastApiError(e)
  } finally {
    opening.value = null
  }
}
defineExpose({ refresh: load })
</script>

<template>
  <div class="flex flex-col min-h-0">
    <header v-if="showSubject && view" class="pb-3 mb-3 border-b border-border">
      <p class="text-xs text-muted">{{ $t(`collab.messages.subjects.${view.subject.type}`) }}<template v-if="view.subject.reference_no"> · <span class="num">{{ view.subject.reference_no }}</span></template></p>
      <p class="font-semibold text-ink">{{ view.subject.title }}</p>
      <p v-if="view.subject.counterpart" class="text-xs text-muted">{{ view.subject.counterpart }}</p>
    </header>

    <p v-if="loading && !view" class="text-sm text-muted py-6 text-center">{{ $t('common.loading') }}</p>
    <div v-else-if="failed" class="py-6 text-center"><AppButton size="sm" variant="secondary" @click="load">{{ $t('common.tryAgain') }}</AppButton></div>
    <template v-else-if="view">
      <div ref="scroller" class="max-h-[26rem] overflow-y-auto scrollbar-thin space-y-3 pe-1" aria-live="polite">
        <p v-if="!view.messages.length" class="text-sm text-muted text-center py-6">{{ $t('collab.messages.empty') }}</p>
        <article v-for="m in view.messages" :key="m.uuid" class="max-w-[85%] rounded-xl px-4 py-3 text-sm" :class="m.author.is_me ? 'ms-auto bg-navy text-white' : m.author.side === 'staff' ? 'bg-teal-50 text-ink border border-teal/20' : 'bg-bg text-ink border border-border'">
          <p class="text-xs font-medium" :class="m.author.is_me ? 'text-white/80' : 'text-muted'">
            {{ m.author.is_me ? $t('collab.messages.you') : m.author.name }} · {{ sideLabel(m) }}
          </p>
          <p v-if="m.body" class="mt-1 leading-6 whitespace-pre-line break-words">{{ m.body }}</p>
          <ul v-if="m.attachments.length" class="mt-2 space-y-1.5">
            <li v-for="a in m.attachments" :key="a.uuid" class="flex flex-wrap items-center gap-2 rounded-lg px-2.5 py-1.5" :class="m.author.is_me ? 'bg-white/10' : 'bg-surface border border-border'">
              <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12.5l-8.5 8.5a5 5 0 01-7-7L14 5.5a3.3 3.3 0 014.7 4.7l-8.5 8.5a1.7 1.7 0 01-2.4-2.4L15.5 8.6" /></svg>
              <span class="min-w-0 flex-1 truncate">{{ a.title }} <span class="text-xs opacity-70 num">({{ fileSize(a.size_bytes) }})</span></span>
              <span v-if="a.scan_pending" class="text-xs opacity-70">{{ $t('collab.messages.scanPending') }}</span>
              <button v-else-if="a.downloadable" type="button" class="text-xs font-medium underline kbd-focus rounded" :disabled="opening === a.uuid" @click="openAttachment(a.uuid)">{{ $t('common.download') }}</button>
              <span v-else class="text-xs opacity-70">{{ $t('collab.messages.unavailable') }}</span>
            </li>
          </ul>
          <p class="mt-1.5 text-[11px] num" :class="m.author.is_me ? 'text-white/60' : 'text-muted'"><time :datetime="m.created_at">{{ dateTime(m.created_at) }}</time></p>
        </article>
      </div>

      <form v-if="view.can_post" class="mt-4 pt-4 border-t border-border space-y-3" novalidate @submit.prevent="send">
        <FormField v-model="form.values.body" name="body" type="textarea" :label="$t('collab.messages.write')" :placeholder="staff ? $t('collab.messages.placeholderStaff') : $t('collab.messages.placeholder')" :error="form.errors.value.body" :rows="3" :maxlength="RULES.longTextMax" />
        <ul v-if="files.length" class="flex flex-wrap gap-1.5">
          <li v-for="(f, i) in files" :key="`${f.name}-${i}`" class="chip chip-active text-xs !py-0.5 inline-flex items-center gap-1">
            <span class="max-w-[12rem] truncate">{{ f.name }}</span> <span class="num opacity-70">({{ fileSize(f.size) }})</span>
            <button type="button" class="ms-1" :aria-label="`${$t('common.delete')} ${f.name}`" @click="removeFile(i)">×</button>
          </li>
        </ul>
        <FormAlert />
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <input ref="fileInput" type="file" class="sr-only" multiple :accept="ACCEPT" :aria-label="$t('collab.messages.attach')" @change="pickFiles">
            <AppButton size="sm" variant="ghost" :disabled="files.length >= MAX_FILES" @click="fileInput?.click()">{{ $t('collab.messages.attach') }}</AppButton>
            <span class="text-xs text-muted">{{ $t('collab.messages.attachHint', { n: MAX_FILES, mb: MAX_MB }) }}</span>
          </div>
          <AppButton type="submit" size="sm" :loading="form.submitting.value">{{ $t('common.send') }}</AppButton>
        </div>
        <p v-if="staff" class="text-xs text-muted">{{ $t('collab.messages.staffHint') }}</p>
      </form>
      <InlineAlert v-else tone="info" class="mt-4">{{ $t('collab.messages.readOnly') }}</InlineAlert>
    </template>
  </div>
</template>
