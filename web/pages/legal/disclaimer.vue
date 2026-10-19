<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useHead({ title: t('legal.disclaimer.metaTitle') })
</script>
<template><LegalPage kind="disclaimer" version="0.3-draft" /></template>
