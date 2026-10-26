<script setup lang="ts">
/**
 * FRM-01 — the membership review questions on the staff membership file: answered by the reviewer
 * (PUT /admin/memberships/{id}/review-answers, validated against the active schema) and shown with their version.
 * Hidden while no review questions are published and nothing was answered.
 */
import type { AnswerMap, ExtraAnswers, FormQuestion } from '~/types/platform'
import { parseApiError } from '~/utils/apiError'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ membershipId: string | number; data: ExtraAnswers | null | undefined }>()
const emit = defineEmits<{ (e: 'saved'): void }>()
const { t } = useI18n()
const api = useApi()

const questions = ref<FormQuestion[] | null>(null)
const editing = ref(false)
const answers = ref<AnswerMap>({})
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const form = ref<{ validate: () => boolean } | null>(null)
const answered = computed(() => !!props.data?.items?.length)

const start = () => {
  answers.value = { ...(props.data?.answers ?? {}) }
  errors.value = {}
  editing.value = true
}
onMounted(async () => {
  try {
    const r = await api.get<{ data: { questions: FormQuestion[] } }>('/admin/forms/membership_review')
    questions.value = (r.data.questions ?? []).filter((q) => q.active !== false)
  } catch {
    questions.value = []
  }
  // first visit with nothing answered yet → the form opens directly
  if (questions.value.length && !answered.value) start()
})
const save = async () => {
  if (form.value && !form.value.validate()) return void toastError(t('validation.fixErrors'))
  saving.value = true
  try {
    await api.put(`/admin/memberships/${props.membershipId}/review-answers`, { extra_answers: answers.value })
    editing.value = false
    toastSuccess(t('notify.savedItem', { item: t('platform.extra.reviewTitle') }))
    emit('saved')
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
  <SectionCard v-if="questions?.length || answered" :title="$t('platform.extra.reviewTitle')" :sub="$t('platform.extra.reviewSub')">
    <template v-if="!editing && questions?.length" #actions><AppButton size="sm" variant="secondary" @click="start">{{ answered ? $t('common.edit') : $t('platform.extra.answer') }}</AppButton></template>
    <form v-if="editing" class="space-y-4" novalidate @submit.prevent="save">
      <DynamicQuestions ref="form" v-model="answers" :questions="questions" :errors="errors" error-prefix="extra_answers." :framed="false" />
      <div class="flex justify-end gap-2">
        <AppButton variant="ghost" @click="editing = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton type="submit" :loading="saving">{{ $t('common.save') }}</AppButton>
      </div>
    </form>
    <ExtraAnswersView v-else :data="data" />
  </SectionCard>
</template>
