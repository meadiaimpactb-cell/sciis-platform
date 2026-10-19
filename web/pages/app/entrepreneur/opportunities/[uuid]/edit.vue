<script setup lang="ts">
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.form.editTitle') })
const tax = useTaxonomyStore()
await tax.load().catch(() => {})
const route = useRoute()
</script>
<template>
  <div>
    <PageHeader :title="$t('entrepreneur.form.editTitle')" :eyebrow="$t('portal.entrepreneur')"><template #actions><AppButton variant="ghost" size="sm" to="/app/entrepreneur/dashboard">{{ $t('common.back') }}</AppButton></template></PageHeader>
    <OpportunityForm :uuid="route.params.uuid as string" />
  </div>
</template>
