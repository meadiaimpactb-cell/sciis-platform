<script setup lang="ts">
/** OPP-09 — the investor's private notes on an opportunity: only the author ever sees them (not the owner, not staff). */
import { z } from 'zod'
import type { InvestorNote } from '~/types/discovery'
import { requiredString } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'

/** Mirrors InvestorNoteRequest (body ≤ 4000). */
const NOTE_MAX = 4000

const props = defineProps<{ uuid: string }>()
const { t } = useI18n()
const { dateTime } = useFormat()
const api = useApi()

const { data: notes, loading, refresh } = useAsyncResource(() => api.get<{ data: InvestorNote[] }>(`/opportunities/${props.uuid}/notes`).then((r) => r.data))

const schema = z.object({ body: requiredString('investor.detail.notes.body', 1, NOTE_MAX) })
const addForm = useZodForm(schema, { body: '' })
const add = () => addForm.submit(async (v) => {
  await api.post(`/opportunities/${props.uuid}/notes`, { body: v.body.trim() })
  addForm.reset()
  toastSuccess(t('investor.detail.notes.saved'))
  await refresh()
})

const editing = ref<number | null>(null)
const editForm = useZodForm(schema, { body: '' })
const startEdit = (n: InvestorNote) => { editForm.reset(); editForm.values.body = n.body; editing.value = n.id }
const saveEdit = (n: InvestorNote) => editForm.submit(async (v) => {
  await api.patch(`/investor/notes/${n.id}`, { body: v.body.trim() })
  editing.value = null
  toastSuccess(t('investor.detail.notes.saved'))
  await refresh()
})
const busy = ref<number | null>(null)
const remove = async (n: InvestorNote) => {
  if (!(await confirmDialog(t('investor.detail.notes.deleteTitle'), t('investor.detail.notes.deleteText'), t('common.delete')))) return
  busy.value = n.id
  try {
    await api.del(`/investor/notes/${n.id}`)
    toastSuccess(t('investor.detail.notes.deleted'))
    await refresh()
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}
</script>

<template>
  <section class="card card-pad" :aria-labelledby="`notes-${uuid}`">
    <div class="flex items-center gap-2">
      <svg class="h-4 w-4 text-accent-text shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 118 0v4" /></svg>
      <h2 :id="`notes-${uuid}`" class="text-base text-ink">{{ $t('investor.detail.notes.title') }}</h2>
    </div>
    <p class="text-xs text-muted mt-1">{{ $t('investor.detail.notes.hint') }}</p>

    <form class="mt-3 space-y-2" novalidate @submit.prevent="add">
      <FormField v-model="addForm.values.body" name="body" type="textarea" :label="$t('investor.detail.notes.body')" :error="addForm.errors.value.body" :rows="3" :maxlength="NOTE_MAX" :placeholder="$t('investor.detail.notes.placeholder')" />
      <FormAlert :message="addForm.formError.value" />
      <AppButton type="submit" size="sm" variant="secondary" block :loading="addForm.submitting.value">{{ $t('investor.detail.notes.add') }}</AppButton>
    </form>

    <p v-if="loading && !notes" class="mt-3 text-xs text-muted">{{ $t('common.loading') }}</p>
    <ul v-else-if="notes?.length" class="mt-4 space-y-3">
      <li v-for="n in notes" :key="n.id" class="rounded-xl border border-border bg-glass p-3 text-sm">
        <form v-if="editing === n.id" class="space-y-2" novalidate @submit.prevent="saveEdit(n)">
          <FormField v-model="editForm.values.body" name="body" type="textarea" :label="$t('investor.detail.notes.body')" :error="editForm.errors.value.body" :rows="3" :maxlength="NOTE_MAX" />
          <FormAlert :message="editForm.formError.value" />
          <div class="flex justify-end gap-2"><AppButton size="sm" variant="ghost" @click="editing = null">{{ $t('common.cancel') }}</AppButton><AppButton type="submit" size="sm" :loading="editForm.submitting.value">{{ $t('common.save') }}</AppButton></div>
        </form>
        <template v-else>
          <p class="leading-6 text-text whitespace-pre-line break-words">{{ n.body }}</p>
          <div class="mt-2 flex items-center justify-between gap-2 text-xs text-muted">
            <span class="num">{{ dateTime(n.updated_at ?? n.created_at) }}</span>
            <span class="flex items-center gap-3">
              <button type="button" class="link text-xs" @click="startEdit(n)">{{ $t('common.edit') }}</button>
              <button type="button" class="text-xs text-danger hover:underline disabled:opacity-50" :disabled="busy === n.id" @click="remove(n)">{{ $t('common.delete') }}</button>
            </span>
          </div>
        </template>
      </li>
    </ul>
  </section>
</template>
