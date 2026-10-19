<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useHead({ title: t('legal.privacy.metaTitle') })
</script>
<template><LegalPage kind="privacy" version="0.3-draft" /></template>
