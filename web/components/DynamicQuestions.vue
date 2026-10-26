<script setup lang="ts">
/**
 * FRM-01 — renders the admin-defined "additional questions" of a form (v-model = `{question_id: value}`).
 * Questions come from `questions` or are loaded by `formKey` (GET /forms/{key}, or /admin/forms/{key} for staff-only
 * forms). Inactive questions are skipped; `show_when` hides a question until an earlier one has the given answer
 * (hidden answers are dropped server-side). Types: short / long text, number, single / multiple choice, yes / no, date.
 * Server 422 messages arrive in `errors` (keys `{errorPrefix}{id}`); `validate()` mirrors the API rules client-side.
 * Renders nothing while the form has no questions.
 */
import type { AnswerMap, AnswerValue, FormKey, FormQuestion } from '~/types/platform'

const props = withDefaults(
  defineProps<{
    modelValue: AnswerMap | null | undefined
    formKey?: FormKey
    questions?: FormQuestion[] | null
    /** staff-only form (membership review) → GET /admin/forms/{key} */
    admin?: boolean
    errors?: Record<string, string | undefined>
    errorPrefix?: string
    disabled?: boolean
    title?: string
    sub?: string
    /** wrap in a bordered fieldset with a legend (default) or render the fields only */
    framed?: boolean
  }>(),
  { formKey: undefined, questions: null, admin: false, errors: () => ({}), errorPrefix: '', disabled: false, title: undefined, sub: undefined, framed: true },
)
const emit = defineEmits<{ (e: 'update:modelValue', v: AnswerMap): void; (e: 'loaded', q: FormQuestion[]): void }>()
const { t, locale } = useI18n()
const api = useApi()
const uid = useId()

const SHORT_MAX = 255
const LONG_MAX = 3000

const loaded = ref<FormQuestion[]>([])
const list = computed(() => (props.questions ?? loaded.value).filter((q) => q.active !== false))
onMounted(async () => {
  if (props.questions || !props.formKey) return
  try {
    const r = await api.get<{ data: { questions: FormQuestion[] } }>(props.admin ? `/admin/forms/${props.formKey}` : `/forms/${props.formKey}`)
    loaded.value = r.data.questions ?? []
  } catch {
    loaded.value = []
  }
  emit('loaded', list.value)
})

const answers = computed<AnswerMap>(() => props.modelValue ?? {})
const set = (id: string, v: AnswerValue) => {
  const next = { ...answers.value, [id]: v }
  if (v === null || v === '' || (Array.isArray(v) && !v.length)) delete next[id]
  emit('update:modelValue', next)
  if (localErrors.value[id]) localErrors.value = { ...localErrors.value, [id]: undefined }
}

const blank = (v: unknown) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && !v.length)
/** Same rule as FormSchemaService::matches (yes / no for booleans, "contains" for multiple choice). */
const matches = (v: AnswerValue | undefined, equals: string) => {
  if (blank(v)) return false
  if (Array.isArray(v)) return v.map(String).includes(equals)
  if (typeof v === 'boolean') return (v ? 'yes' : 'no') === equals
  return String(v) === equals
}
const visible = computed(() => {
  const out: Record<string, boolean> = {}
  for (const q of list.value) out[q.id] = !q.show_when || (!!out[q.show_when.question] && matches(answers.value[q.show_when.question], q.show_when.equals))
  return out
})
const shown = computed(() => list.value.filter((q) => visible.value[q.id]))

const en = computed(() => locale.value === 'en')
const qLabel = (q: FormQuestion) => (en.value && q.label_en) || q.label_ar
const qHelp = (q: FormQuestion) => ((en.value && q.help_en) || q.help_ar) ?? undefined
const optLabel = (o: FormQuestion['options'][number]) => (en.value && o.label_en) || o.label_ar
const options = (q: FormQuestion) => q.options.map((o) => ({ value: o.value, label: optLabel(o) }))

const localErrors = ref<Record<string, string | undefined>>({})
const errorOf = (q: FormQuestion) => localErrors.value[q.id] ?? props.errors?.[`${props.errorPrefix}${q.id}`]

/** Client-side check of the visible questions (required, lengths, numbers, dates); returns true when valid. */
const validate = () => {
  const errs: Record<string, string> = {}
  for (const q of shown.value) {
    const v = answers.value[q.id]
    if (blank(v)) {
      if (q.required) errs[q.id] = t('validation.required')
      continue
    }
    if (q.type === 'short_text' && String(v).trim().length > SHORT_MAX) errs[q.id] = t('validation.maxLength', { n: SHORT_MAX })
    if (q.type === 'long_text' && String(v).trim().length > LONG_MAX) errs[q.id] = t('validation.maxLength', { n: LONG_MAX })
    if (q.type === 'number' && Number.isNaN(Number(v))) errs[q.id] = t('validation.number')
    if (q.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(String(v))) errs[q.id] = t('validation.invalid')
  }
  localErrors.value = errs
  return !Object.keys(errs).length
}
/** Only the answers of visible questions (what the API keeps anyway). */
const visibleAnswers = (): AnswerMap => Object.fromEntries(Object.entries(answers.value).filter(([id]) => visible.value[id]))
defineExpose({ validate, visibleAnswers, questions: list })

const radioValue = (q: FormQuestion) => {
  const v = answers.value[q.id]
  if (q.type === 'yes_no') return v === true ? 'yes' : v === false ? 'no' : ''
  return v == null ? '' : String(v)
}
const onRadio = (q: FormQuestion, value: string) => set(q.id, q.type === 'yes_no' ? value === 'yes' : value)
const radioOptions = (q: FormQuestion) => (q.type === 'yes_no' ? [{ value: 'yes', label: t('common.yes') }, { value: 'no', label: t('common.no') }] : options(q))
/** Short choice lists read better as radio buttons; long ones as a select. */
const asRadios = (q: FormQuestion) => q.type === 'yes_no' || (q.type === 'single_choice' && q.options.length <= 5)
</script>

<template>
  <component :is="framed ? 'fieldset' : 'div'" v-if="list.length" class="min-w-0" :class="framed && 'rounded-xl border border-border p-4 sm:p-5 space-y-4'">
    <legend v-if="framed" class="px-1 text-sm font-semibold text-ink">{{ title ?? $t('platform.extra.title') }}</legend>
    <p v-if="framed && (sub ?? $t('platform.extra.sub'))" class="text-xs text-muted -mt-1">{{ sub ?? $t('platform.extra.sub') }}</p>
    <div class="space-y-4">
      <template v-for="q in shown" :key="q.id">
        <!-- yes / no and short choice lists: native radios (arrow keys move between options) -->
        <fieldset v-if="asRadios(q)" class="min-w-0" :aria-describedby="errorOf(q) ? `${uid}-${q.id}-err` : qHelp(q) ? `${uid}-${q.id}-help` : undefined" :aria-invalid="!!errorOf(q) || undefined">
          <legend class="label">
            {{ qLabel(q) }}
            <span v-if="q.required" class="text-danger ms-0.5" aria-hidden="true">*</span>
            <span v-else class="text-xs text-muted font-normal ms-1">({{ $t('common.optional') }})</span>
            <span v-if="q.required" class="sr-only">({{ $t('common.required') }})</span>
          </legend>
          <div class="flex flex-wrap gap-x-5 gap-y-2">
            <label v-for="o in radioOptions(q)" :key="o.value" class="inline-flex items-center gap-2 text-sm text-ink cursor-pointer" :class="disabled && 'opacity-60 cursor-not-allowed'">
              <input type="radio" class="h-4 w-4 accent-navy" :name="`${uid}-${q.id}`" :value="o.value" :checked="radioValue(q) === o.value" :disabled="disabled" @change="onRadio(q, o.value)">
              {{ o.label }}
            </label>
          </div>
          <p v-if="errorOf(q)" :id="`${uid}-${q.id}-err`" class="error-text" role="alert">{{ errorOf(q) }}</p>
          <p v-else-if="qHelp(q)" :id="`${uid}-${q.id}-help`" class="help">{{ qHelp(q) }}</p>
        </fieldset>
        <FormField
          v-else-if="q.type === 'multiple_choice'"
          :model-value="answers[q.id] ?? []" :name="`extra-${q.id}`" type="chips" :label="qLabel(q)" :help="qHelp(q)" :options="options(q)"
          :error="errorOf(q)" :required="q.required" :disabled="disabled" @update:model-value="set(q.id, $event as string[])"
        />
        <FormField
          v-else-if="q.type === 'single_choice'"
          :model-value="answers[q.id] ?? ''" :name="`extra-${q.id}`" type="select" :label="qLabel(q)" :help="qHelp(q)" :options="options(q)"
          :error="errorOf(q)" :required="q.required" :disabled="disabled" @update:model-value="set(q.id, ($event as string | null) || null)"
        />
        <FormField
          v-else
          :model-value="answers[q.id] ?? (q.type === 'number' ? null : '')" :name="`extra-${q.id}`"
          :type="q.type === 'long_text' ? 'textarea' : q.type === 'number' ? 'number' : q.type === 'date' ? 'date' : 'text'"
          :label="qLabel(q)" :help="qHelp(q)" :error="errorOf(q)" :required="q.required" :disabled="disabled"
          :maxlength="q.type === 'short_text' ? SHORT_MAX : q.type === 'long_text' ? LONG_MAX : undefined" :rows="3" :ltr="q.type === 'number' || q.type === 'date'"
          @update:model-value="set(q.id, $event as AnswerValue)"
        />
      </template>
    </div>
  </component>
</template>
