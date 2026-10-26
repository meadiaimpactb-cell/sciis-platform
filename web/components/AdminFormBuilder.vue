<script setup lang="ts">
/**
 * FRM-01 — "additional questions" builder (no code), admin → settings → additional questions (settings.manage).
 * One tab per form (investor registration / profile, project form, membership review). Staff add, edit, reorder and
 * deactivate questions (7 types, Arabic + English label and help, required flag, "show when question X equals Y"),
 * preview them live, then publish: POST /admin/forms/{key}/publish creates a new schema version (the previous one
 * stays readable for answers given against it). Question ids and option values are generated once and never change.
 */
import type { AdminFormSchema, AnswerMap, FormKey, FormQuestion, QuestionType } from '~/types/platform'
import { FORM_KEYS, QUESTION_TYPES } from '~/types/platform'
import { parseApiError } from '~/utils/apiError'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'

type DraftQuestion = FormQuestion & { _key: string; _open: boolean }

const { t } = useI18n()
const api = useApi()
const { dateTime } = useFormat()

const key = ref<FormKey>('investor_profile')
const formTabs = computed(() => FORM_KEYS.map((k) => ({ key: k, label: t(`platform.builder.forms.${k}`) })))
const schema = ref<AdminFormSchema | null>(null)
const draft = ref<DraftQuestion[]>([])
const loading = ref(false)
const publishing = ref(false)
const errors = ref<Record<string, string>>({})
let snapshot = '[]'

const rid = (n: number) => Math.random().toString(36).slice(2, 2 + n).padEnd(n, '0')
const toDraft = (q: FormQuestion): DraftQuestion => ({ ...q, options: q.options.map((o) => ({ ...o })), show_when: q.show_when ? { ...q.show_when } : null, _key: q.id, _open: false })
const clean = (list: DraftQuestion[]): FormQuestion[] => list.map(({ _key, _open, ...q }) => ({
  ...q,
  label_ar: q.label_ar.trim(),
  label_en: q.label_en?.trim() || null,
  help_ar: q.help_ar?.trim() || null,
  help_en: q.help_en?.trim() || null,
  options: ['single_choice', 'multiple_choice'].includes(q.type) ? q.options.map((o) => ({ value: o.value, label_ar: o.label_ar.trim(), label_en: o.label_en?.trim() || null })) : [],
  show_when: q.show_when?.question ? q.show_when : null,
}))
const dirty = computed(() => JSON.stringify(clean(draft.value)) !== snapshot)

const load = async () => {
  loading.value = true
  errors.value = {}
  try {
    schema.value = (await api.get<{ data: AdminFormSchema }>(`/admin/forms/${key.value}`)).data
    draft.value = schema.value.questions.map(toDraft)
    snapshot = JSON.stringify(clean(draft.value))
  } catch (e) {
    toastApiError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)
let reverting = false
watch(key, async (_next, prev) => {
  if (reverting) { reverting = false; return }
  if (dirty.value && !(await confirmDialog(t('platform.builder.discardTitle'), t('platform.builder.discardText'), t('platform.builder.discardConfirm')))) {
    reverting = true
    key.value = prev
    return
  }
  await load()
})

const typeOpts = computed(() => QUESTION_TYPES.map((v) => ({ value: v, label: t(`platform.builder.types.${v}`) })))
const isChoice = (type: QuestionType) => type === 'single_choice' || type === 'multiple_choice'
const addQuestion = () => {
  draft.value.push({ id: `q_${rid(6)}`, type: 'short_text', label_ar: '', label_en: '', help_ar: '', help_en: '', required: false, active: true, options: [], show_when: null, _key: rid(8), _open: true })
}
const onType = (q: DraftQuestion, type: QuestionType) => {
  q.type = type
  if (isChoice(type) && q.options.length < 2) while (q.options.length < 2) q.options.push({ value: `o${rid(5)}`, label_ar: '', label_en: '' })
  // dependants of this question keep a condition only if the value still fits
  for (const d of draft.value) if (d.show_when?.question === q.id && !conditionValues(q).some((o) => o.value === d.show_when?.equals)) d.show_when.equals = ''
}
const addOption = (q: DraftQuestion) => q.options.push({ value: `o${rid(5)}`, label_ar: '', label_en: '' })
const removeOption = (q: DraftQuestion, i: number) => {
  const [gone] = q.options.splice(i, 1)
  for (const d of draft.value) if (d.show_when?.question === q.id && d.show_when.equals === gone?.value) d.show_when.equals = ''
}

/** "Show when" may only point to an earlier yes / no or choice question. */
const drivers = (i: number) => draft.value.slice(0, i).filter((d) => d.type === 'yes_no' || isChoice(d.type))
const conditionValues = (driver: FormQuestion | undefined) => {
  if (!driver) return []
  if (driver.type === 'yes_no') return [{ value: 'yes', label: t('common.yes') }, { value: 'no', label: t('common.no') }]
  return driver.options.map((o) => ({ value: o.value, label: o.label_ar || o.value }))
}
const driverOf = (q: DraftQuestion) => draft.value.find((d) => d.id === q.show_when?.question)
const setCondition = (q: DraftQuestion, question: string | null) => (q.show_when = question ? { question, equals: '' } : null)

/** Moving must keep every condition pointing at an earlier question. */
const canMove = (i: number, dir: -1 | 1) => {
  const j = i + dir
  if (j < 0 || j >= draft.value.length) return false
  const [a, b] = dir === -1 ? [draft.value[j], draft.value[i]] : [draft.value[i], draft.value[j]]
  return b.show_when?.question !== a.id
}
const move = (i: number, dir: -1 | 1) => {
  if (!canMove(i, dir)) return
  const list = draft.value
  ;[list[i], list[i + dir]] = [list[i + dir], list[i]]
}
const remove = async (i: number) => {
  const q = draft.value[i]
  const name = q.label_ar.trim() || t('platform.builder.untitled')
  if (!(await confirmDialog(t('notify.deleteTitle'), t('platform.builder.removeText', { name }), t('notify.deleteConfirm')))) return
  draft.value.splice(i, 1)
  for (const d of draft.value) if (d.show_when?.question === q.id) d.show_when = null
}

/** Per-question messages: client checks first, then the API's `questions.{i}.{field}` keys. */
const errorFor = (i: number, field: string) => errors.value[`questions.${i}.${field}`]
const questionHasError = (i: number) => Object.keys(errors.value).some((k) => k.startsWith(`questions.${i}.`))
const check = () => {
  const out: Record<string, string> = {}
  draft.value.forEach((q, i) => {
    if (!q.label_ar.trim()) out[`questions.${i}.label_ar`] = t('validation.required')
    if (isChoice(q.type)) {
      if (q.options.length < 2) out[`questions.${i}.options`] = t('platform.builder.optionsMin')
      q.options.forEach((o, j) => { if (!o.label_ar.trim()) out[`questions.${i}.options.${j}.label_ar`] = t('validation.required') })
    }
    if (q.show_when && !q.show_when.equals) out[`questions.${i}.show_when.equals`] = t('validation.required')
  })
  errors.value = out
  draft.value.forEach((q, i) => { if (questionHasError(i)) q._open = true })
  return !Object.keys(out).length
}

const publish = async () => {
  if (!check()) return void toastError(t('validation.fixErrors'))
  const next = (schema.value?.version ?? 0) + 1
  if (!(await confirmDialog(t('platform.builder.publishTitle', { version: next }), t('platform.builder.publishText'), t('platform.builder.publish')))) return
  publishing.value = true
  try {
    schema.value = (await api.post<{ data: AdminFormSchema }>(`/admin/forms/${key.value}/publish`, { questions: clean(draft.value) })).data
    draft.value = schema.value.questions.map(toDraft)
    snapshot = JSON.stringify(clean(draft.value))
    errors.value = {}
    toastSuccess(t('platform.builder.published', { version: schema.value.version }))
  } catch (e) {
    const p = parseApiError(e)
    if (p.status === 422 && Object.keys(p.errors).length) {
      errors.value = p.errors
      draft.value.forEach((q, i) => { if (questionHasError(i)) q._open = true })
      toastError(t('validation.fixErrors'))
    } else toastApiError(e)
  } finally {
    publishing.value = false
  }
}
const reset = () => {
  draft.value = (schema.value?.questions ?? []).map(toDraft)
  errors.value = {}
}

// live preview of the draft
const previewAnswers = ref<AnswerMap>({})
const previewQuestions = computed(() => clean(draft.value).filter((q) => q.label_ar))
const maxQuestions = computed(() => schema.value?.limits.max_questions ?? 40)
const label = (q: DraftQuestion) => q.label_ar.trim() || t('platform.builder.untitled')
</script>

<template>
  <div class="space-y-5">
    <SectionCard :title="$t('platform.builder.title')" :sub="$t('platform.builder.sub')">
      <Tabs v-model="key" :tabs="formTabs" variant="pills" class="w-fit max-w-full" />
      <p class="mt-3 text-sm text-muted" aria-live="polite">
        <template v-if="loading">{{ $t('common.loading') }}</template>
        <template v-else-if="schema?.version">{{ $t('platform.builder.currentVersion', { version: schema.version }) }} <span class="num">· {{ dateTime(schema.published_at) }}</span></template>
        <template v-else>{{ $t('platform.builder.notPublished') }}</template>
      </p>
    </SectionCard>

    <div class="grid xl:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
      <div class="space-y-3 min-w-0">
        <p v-if="!draft.length && !loading" class="card card-pad text-sm text-muted">{{ $t('platform.builder.empty') }}</p>
        <ol class="space-y-3">
          <li v-for="(q, i) in draft" :key="q._key" class="card" :class="[!q.active && 'opacity-75', questionHasError(i) && 'border-danger/50']">
            <div class="flex flex-wrap items-center gap-2 px-4 py-3">
              <span class="num h-7 w-7 shrink-0 rounded-full bg-navy-50 text-ink text-xs font-semibold flex items-center justify-center" aria-hidden="true">{{ i + 1 }}</span>
              <button type="button" class="min-w-0 flex-1 text-start kbd-focus rounded" :aria-expanded="q._open" :aria-controls="`fb-${q._key}`" @click="q._open = !q._open">
                <span class="block font-medium text-ink truncate">{{ label(q) }}</span>
                <span class="mt-0.5 flex flex-wrap gap-1.5 text-[11px]">
                  <span class="rounded-full bg-navy-50 text-muted px-2 py-0.5">{{ $t(`platform.builder.types.${q.type}`) }}</span>
                  <span v-if="q.required" class="rounded-full bg-warning/10 text-warning px-2 py-0.5">{{ $t('common.required') }}</span>
                  <span v-if="!q.active" class="rounded-full bg-border text-ink px-2 py-0.5">{{ $t('platform.builder.inactive') }}</span>
                  <span v-if="q.show_when" class="rounded-full bg-teal-soft text-teal-text px-2 py-0.5">{{ $t('platform.builder.conditional') }}</span>
                </span>
              </button>
              <div class="flex items-center gap-1">
                <AppButton size="sm" variant="ghost" icon :disabled="!canMove(i, -1)" :aria-label="$t('platform.builder.moveUp', { name: label(q) })" @click="move(i, -1)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                </AppButton>
                <AppButton size="sm" variant="ghost" icon :disabled="!canMove(i, 1)" :aria-label="$t('platform.builder.moveDown', { name: label(q) })" @click="move(i, 1)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7" /></svg>
                </AppButton>
                <AppButton size="sm" variant="ghost" icon :aria-label="$t('platform.builder.remove', { name: label(q) })" @click="remove(i)">
                  <svg class="h-4 w-4 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3" /></svg>
                </AppButton>
              </div>
            </div>
            <div v-show="q._open" :id="`fb-${q._key}`" class="border-t border-border px-4 py-4 space-y-4">
              <div class="grid sm:grid-cols-2 gap-4">
                <FormField v-model="q.label_ar" :name="`q-${q._key}-label_ar`" :label="$t('platform.builder.labelAr')" :error="errorFor(i, 'label_ar')" :maxlength="300" required />
                <FormField v-model="q.label_en" :name="`q-${q._key}-label_en`" :label="$t('platform.builder.labelEn')" :error="errorFor(i, 'label_en')" :maxlength="300" ltr />
                <FormField v-model="q.help_ar" :name="`q-${q._key}-help_ar`" :label="$t('platform.builder.helpAr')" :error="errorFor(i, 'help_ar')" :maxlength="500" />
                <FormField v-model="q.help_en" :name="`q-${q._key}-help_en`" :label="$t('platform.builder.helpEn')" :error="errorFor(i, 'help_en')" :maxlength="500" ltr />
                <FormField :model-value="q.type" :name="`q-${q._key}-type`" type="select" :label="$t('platform.builder.type')" :options="typeOpts" :error="errorFor(i, 'type')" required @update:model-value="onType(q, ($event || 'short_text') as QuestionType)" />
                <div class="flex flex-col justify-end gap-2 pb-1">
                  <FormField v-model="q.required" :name="`q-${q._key}-required`" type="checkbox" :label="$t('platform.builder.required')" />
                  <FormField v-model="q.active" :name="`q-${q._key}-active`" type="checkbox" :label="$t('platform.builder.active')" :help="$t('platform.builder.activeHelp')" />
                </div>
              </div>

              <!-- options (choice types) -->
              <fieldset v-if="isChoice(q.type)" class="rounded-lg border border-border p-3 space-y-2">
                <legend class="px-1 text-sm font-medium text-ink">{{ $t('platform.builder.options') }}</legend>
                <p v-if="errorFor(i, 'options')" class="error-text" role="alert">{{ errorFor(i, 'options') }}</p>
                <div v-for="(o, j) in q.options" :key="o.value" class="grid grid-cols-[1fr_1fr_auto] gap-2 items-start">
                  <FormField v-model="o.label_ar" :name="`q-${q._key}-o-${o.value}-ar`" :label="j === 0 ? $t('platform.builder.optionAr') : undefined" :error="errorFor(i, `options.${j}.label_ar`)" :maxlength="190" required :placeholder="$t('platform.builder.optionN', { n: j + 1 })" />
                  <FormField v-model="o.label_en" :name="`q-${q._key}-o-${o.value}-en`" :label="j === 0 ? $t('platform.builder.optionEn') : undefined" :maxlength="190" ltr />
                  <AppButton size="sm" variant="ghost" icon :class="j === 0 && 'mt-[1.625rem]'" :disabled="q.options.length <= 2" :aria-label="$t('platform.builder.removeOption', { n: j + 1 })" @click="removeOption(q, j)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                  </AppButton>
                </div>
                <AppButton size="sm" variant="outline" :disabled="q.options.length >= (schema?.limits.max_options ?? 30)" @click="addOption(q)">{{ $t('platform.builder.addOption') }}</AppButton>
              </fieldset>

              <!-- conditional display -->
              <div class="grid sm:grid-cols-2 gap-4">
                <FormField :model-value="q.show_when?.question ?? ''" :name="`q-${q._key}-when`" type="select" :label="$t('platform.builder.showWhen')" :help="drivers(i).length ? $t('platform.builder.showWhenHelp') : $t('platform.builder.showWhenNone')" :placeholder="$t('platform.builder.always')" :options="drivers(i).map((d) => ({ value: d.id, label: label(d) }))" :disabled="!drivers(i).length" :error="errorFor(i, 'show_when.question')" @update:model-value="setCondition(q, ($event as string | null) || null)" />
                <FormField v-if="q.show_when" v-model="q.show_when.equals" :name="`q-${q._key}-equals`" type="select" :label="$t('platform.builder.equals')" :options="conditionValues(driverOf(q))" :error="errorFor(i, 'show_when.equals')" required />
              </div>
            </div>
          </li>
        </ol>
        <AppButton variant="outline" :disabled="draft.length >= maxQuestions || loading" @click="addQuestion">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          {{ $t('platform.builder.add') }}
        </AppButton>

        <div class="card card-pad flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm" :class="dirty ? 'text-warning' : 'text-muted'" aria-live="polite">{{ dirty ? $t('platform.builder.unpublished') : $t('platform.builder.upToDate') }}</p>
          <div class="flex gap-2">
            <AppButton variant="ghost" :disabled="!dirty || publishing" @click="reset">{{ $t('platform.builder.reset') }}</AppButton>
            <AppButton :loading="publishing" :disabled="!dirty" @click="publish">{{ $t('platform.builder.publishVersion', { version: (schema?.version ?? 0) + 1 }) }}</AppButton>
          </div>
        </div>
      </div>

      <aside class="space-y-5 xl:sticky xl:top-24">
        <SectionCard :title="$t('platform.builder.preview')" :sub="$t('platform.builder.previewSub')">
          <DynamicQuestions v-if="previewQuestions.length" v-model="previewAnswers" :questions="previewQuestions" :framed="false" />
          <p v-else class="text-sm text-muted">{{ $t('platform.builder.empty') }}</p>
        </SectionCard>
        <SectionCard v-if="schema?.versions.length" :title="$t('platform.builder.versions')" flush>
          <ul class="divide-y divide-border text-sm">
            <li v-for="v in schema.versions" :key="v.version" class="px-5 py-3 flex items-center justify-between gap-3">
              <span>
                <span class="font-medium text-ink num">v{{ v.version }}</span>
                <span class="block text-xs text-muted num">{{ dateTime(v.published_at) }}<template v-if="v.published_by"> · {{ v.published_by }}</template></span>
              </span>
              <span class="text-xs text-muted num">{{ $t('platform.builder.questionsCount', { n: v.questions_count }) }}</span>
              <AppBadge v-if="v.is_active" kind="generic" value="active" :label="$t('platform.builder.live')" />
            </li>
          </ul>
        </SectionCard>
      </aside>
    </div>
  </div>
</template>
