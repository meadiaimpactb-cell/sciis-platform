<script setup lang="ts">
/**
 * Admin editor for the marketing copy of the public pages (pages.meta.sections), generated from the typed
 * schema in utils/pageSections.ts. Arabic and English side by side; lists and item groups support add / remove /
 * reorder. Missing CMS values are pre-filled from the i18n texts. Saving merges into the page meta (hero_images kept).
 * The draft is a `useZodForm` model (`sections.<key>.<ar|en>`): lengths / item counts are checked against SECTION_LIMITS
 * on save, server 422s (`meta.sections.<key>.<loc>`) land under the matching field.
 */
import { z, type ZodType } from 'zod'
import type { ContentItem } from '~/types/api'
import { PUBLIC_PAGE_SECTIONS, SECTION_LIMITS, publicPageDef, type PageSectionDef, type PageSections, type PublicPageSlug, type SectionItem } from '~/utils/pageSections'
import { vmsg } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastInfo, toastSuccess } from '~/utils/notify'

type Loc = 'ar' | 'en'
type DraftValue = string | string[] | SectionItem[]
type Draft = Record<string, Record<Loc, DraftValue>>

const { t, te, tm } = useI18n()
const api = useApi()
const localePath = useLocalePath()

const LOCALES: Loc[] = ['ar', 'en']
const slug = ref<PublicPageSlug>('home')
const def = computed(() => publicPageDef(slug.value) ?? PUBLIC_PAGE_SECTIONS[0])
const page = ref<ContentItem | null>(null)
const fromDefault = ref<Record<string, boolean>>({})
const loading = ref(false)

// ---------------------------------------------------------------- draft form (validated on save)
const tooLong = (x: unknown) => typeof x === 'string' && x.trim().length > SECTION_LIMITS.maxString
/** One locale value of a section: text, list of strings, or list of items (`{field: text|boolean}`). */
const sectionValue = z.unknown().superRefine((v, ctx) => {
  const long = () => vmsg('maxLength', { n: SECTION_LIMITS.maxString })
  if (typeof v === 'string') {
    if (tooLong(v)) ctx.addIssue({ code: 'custom', message: long() })
    return
  }
  if (!Array.isArray(v)) return
  if (v.length > SECTION_LIMITS.maxItems) ctx.addIssue({ code: 'custom', message: t('admin.content.sections.maxItems', { n: SECTION_LIMITS.maxItems }) })
  v.forEach((item, i) => {
    if (tooLong(item)) ctx.addIssue({ code: 'custom', path: [i], message: long() })
    else if (item && typeof item === 'object') for (const [k, x] of Object.entries(item)) if (tooLong(x)) ctx.addIssue({ code: 'custom', path: [i, k], message: long() })
  })
})
const sectionsSchema = z.object({ sections: z.record(z.string(), z.object({ ar: sectionValue, en: sectionValue })) })
const sectionAliases = Object.fromEntries(PUBLIC_PAGE_SECTIONS.flatMap((p) => p.sections.flatMap((x) => LOCALES.map((loc) => [`meta.sections.${x.key}.${loc}`, `sections.${x.key}.${loc}`]))))
const sectionsForm = useZodForm(sectionsSchema as unknown as ZodType<{ sections: Draft }>, { sections: {} as Draft }, { aliases: sectionAliases })
const draft = computed(() => sectionsForm.values.sections)
const errs = computed(() => sectionsForm.errors.value)
const path = (key: string, loc: Loc, ...rest: (string | number)[]) => ['sections', key, loc, ...rest].join('.')

const pageOptions = PUBLIC_PAGE_SECTIONS.map((p) => ({ value: p.slug, label: p.label }))
const previewHref = computed(() => localePath(def.value.path))

// ---------------------------------------------------------------- i18n defaults (both locales are bundled: lazy=false)
const trIn = (key: string, loc: Loc) => (te(key, loc) ? t(key, {}, { locale: loc }) : '')
/** Keys of a list / keyed-object i18n message in the current locale (e.g. `home.how` → ['1'..'5']). */
const i18nKeys = (key: string): string[] => {
  const m = tm(key) as unknown
  if (Array.isArray(m)) return m.map((_, i) => String(i))
  return m && typeof m === 'object' ? Object.keys(m as object) : []
}
const i18nDefault = (s: PageSectionDef, loc: Loc): DraftValue => {
  if (s.type === 'text' || s.type === 'textarea') return trIn(s.i18nKey, loc)
  const keys = i18nKeys(s.i18nKey)
  if (s.type === 'list') return keys.map((k) => trIn(`${s.i18nKey}.${k}`, loc)).filter(Boolean)
  const raw = tm(s.i18nKey) as unknown as Record<string, Record<string, unknown>>
  return keys.map((k) => Object.fromEntries((s.fields ?? []).map((f) => [
    f.key,
    f.type === 'boolean' ? !!raw?.[k]?.[f.key] : trIn(`${s.i18nKey}.${k}.${f.key}`, loc),
  ])) as SectionItem)
}

const blankItem = (s: PageSectionDef): SectionItem => Object.fromEntries((s.fields ?? []).map((f) => [f.key, f.type === 'boolean' ? false : '']))
const filled = (v: unknown) => (typeof v === 'string' ? v.trim() !== '' : Array.isArray(v) ? v.length > 0 : false)

/** CMS value normalised to the section type, or null when missing / empty. */
const fromCms = (s: PageSectionDef, v: unknown): DraftValue | null => {
  if (!filled(v)) return null
  if (s.type === 'text' || s.type === 'textarea') return typeof v === 'string' ? v : null
  if (!Array.isArray(v)) return null
  if (s.type === 'list') return v.filter((x): x is string => typeof x === 'string')
  return v.filter((x) => x && typeof x === 'object' && !Array.isArray(x)).map((x) => ({ ...blankItem(s), ...(x as SectionItem) }))
}

const buildDraft = () => {
  const cms = (page.value?.meta?.sections ?? {}) as PageSections
  const d: Draft = {}
  const defaults: Record<string, boolean> = {}
  for (const s of def.value.sections) {
    d[s.key] = { ar: '', en: '' }
    for (const loc of LOCALES) {
      const v = fromCms(s, cms[s.key]?.[loc])
      if (v === null) defaults[s.key] = true
      d[s.key][loc] = v ?? i18nDefault(s, loc)
    }
  }
  sectionsForm.reset({ sections: d })
  fromDefault.value = defaults
}

const load = async () => {
  loading.value = true
  try {
    const list = (await api.get<{ data: ContentItem[] }>('/admin/content/pages', { slug: slug.value })).data
    page.value = list.find((p) => p.slug === slug.value) ?? null
    buildDraft()
  } catch (e) { toastApiError(e) } finally { loading.value = false }
}
onMounted(load)
watch(slug, load)

// ---------------------------------------------------------------- list / item helpers
const arr = (key: string, loc: Loc) => draft.value[key][loc] as unknown[]
const listOf = (key: string, loc: Loc) => draft.value[key][loc] as string[]
const itemsOf = (key: string, loc: Loc) => draft.value[key][loc] as SectionItem[]
const move = (key: string, loc: Loc, i: number, dir: -1 | 1) => {
  const a = arr(key, loc); const j = i + dir
  if (j < 0 || j >= a.length) return
  ;[a[i], a[j]] = [a[j], a[i]]
}
/** Row name for the confirmation: its text (or title) when filled, otherwise "<section> <n>". */
const rowName = (s: PageSectionDef, loc: Loc, i: number) => {
  const row = arr(s.key, loc)[i]
  const text = typeof row === 'string' ? row : row && typeof row === 'object' ? Object.values(row as SectionItem).find((x) => typeof x === 'string' && x.trim()) : ''
  return typeof text === 'string' && text.trim() ? text.trim().slice(0, 60) : `${s.label} ${i + 1}`
}
const removeAt = async (s: PageSectionDef, loc: Loc, i: number) => {
  const name = rowName(s, loc, i)
  if (!(await confirmDialog(t('notify.removeTitle'), t('notify.removeText', { name }), t('notify.removeConfirm')))) return
  arr(s.key, loc).splice(i, 1)
  sectionsForm.clearErrors([path(s.key, loc)])
  toastInfo(t('admin.content.pendingSave', { name }))
}
const addRow = (s: PageSectionDef, loc: Loc) => {
  const a = arr(s.key, loc)
  if (a.length >= SECTION_LIMITS.maxItems) return toastError(t('admin.content.sections.maxItems', { n: SECTION_LIMITS.maxItems }))
  a.push(s.type === 'list' ? '' : blankItem(s))
}
const resetSection = async (s: PageSectionDef) => {
  if (!(await confirmDialog(t('admin.content.sections.resetTitle'), t('admin.content.sections.resetText', { name: s.label }), t('admin.content.sections.reset')))) return
  for (const loc of LOCALES) draft.value[s.key][loc] = i18nDefault(s, loc)
  sectionsForm.clearErrors([`sections.${s.key}`])
  toastInfo(t('admin.content.sections.resetDone', { name: s.label }))
}

const groups = computed(() => {
  const out: { name: string | null; sections: PageSectionDef[] }[] = []
  for (const s of def.value.sections) {
    const last = out[out.length - 1]
    if (last && last.name === (s.group ?? null)) last.sections.push(s)
    else out.push({ name: s.group ?? null, sections: [s] })
  }
  return out
})

// ---------------------------------------------------------------- save (merge into existing meta — keeps hero_images)
const clean = (s: PageSectionDef, v: DraftValue): DraftValue | null => {
  if (typeof v === 'string') return v.trim() === '' ? null : v.trim()
  if (s.type === 'list') return (v as string[]).map((x) => x.trim()).filter(Boolean)
  return (v as SectionItem[])
    .map((item) => Object.fromEntries(Object.entries(item).map(([k, x]) => [k, typeof x === 'string' ? x.trim() : x])) as SectionItem)
    .filter((item) => Object.values(item).some((x) => typeof x === 'string' && x !== ''))
}
const save = () => sectionsForm.submit(async (v) => {
  const existing = (page.value?.meta?.sections ?? {}) as PageSections
  const sections: PageSections = { ...existing }
  for (const s of def.value.sections) sections[s.key] = { ar: clean(s, v.sections[s.key].ar), en: clean(s, v.sections[s.key].en) }
  const meta = { ...(page.value?.meta ?? {}), sections }
  if (page.value) await api.patch(`/admin/content/pages/${page.value.id}`, { meta })
  else await api.post('/admin/content/pages', { slug: def.value.slug, title_ar: def.value.label, title_en: def.value.slug, is_published: true, meta })
  toastSuccess(t('notify.updated', { item: `${t('notify.entities.pageContent')} — ${def.value.label}` }))
  await load()
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard :title="$t('admin.content.sections.title')" :sub="$t('admin.content.sections.sub')">
      <div class="flex flex-wrap items-end gap-3">
        <FormField v-model="slug" name="section_page" type="select" :label="$t('admin.content.sections.page')" :options="pageOptions" class="min-w-[16rem]" />
        <AppButton :href="previewHref" variant="outline">{{ $t('admin.content.sections.preview') }}</AppButton>
      </div>
      <InlineAlert v-if="!loading && !page" tone="info" class="mt-4">{{ $t('admin.content.sections.noPage') }}</InlineAlert>
    </SectionCard>

    <div v-if="loading" class="text-muted">{{ $t('common.loading') }}</div>
    <template v-else>
      <SectionCard v-for="(g, gi) in groups" :key="`${slug}-${gi}`" :title="g.name ?? def.label">
        <div class="space-y-6">
          <div v-for="s in g.sections" :key="s.key" class="rounded-xl border border-border p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
              <h3 class="font-semibold text-sm">{{ s.label }}<span v-if="fromDefault[s.key]" class="ms-2 text-xs font-normal rounded-full bg-bg text-muted px-2 py-0.5">{{ $t('admin.content.sections.fromDefault') }}</span></h3>
              <AppButton size="sm" variant="ghost" @click="resetSection(s)">{{ $t('admin.content.sections.reset') }}</AppButton>
            </div>
            <div class="grid lg:grid-cols-2 gap-4">
              <div v-for="loc in LOCALES" :key="loc" :dir="loc === 'en' ? 'ltr' : 'rtl'" class="min-w-0">
                <p class="text-xs font-medium text-muted mb-1.5">{{ loc === 'ar' ? $t('admin.content.sections.ar') : $t('admin.content.sections.en') }}</p>

                <!-- text / textarea -->
                <FormField
                  v-if="s.type === 'text' || s.type === 'textarea'"
                  v-model="draft[s.key][loc]" :name="path(s.key, loc)" :label="s.label" :type="s.type" :rows="s.type === 'textarea' ? 4 : undefined"
                  :error="errs[path(s.key, loc)]" :maxlength="SECTION_LIMITS.maxString" :ltr="loc === 'en'" class="[&_label]:sr-only"
                />

                <!-- list of strings -->
                <div v-else-if="s.type === 'list'" class="space-y-2">
                  <div v-for="(_, i) in listOf(s.key, loc)" :key="i" class="flex items-start gap-1">
                    <FormField v-model="listOf(s.key, loc)[i]" :name="path(s.key, loc, i)" :label="`${s.label} ${i + 1}`" :error="errs[path(s.key, loc, i)]" :maxlength="SECTION_LIMITS.maxString" :ltr="loc === 'en'" class="flex-1 [&_label]:sr-only" />
                    <AppButton size="sm" variant="ghost" icon :disabled="i === 0" :aria-label="$t('admin.content.hero.moveUp')" @click="move(s.key, loc, i, -1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 15l-6-6-6 6" /></svg></AppButton>
                    <AppButton size="sm" variant="ghost" icon :disabled="i === listOf(s.key, loc).length - 1" :aria-label="$t('admin.content.hero.moveDown')" @click="move(s.key, loc, i, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></AppButton>
                    <AppButton size="sm" variant="ghost" icon class="text-danger" :aria-label="$t('common.delete')" @click="removeAt(s, loc, i)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></AppButton>
                  </div>
                  <p v-if="errs[path(s.key, loc)]" class="error-text" role="alert">{{ errs[path(s.key, loc)] }}</p>
                  <AppButton size="sm" variant="secondary" :disabled="listOf(s.key, loc).length >= SECTION_LIMITS.maxItems" @click="addRow(s, loc)">{{ $t('admin.content.sections.addItem') }}</AppButton>
                </div>

                <!-- list of objects -->
                <div v-else class="space-y-3">
                  <div v-for="(item, i) in itemsOf(s.key, loc)" :key="i" class="rounded-lg border border-border bg-bg/40 p-3">
                    <div class="flex items-center justify-between gap-2 mb-2">
                      <span class="num text-xs font-semibold text-teal-text">{{ String(i + 1).padStart(2, '0') }}</span>
                      <div class="flex gap-1">
                        <AppButton size="sm" variant="ghost" icon :disabled="i === 0" :aria-label="$t('admin.content.hero.moveUp')" @click="move(s.key, loc, i, -1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 15l-6-6-6 6" /></svg></AppButton>
                        <AppButton size="sm" variant="ghost" icon :disabled="i === itemsOf(s.key, loc).length - 1" :aria-label="$t('admin.content.hero.moveDown')" @click="move(s.key, loc, i, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></AppButton>
                        <AppButton size="sm" variant="ghost" icon class="text-danger" :aria-label="$t('common.delete')" @click="removeAt(s, loc, i)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></AppButton>
                      </div>
                    </div>
                    <div class="space-y-2">
                      <FormField
                        v-for="f in s.fields" :key="f.key" v-model="item[f.key]" :name="path(s.key, loc, i, f.key)" :label="f.label" :error="errs[path(s.key, loc, i, f.key)]"
                        :type="f.type === 'boolean' ? 'checkbox' : f.type" :rows="f.type === 'textarea' ? 2 : undefined" :maxlength="f.type === 'boolean' ? undefined : SECTION_LIMITS.maxString" :ltr="loc === 'en'"
                      />
                    </div>
                  </div>
                  <p v-if="errs[path(s.key, loc)]" class="error-text" role="alert">{{ errs[path(s.key, loc)] }}</p>
                  <AppButton size="sm" variant="secondary" :disabled="itemsOf(s.key, loc).length >= SECTION_LIMITS.maxItems" @click="addRow(s, loc)">{{ $t('admin.content.sections.addItem') }}</AppButton>
                </div>
              </div>
            </div>
          </div>
        </div>
      </SectionCard>

      <FormAlert :message="sectionsForm.formError.value" />
      <div class="sticky bottom-4 flex justify-end gap-2">
        <AppButton :href="previewHref" variant="ghost">{{ $t('admin.content.sections.preview') }}</AppButton>
        <AppButton :loading="sectionsForm.submitting.value" @click="save">{{ $t('common.save') }}</AppButton>
      </div>
    </template>
  </div>
</template>
