<script setup lang="ts">
/**
 * FRM-01 — "additional questions" section of an editable record (investor profile): the admin-defined questions of
 * `formKey`, answered here and saved with `{extra_answers}` to `endpoint` (validated server-side against the active
 * schema version). Hidden while the form has no published questions and nothing was answered.
 */
import type { AnswerMap, ExtraAnswers, FormKey, FormQuestion } from '~/types/platform'
import { parseApiError } from '~/utils/apiError'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = withDefaults(defineProps<{ formKey: FormKey; data: ExtraAnswers | null | undefined; endpoint: string; method?: 'put' | 'patch'; title?: string }>(), { method: 'put', title: undefined })
const emit = defineEmits<{ (e: 'saved', body: unknown): void }>()
const { t } = useI18n()
const api = useApi()

const questions = ref<FormQuestion[] | null>(null)
onMounted(async () => {
  try {
    questions.value = ((await api.get<{ data: { questions: FormQuestion[] } }>(`/forms/${props.formKey}`)).data.questions ?? []).filter((q) => q.active !== false)
  } catch {
    questions.value = []
  }
})
const answered = computed(() => !!props.data?.items?.length)
/** Required questions without an answer (conditions ignored: a hint only, the API has the final word). */
const missingRequired = computed(() => (questions.value ?? []).filter((q) => q.required && !q.show_when && !(q.id in (props.data?.answers ?? {}))).length)

const editing = ref(false)
const answers = ref<AnswerMap>({})
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const form = ref<{ validate: () => boolean } | null>(null)
const start = () => {
  answers.value = { ...(props.data?.answers ?? {}) }
  errors.value = {}
  editing.value = true
}
const save = async () => {
  if (form.value && !form.value.validate()) return void toastError(t('validation.fixErrors'))
  saving.value = true
  try {
    const r = await api[props.method]<{ data: unknown }>(props.endpoint, { extra_answers: answers.value })
    editing.value = false
    toastSuccess(t('notify.updated', { item: props.title ?? t('platform.extra.title') }))
    emit('saved', r.data)
  } catch (e) {
    const p = parseApiError(e)
    if (p.status === 422 && Object.keys(p.errors).length) {
      errors.value = p.errors
      toastError(t('validation.fixErrors'))
    } else toastApiError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SectionCard v-if="questions?.length || answered" :title="title ?? $t('platform.extra.title')" :sub="$t('platform.extra.sub')">
    <template v-if="!editing && questions?.length" #actions><AppButton size="sm" variant="secondary" @click="start">{{ answered ? $t('common.edit') : $t('platform.extra.answer') }}</AppButton></template>
    <form v-if="editing" class="space-y-4" novalidate @submit.prevent="save">
      <DynamicQuestions ref="form" v-model="answers" :questions="questions" :errors="errors" error-prefix="extra_answers." :framed="false" />
      <div class="flex justify-end gap-2">
        <AppButton variant="ghost" @click="editing = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" :loading="saving">{{ $t('common.save') }}</AppButton>
      </div>
    </form>
    <template v-else>
      <InlineAlert v-if="missingRequired" tone="warning" class="mb-4">{{ $t('platform.extra.missing', { n: missingRequired }) }}</InlineAlert>
      <ExtraAnswersView :data="data" />
    </template>
  </SectionCard>
</template>
