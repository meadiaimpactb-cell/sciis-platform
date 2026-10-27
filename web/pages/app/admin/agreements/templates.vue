<script setup lang="ts">
/**
 * AGR-01 — agreement templates (agreements.manage): Arabic + English text with placeholders such as {investor_name};
 * every edit saves a new, inactive version; activating a version (legal approval) deactivates the others of its key.
 * AgreementTemplateRequest: key [a-z0-9_-] ≤80 (create only), title 3..190, title_en ≤190, body 20..100000,
 * body_en ≤100000, description / change_note ≤2000.
 */
import { z } from 'zod'
import type { AgreementTemplateFamily, AgreementTemplateItem } from '~/types/api'
import { optionalText, requiredString, RULES, termKeyField } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'

definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t } = useI18n()
useHead({ title: t('agreements.templates.metaTitle') })
const api = useApi()
const { dateTime } = useFormat()
const TEXT_MAX = 100000

type Catalogue = { key: string; label: string }[]
const forbidden = ref(false)
const { data, loading, refresh } = useAsyncResource(async () => {
  try { return (await api.get<{ data: { templates: AgreementTemplateFamily[]; placeholders: Catalogue } }>('/admin/agreement-templates')).data } catch (e) {
    if ((e as { status?: number }).status === 403) forbidden.value = true
    else toastApiError(e)
    return null
  }
})
const open = reactive<Record<string, boolean>>({})
const ph = (key: string) => `{${key}}`

// ---------------------------------------------------------------- editor (create / new version)
const editing = ref<AgreementTemplateItem | null>(null)
const editorOpen = ref(false)
const isNew = computed(() => editing.value === null)
const blank = () => ({ key: '', title: '', title_en: '', description: '', body: '', body_en: '', change_note: '' })
const form = useZodForm(
  z.object({
    key: z.string(),
    title: requiredString('agreements.templates.titleAr', RULES.titleMin, RULES.titleMax),
    title_en: optionalText(RULES.titleMax),
    description: optionalText(RULES.noteMax),
    body: requiredString('agreements.templates.bodyAr', 20, TEXT_MAX),
    body_en: optionalText(TEXT_MAX),
    change_note: optionalText(RULES.noteMax),
  }).superRefine((v, ctx) => {
    if (!isNew.value) return
    const r = termKeyField.safeParse(v.key)
    if (!r.success) ctx.addIssue({ code: 'custom', path: ['key'], message: r.error.issues[0]?.message ?? '' })
  }),
  blank(),
)
const openCreate = () => { editing.value = null; form.reset(blank()); editorOpen.value = true }
const openVersion = (tpl: AgreementTemplateItem) => {
  editing.value = tpl
  form.reset({ key: tpl.key, title: tpl.title, title_en: tpl.title_en ?? '', description: tpl.description ?? '', body: tpl.body, body_en: tpl.body_en ?? '', change_note: '' })
  editorOpen.value = true
}
// Placeholder chips insert at the caret of the last focused text area.
const bodyAr = ref<HTMLTextAreaElement | null>(null)
const bodyEn = ref<HTMLTextAreaElement | null>(null)
const focused = ref<'body' | 'body_en'>('body')
const insert = (key: string) => {
  const el = focused.value === 'body' ? bodyAr.value : bodyEn.value
  const field = focused.value
  const value = String(form.values[field] ?? '')
  const at = el ? el.selectionStart ?? value.length : value.length
  const end = el ? el.selectionEnd ?? at : at
  form.values[field] = value.slice(0, at) + ph(key) + value.slice(end)
  nextTick(() => { if (el) { el.focus(); el.selectionStart = el.selectionEnd = at + ph(key).length } })
}
const save = () => form.submit(async (v) => {
  const body = { title: v.title.trim(), title_en: v.title_en?.trim() || undefined, description: v.description?.trim() || undefined, body: v.body, body_en: v.body_en?.trim() || undefined, change_note: v.change_note?.trim() || undefined }
  if (isNew.value) {
    await api.post('/admin/agreement-templates', { key: v.key.trim(), ...body })
    toastSuccess(t('agreements.toast.templateCreated', { name: body.title }))
  } else {
    const r = await api.post<{ data: AgreementTemplateItem }>(`/admin/agreement-templates/${editing.value!.id}/versions`, body)
    toastSuccess(t('agreements.toast.templateVersion', { name: body.title, version: r.data.version }))
  }
  editorOpen.value = false
  await refresh()
})

// ---------------------------------------------------------------- activate / deactivate
const busy = ref<number | null>(null)
const setActive = async (id: number, title: string, version: number, active: boolean) => {
  const ok = active
    ? await confirmDialog(t('agreements.templates.activateTitle'), t('agreements.templates.activateText', { name: title, version }), t('agreements.templates.activate'))
    : await confirmDialog(t('agreements.templates.deactivateTitle'), t('agreements.templates.deactivateText', { name: title, version }), t('agreements.templates.deactivate'))
  if (!ok) return
  busy.value = id
  try {
    await api.post(`/admin/agreement-templates/${id}/${active ? 'activate' : 'deactivate'}`)
    await refresh()
    toastSuccess(t(active ? 'agreements.toast.templateActivated' : 'agreements.toast.templateDeactivated', { name: title, version }))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// ---------------------------------------------------------------- preview of one version
const preview = ref<AgreementTemplateItem | null>(null)
const previewLang = ref<'ar' | 'en'>('ar')
const langTabs = computed(() => [{ key: 'ar', label: t('agreements.text.ar') }, { key: 'en', label: t('agreements.text.en') }])
const previewing = ref<number | null>(null)
const openPreview = async (id: number) => {
  previewing.value = id
  try { preview.value = (await api.get<{ data: AgreementTemplateItem }>(`/admin/agreement-templates/${id}`)).data; previewLang.value = 'ar' } catch (e) { toastApiError(e) } finally { previewing.value = null }
}
const editFromPreview = () => { const p = preview.value; preview.value = null; if (p) openVersion(p) }
</script>

<template>
  <div>
    <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/admin/agreements')" class="hover:text-accent-text">{{ $t('agreements.title') }}</NuxtLink> / <span>{{ $t('agreements.templates.title') }}</span></nav>
    <PageHeader :title="$t('agreements.templates.title')" :sub="$t('agreements.templates.sub')">
      <template v-if="!forbidden" #actions><AppButton @click="openCreate">{{ $t('agreements.templates.new') }}</AppButton></template>
    </PageHeader>

    <EmptyState v-if="forbidden" :title="$t('notify.forbidden')" />
    <div v-else-if="loading && !data" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="data" class="grid lg:grid-cols-[1fr_320px] gap-5 items-start">
      <div class="space-y-4">
        <EmptyState v-if="!data.templates.length" :title="$t('agreements.templates.empty')"><AppButton size="sm" @click="openCreate">{{ $t('agreements.templates.new') }}</AppButton></EmptyState>
        <article v-for="f in data.templates" :key="f.key" class="card card-pad">
          <div class="flex flex-col sm:flex-row sm:items-start gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <AppBadge v-if="f.active" kind="generic" value="active" :label="$t('agreements.templates.active', { n: f.active.version })" />
                <AppBadge v-else kind="generic" value="suspended" :label="$t('agreements.templates.inactive')" />
                <code class="text-xs ltr rounded-full border border-border bg-ink/5 text-muted px-2 py-0.5">{{ f.key }}</code>
                <span class="text-xs text-muted num">{{ $t('agreements.templates.latest', { n: f.latest.version }) }} · {{ dateTime(f.latest.created_at) }}</span>
              </div>
              <h2 class="mt-2 card-title">{{ f.latest.title }}</h2>
              <p v-if="f.latest.title_en" class="text-sm text-muted ltr text-start">{{ f.latest.title_en }}</p>
              <p v-if="f.latest.description" class="mt-1 text-desc text-muted">{{ f.latest.description }}</p>
              <ul v-if="f.latest.placeholders.length" class="mt-3 flex flex-wrap gap-1.5" :aria-label="$t('agreements.templates.used')"><li v-for="k in f.latest.placeholders" :key="k"><code class="text-xs ltr rounded-full border border-accent/30 bg-accent/10 text-accent-text px-2 py-0.5">{{ ph(k) }}</code></li></ul>
            </div>
            <div class="flex sm:flex-col gap-2 shrink-0">
              <AppButton size="sm" variant="secondary" @click="openVersion(f.latest)">{{ $t('agreements.templates.edit') }}</AppButton>
              <AppButton v-if="!f.latest.is_active" size="sm" :loading="busy === f.latest.id" @click="setActive(f.latest.id, f.latest.title, f.latest.version, true)">{{ $t('agreements.templates.activateVersion', { n: f.latest.version }) }}</AppButton>
              <AppButton v-else size="sm" variant="ghost" class="!text-danger hover:!bg-danger/10" :loading="busy === f.latest.id" @click="setActive(f.latest.id, f.latest.title, f.latest.version, false)">{{ $t('agreements.templates.deactivate') }}</AppButton>
            </div>
          </div>
          <button type="button" class="mt-4 link text-xs kbd-focus rounded" :aria-expanded="!!open[f.key]" @click="open[f.key] = !open[f.key]">{{ $t('agreements.templates.versions', { n: f.versions.length }) }}</button>
          <div v-if="open[f.key]" class="mt-3 overflow-x-auto">
            <table class="table-base">
              <thead><tr><th>{{ $t('common.version') }}</th><th>{{ $t('common.createdAt') }}</th><th>{{ $t('agreements.versions.author') }}</th><th>{{ $t('agreements.versions.note') }}</th><th>{{ $t('common.status') }}</th><th><span class="sr-only">{{ $t('common.actions') }}</span></th></tr></thead>
              <tbody>
                <tr v-for="v in f.versions" :key="v.id">
                  <td class="num font-medium">v{{ v.version }}</td>
                  <td class="num text-muted whitespace-nowrap">{{ dateTime(v.created_at) }}</td>
                  <td>{{ v.created_by ?? '—' }}</td>
                  <td class="min-w-[10rem]">{{ v.change_note ?? '—' }}</td>
                  <td><AppBadge v-if="v.is_active" kind="generic" value="active" :label="$t('agreements.templates.activeShort')" /><span v-else class="text-xs text-muted">—</span></td>
                  <td class="whitespace-nowrap"><div class="flex gap-1 justify-end"><AppButton size="sm" variant="ghost" :loading="previewing === v.id" @click="openPreview(v.id)">{{ $t('common.view') }}</AppButton><AppButton v-if="!v.is_active" size="sm" variant="outline" :loading="busy === v.id" @click="setActive(v.id, v.title, v.version, true)">{{ $t('agreements.templates.activate') }}</AppButton></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </div>

      <SectionCard :title="$t('agreements.templates.placeholders')" :sub="$t('agreements.templates.placeholdersSub')" class="lg:sticky lg:top-6">
        <ul class="space-y-2 text-sm">
          <li v-for="p in data.placeholders" :key="p.key" class="flex items-center justify-between gap-2"><span>{{ p.label }}</span><code class="text-xs ltr rounded-full border border-border bg-ink/5 text-muted px-2 py-0.5">{{ ph(p.key) }}</code></li>
        </ul>
        <p class="mt-3 text-xs text-muted leading-5">{{ $t('agreements.templates.customHint') }}</p>
      </SectionCard>
    </div>

    <!-- editor -->
    <Modal :open="editorOpen" :title="isNew ? $t('agreements.templates.new') : $t('agreements.templates.newVersionOf', { name: editing?.title ?? '' })" size="lg" @close="editorOpen = false">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <InlineAlert v-if="!isNew" tone="info">{{ $t('agreements.templates.versionHint') }}</InlineAlert>
        <FormField v-if="isNew" v-model="form.values.key" name="key" :label="$t('agreements.templates.key')" :help="$t('agreements.templates.keyHint')" :maxlength="RULES.termKeyMax" :error="form.errors.value.key" required ltr />
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="form.values.title" name="title" :label="$t('agreements.templates.titleAr')" :maxlength="RULES.titleMax" :error="form.errors.value.title" required />
          <FormField v-model="form.values.title_en" name="title_en" :label="$t('agreements.templates.titleEn')" :maxlength="RULES.titleMax" :error="form.errors.value.title_en" ltr />
        </div>
        <FormField v-model="form.values.description" name="description" :label="$t('agreements.templates.description')" :maxlength="RULES.noteMax" :error="form.errors.value.description" />
        <div>
          <p class="label">{{ $t('agreements.templates.insert') }}</p>
          <ul class="flex flex-wrap gap-1.5"><li v-for="p in data?.placeholders ?? []" :key="p.key"><button type="button" class="chip text-xs !py-0.5" :title="p.label" @click="insert(p.key)">{{ p.label }} <code class="ltr opacity-70">{{ ph(p.key) }}</code></button></li></ul>
        </div>
        <div>
          <label class="label" for="tpl-body">{{ $t('agreements.templates.bodyAr') }} <span class="text-danger" aria-hidden="true">*</span></label>
          <textarea id="tpl-body" ref="bodyAr" v-model="form.values.body" name="body" rows="14" :maxlength="TEXT_MAX" class="input resize-y" :class="form.errors.value.body && 'input-error'" :aria-invalid="!!form.errors.value.body" required @focus="focused = 'body'" @blur="form.validateField('body')" />
          <p v-if="form.errors.value.body" class="error-text" role="alert">{{ form.errors.value.body }}</p>
          <p class="text-xs text-muted mt-1">{{ $t('agreements.templates.bodyHint') }}</p>
        </div>
        <div>
          <label class="label" for="tpl-body-en">{{ $t('agreements.templates.bodyEn') }} <span class="text-xs text-muted font-normal ms-1">({{ $t('common.optional') }})</span></label>
          <textarea id="tpl-body-en" ref="bodyEn" v-model="form.values.body_en" name="body_en" dir="ltr" lang="en" rows="10" :maxlength="TEXT_MAX" class="input resize-y ltr text-start" :class="form.errors.value.body_en && 'input-error'" :aria-invalid="!!form.errors.value.body_en" @focus="focused = 'body_en'" @blur="form.validateField('body_en')" />
          <p v-if="form.errors.value.body_en" class="error-text" role="alert">{{ form.errors.value.body_en }}</p>
        </div>
        <FormField v-if="!isNew" v-model="form.values.change_note" name="change_note" :label="$t('agreements.edit.changeNote')" :maxlength="RULES.noteMax" :error="form.errors.value.change_note" />
      </form>
      <template #footer><FormAlert :message="form.formError.value" class="basis-full" /><AppButton variant="ghost" @click="editorOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="form.submitting.value" @click="save">{{ isNew ? $t('agreements.templates.create') : $t('agreements.templates.saveVersion') }}</AppButton></template>
    </Modal>

    <!-- preview -->
    <Modal :open="!!preview" :title="preview ? `${preview.title} — v${preview.version}` : ''" size="lg" @close="preview = null">
      <div v-if="preview" class="space-y-4">
        <Tabs v-model="previewLang" :tabs="langTabs" variant="pills" />
        <div class="rounded-xl border border-border bg-ink/[0.03] p-5">
          <AgreementText v-if="previewLang === 'ar'" :text="preview.body" lang="ar" />
          <AgreementText v-else-if="preview.body_en" :text="preview.body_en" lang="en" />
          <p v-else class="text-sm text-muted">{{ $t('agreements.text.noEn') }}</p>
        </div>
        <p v-if="preview.legal_approved_at" class="text-xs text-muted">{{ $t('agreements.templates.legalApproved', { date: dateTime(preview.legal_approved_at) }) }}</p>
      </div>
      <template #footer><AppButton variant="ghost" @click="preview = null">{{ $t('common.close') }}</AppButton><AppButton v-if="preview" variant="secondary" @click="editFromPreview">{{ $t('agreements.templates.edit') }}</AppButton></template>
    </Modal>
  </div>
</template>
