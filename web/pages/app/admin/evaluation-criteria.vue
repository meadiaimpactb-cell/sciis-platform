<script setup lang="ts">
/** FRM-03 criteria — `weight` arrives as a float from the API (decimal column); `Number()` guards older payloads (#28). */
import { z } from 'zod'
import type { EvaluationCriterion } from '~/types/api'
import { EVALUATION_AXES } from '~/types/enums'
import { criterionKeyField, optionalText, requiredChoice, requiredNumber, requiredString, RULES } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['evaluator', 'reviewer'] })
const { t } = useI18n()
useHead({ title: t('admin.criteria.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const { label } = useEnumLabel()
const { number } = useFormat()
const { data, refresh } = useAsyncResource(() => api.get<{ data: EvaluationCriterion[]; version?: number }>('/admin/evaluation-criteria').then((r) => ({ data: (r.data ?? []).map((c) => ({ ...c, weight: Number(c.weight) })), version: r.version ?? Math.max(1, ...(r.data ?? []).map((c) => c.version ?? 1)) })))

// EvaluationCriterionRequest: key ^[a-z0-9_]+$ ≤60, label_ar ≤190, weight 0.1..10, exactly 5 scale descriptions ≤500.
const LEVELS = Array.from({ length: RULES.scaleLevels }, (_, i) => String(i + 1)) as ('1' | '2' | '3' | '4' | '5')[]
const scaleSchema = z.object(Object.fromEntries(LEVELS.map((n) => [n, requiredString('admin.criteria.scale', 1, RULES.shortTextMax)])) as Record<'1' | '2' | '3' | '4' | '5', ReturnType<typeof requiredString>>)
const schema = z.object({
  axis: requiredChoice('admin.dashboard.axis'),
  key: criterionKeyField,
  label_ar: requiredString('admin.settings.labelAr', 1, RULES.titleMax),
  label_en: optionalText(RULES.titleMax),
  weight: requiredNumber('admin.criteria.weight', { min: RULES.weightMin, max: RULES.weightMax }),
  scale_descriptions: scaleSchema,
  is_active: z.boolean(),
})
type CriterionForm = z.output<typeof schema>
const blank = (): CriterionForm => ({ axis: 'readiness', key: '', label_ar: '', label_en: '', weight: RULES.weightMin, scale_descriptions: { '1': '', '2': '', '3': '', '4': '', '5': '' }, is_active: true })
const form = useZodForm(schema, blank())
const editing = ref<EvaluationCriterion | null>(null)
const show = ref(false)
const open = (c?: EvaluationCriterion) => {
  editing.value = c ?? null
  form.reset(c
    ? { axis: c.axis, key: c.key, label_ar: c.label_ar ?? '', label_en: c.label_en ?? '', weight: Number(c.weight), scale_descriptions: { ...blank().scale_descriptions, ...(c.scale_descriptions ?? {}) }, is_active: c.is_active ?? true }
    : blank())
  show.value = true
}
const save = () => form.submit(async (v) => {
  const body = { axis: v.axis, key: v.key.trim(), label_ar: v.label_ar.trim(), label_en: v.label_en?.trim() || null, weight: Number(v.weight), scale_descriptions: v.scale_descriptions, is_active: v.is_active ?? true }
  if (editing.value) await api.patch(`/admin/evaluation-criteria/${editing.value.id}`, body)
  else await api.post('/admin/evaluation-criteria', body)
  show.value = false
  toastSuccess(t(editing.value ? 'notify.updated' : 'notify.created', { item: t('notify.entities.criterion') }))
  await refresh()
})
const totalWeight = computed(() => (data.value?.data ?? []).filter((c) => c.is_active).reduce((a, b) => a + Number(b.weight), 0))
const canManage = computed(() => auth.canAny('settings.manage'))
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.criteria.title')" :sub="$t('admin.criteria.sub')"><template #actions><span class="inline-flex h-10 items-center rounded-full border border-ink/15 px-4 text-sm text-muted num">{{ $t('admin.criteria.version', { v: data?.version ?? 1 }) }} · Σ {{ number(totalWeight, { maximumFractionDigits: 2 }) }}</span><AppButton v-if="canManage" @click="open()">{{ $t('admin.criteria.addCriterion') }}</AppButton></template></PageHeader>
    <div class="space-y-5">
      <SectionCard v-for="ax in EVALUATION_AXES" :key="ax" :title="label('evaluationAxis', ax)" flush>
        <div class="overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('admin.settings.termKey') }}</th><th>{{ $t('admin.settings.labelAr') }}</th><th>{{ $t('admin.criteria.weight') }}</th><th>{{ $t('admin.criteria.scale') }}</th><th></th></tr></thead>
          <tbody><tr v-for="c in (data?.data ?? []).filter((x) => x.axis === ax)" :key="c.id" :class="!c.is_active && 'opacity-50'"><td class="num text-xs ltr text-start">{{ c.key }}</td><td class="font-medium">{{ c.label_ar }}<span class="block text-xs text-muted ltr text-start">{{ c.label_en }}</span></td><td class="num">{{ number(Number(c.weight), { maximumFractionDigits: 2 }) }}</td><td class="text-xs text-muted"><ol class="space-y-0.5"><li v-for="n in 5" :key="n"><span class="num font-medium text-ink">{{ n }}</span> — {{ c.scale_descriptions?.[String(n) as '1'] }}</li></ol></td><td class="text-end"><AppButton v-if="canManage" size="sm" variant="outline" @click="open(c)">{{ $t('common.edit') }}</AppButton></td></tr></tbody></table></div>
      </SectionCard>
    </div>
    <Modal :open="show" :title="editing ? $t('common.edit') : $t('admin.criteria.addCriterion')" size="lg" @close="show = false">
      <form class="space-y-3" novalidate @submit.prevent="save">
        <div class="grid sm:grid-cols-3 gap-3">
          <FormField v-model="form.values.axis" name="axis" type="select" :label="$t('admin.dashboard.axis')" :options="EVALUATION_AXES.map((a) => ({ value: a, label: label('evaluationAxis', a) }))" :error="form.errors.value.axis" required />
          <FormField v-model="form.values.key" name="key" :label="$t('admin.settings.termKey')" :error="form.errors.value.key" :maxlength="RULES.criterionKeyMax" required ltr />
          <FormField v-model="form.values.weight" name="weight" type="number" :label="$t('admin.criteria.weight')" :error="form.errors.value.weight" :step="0.1" :min="RULES.weightMin" :max="RULES.weightMax" required />
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="form.values.label_ar" name="label_ar" :label="$t('admin.settings.labelAr')" :error="form.errors.value.label_ar" :maxlength="RULES.titleMax" required />
          <FormField v-model="form.values.label_en" name="label_en" :label="$t('admin.settings.labelEn')" :error="form.errors.value.label_en" :maxlength="RULES.titleMax" ltr />
        </div>
        <fieldset class="space-y-2">
          <legend class="label">{{ $t('admin.criteria.scale') }} <span class="text-danger ms-0.5" aria-hidden="true">*</span></legend>
          <div v-for="n in LEVELS" :key="n">
            <div class="flex items-center gap-2">
              <span class="num h-7 w-7 shrink-0 rounded-full border border-accent/40 bg-accent/10 text-accent-text text-xs font-bold flex items-center justify-center">{{ n }}</span>
              <input v-model="form.values.scale_descriptions[n]" class="input" :class="form.errors.value[`scale_descriptions.${n}`] && 'input-error'" :maxlength="RULES.shortTextMax" :aria-label="`${$t('admin.criteria.scale')} ${n}`" :aria-invalid="!!form.errors.value[`scale_descriptions.${n}`]" @blur="form.validateField(`scale_descriptions.${n}`)">
            </div>
            <p v-if="form.errors.value[`scale_descriptions.${n}`]" class="error-text ps-9" role="alert">{{ form.errors.value[`scale_descriptions.${n}`] }}</p>
          </div>
        </fieldset>
      </form>
      <template #footer><FormAlert :message="form.formError.value" class="basis-full" /><AppButton variant="ghost" @click="show = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="form.submitting.value" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>
