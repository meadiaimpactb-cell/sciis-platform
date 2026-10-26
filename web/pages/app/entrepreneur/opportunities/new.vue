<script setup lang="ts">
/**
 * New project — a short form (name, sector, stage, one-line description). The draft is created ONLY when the owner
 * submits it (POST /entrepreneur/opportunities); the full file is then completed on the edit page. Visiting this page
 * never creates anything.
 */
import { z } from 'zod'
import { PROJECT_STAGES } from '~/types/enums'
import { RULES, requiredChoice, requiredString } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'

definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.form.newTitle') })
const api = useApi()
const router = useRouter()
const localePath = useLocalePath()
const tax = useTaxonomyStore()
const { term, label } = useEnumLabel()
onMounted(() => { void tax.load().catch(() => {}) })

const L = 'entrepreneur.form'
const form = useZodForm(
  z.object({
    name: requiredString(`${L}.basics.name`, RULES.titleMin, RULES.titleMax),
    sector_id: requiredChoice(`${L}.basics.sector`),
    stage: requiredChoice(`${L}.basics.stage`),
    short_description: z.string().trim().max(RULES.shortDescMax),
  }),
  { name: '', sector_id: '', stage: '', short_description: '' },
)
const sectorOpts = computed(() => tax.list('sectors').map((x) => ({ value: String(x.id), label: term(x) })))
const stageOpts = PROJECT_STAGES.map((s) => ({ value: s, label: label('stage', s) }))

const create = () => form.submit(async (v) => {
  const r = await api.post<{ data: { uuid: string } }>('/entrepreneur/opportunities', {
    name: v.name.trim(),
    sector_id: Number(v.sector_id),
    stage: v.stage,
    short_description: v.short_description.trim() || null,
  })
  toastSuccess(t('notify.created', { item: t('notify.entities.project') }))
  await router.replace(localePath(`/app/entrepreneur/opportunities/${r.data.uuid}/edit`))
})
</script>

<template>
  <div class="max-w-3xl">
    <PageHeader :title="$t('entrepreneur.form.newTitle')" :sub="$t('entrepreneur.form.newSub')" />
    <SectionCard>
      <form class="space-y-5" novalidate @submit.prevent="create">
        <FormField v-model="form.values.name" name="name" :label="$t('entrepreneur.form.basics.name')" :error="form.errors.value.name" :maxlength="RULES.titleMax" required />
        <div class="grid gap-5 sm:grid-cols-2">
          <FormField v-model="form.values.sector_id" name="sector_id" type="select" :label="$t('entrepreneur.form.basics.sector')" :options="sectorOpts" :error="form.errors.value.sector_id" required />
          <FormField v-model="form.values.stage" name="stage" type="select" :label="$t('entrepreneur.form.basics.stage')" :options="stageOpts" :error="form.errors.value.stage" required />
        </div>
        <FormField v-model="form.values.short_description" name="short_description" type="textarea" :rows="3" :label="$t('entrepreneur.form.basics.short')" :help="$t('entrepreneur.form.basics.shortHelp')" :error="form.errors.value.short_description" :maxlength="RULES.shortDescMax" />
        <FormAlert />
        <div class="flex flex-wrap items-center gap-3 pt-1">
          <AppButton type="submit" :loading="form.submitting.value">{{ $t('entrepreneur.form.createDraft') }}</AppButton>
          <AppButton to="/app/entrepreneur/dashboard" variant="ghost">{{ $t('common.cancel') }}</AppButton>
        </div>
      </form>
    </SectionCard>
  </div>
</template>
