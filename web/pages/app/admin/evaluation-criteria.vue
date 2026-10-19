<script setup lang="ts">
import type { EvaluationCriterion } from '~/types/api'
import { EVALUATION_AXES } from '~/types/enums'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: ['evaluator', 'reviewer'] })
const { t } = useI18n()
useHead({ title: t('admin.criteria.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { label } = useEnumLabel()
const { number } = useFormat()
const { data, refresh } = useAsyncResource(() => api.get<{ data: EvaluationCriterion[]; version: number }>('/admin/evaluation-criteria'))
const editing = ref<EvaluationCriterion | null>(null)
const form = reactive<Partial<EvaluationCriterion>>({})
const open = (c?: EvaluationCriterion) => { editing.value = c ?? null; Object.assign(form, c ? JSON.parse(JSON.stringify(c)) : { axis: 'readiness', key: '', label_ar: '', label_en: '', weight: 0.1, scale_descriptions: { '1': '', '2': '', '3': '', '4': '', '5': '' } }); show.value = true }
const show = ref(false); const busy = ref(false)
const save = async () => { busy.value = true; try { if (editing.value) await api.patch(`/admin/evaluation-criteria/${editing.value.id}`, form); else await api.post('/admin/evaluation-criteria', form); show.value = false; toast.success(t('common.saved')); await refresh() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false } }
const totalWeight = computed(() => (data.value?.data ?? []).filter((c) => c.is_active).reduce((a, b) => a + b.weight, 0))
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.criteria.title')" :sub="$t('admin.criteria.sub')"><template #actions><span class="text-sm text-muted">{{ $t('admin.criteria.version', { v: data?.version ?? 1 }) }} · Σ {{ number(totalWeight, { maximumFractionDigits: 2 }) }}</span><AppButton v-if="auth.hasRole()" @click="open()">{{ $t('admin.criteria.addCriterion') }}</AppButton></template></PageHeader>
    <div class="space-y-5">
      <SectionCard v-for="ax in EVALUATION_AXES" :key="ax" :title="label('evaluationAxis', ax)" flush>
        <table class="table-base"><thead><tr><th>{{ $t('admin.settings.termKey') }}</th><th>{{ $t('admin.settings.labelAr') }}</th><th>{{ $t('admin.criteria.weight') }}</th><th>{{ $t('admin.criteria.scale') }}</th><th></th></tr></thead>
          <tbody><tr v-for="c in (data?.data ?? []).filter((x) => x.axis === ax)" :key="c.id"><td class="num text-xs ltr text-start">{{ c.key }}</td><td class="font-medium">{{ c.label_ar }}<span class="block text-xs text-muted ltr text-start">{{ c.label_en }}</span></td><td class="num">{{ c.weight }}</td><td class="text-xs text-muted"><ol class="space-y-0.5"><li v-for="n in 5" :key="n"><span class="num font-medium text-ink">{{ n }}</span> — {{ c.scale_descriptions[String(n) as '1'] }}</li></ol></td><td class="text-end"><AppButton v-if="auth.hasRole()" size="sm" variant="outline" @click="open(c)">{{ $t('common.edit') }}</AppButton></td></tr></tbody></table>
      </SectionCard>
    </div>
    <Modal :open="show" :title="editing ? $t('common.edit') : $t('admin.criteria.addCriterion')" size="lg" @close="show = false">
      <div class="space-y-3">
        <div class="grid sm:grid-cols-3 gap-3"><FormField v-model="form.axis" name="axis" type="select" :label="$t('admin.dashboard.axis')" :options="EVALUATION_AXES.map((a) => ({ value: a, label: label('evaluationAxis', a) }))" /><FormField v-model="form.key" name="key" :label="$t('admin.settings.termKey')" ltr /><FormField v-model="form.weight" name="weight" type="number" :label="$t('admin.criteria.weight')" :step="0.01" :min="0" :max="1" /></div>
        <div class="grid sm:grid-cols-2 gap-3"><FormField v-model="form.label_ar" name="label_ar" :label="$t('admin.settings.labelAr')" required /><FormField v-model="form.label_en" name="label_en" :label="$t('admin.settings.labelEn')" ltr /></div>
        <div v-if="form.scale_descriptions" class="space-y-2"><p class="label">{{ $t('admin.criteria.scale') }}</p><div v-for="n in 5" :key="n" class="flex items-center gap-2"><span class="num w-6 text-center font-medium">{{ n }}</span><input v-model="form.scale_descriptions[String(n) as '1']" class="input" :aria-label="`${n}`" /></div></div>
      </div>
      <template #footer><AppButton variant="ghost" @click="show = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>
