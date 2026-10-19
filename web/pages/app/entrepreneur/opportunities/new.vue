<script setup lang="ts">
/** Creates a draft (POST /entrepreneur/opportunities) then redirects to the edit form. */
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('entrepreneur.form.newTitle') })
const api = useApi()
const router = useRouter()
const localePath = useLocalePath()
const toast = useToast()
onMounted(async () => {
  try {
    const r = await api.post<{ data: { uuid: string } }>('/entrepreneur/opportunities', {})
    router.replace(localePath(`/app/entrepreneur/opportunities/${r.data.uuid}/edit`))
  } catch (e) { toast.error((e as Error).message) }
})
</script>
<template><div><PageHeader :title="$t('entrepreneur.form.newTitle')" /><p class="text-muted">{{ $t('common.loading') }}</p></div></template>
