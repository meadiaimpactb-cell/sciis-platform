<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useSeo({
  title: t('legal.disclaimer.metaTitle'),
  description: t('legal.disclaimer.metaDescription'),
  jsonLd: { '@type': 'WebPage', name: t('legal.disclaimer.title'), description: t('legal.disclaimer.metaDescription'), isPartOf: { '@id': `${useSiteUrl()}/#website` } },
})
</script>
<template><LegalPage kind="disclaimer" version="0.3-draft" /></template>
