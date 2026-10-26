<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useSeo({
  title: t('legal.privacy.metaTitle'),
  description: t('legal.privacy.metaDescription'),
  jsonLd: { '@type': 'WebPage', name: t('legal.privacy.title'), description: t('legal.privacy.metaDescription'), isPartOf: { '@id': `${useSiteUrl()}/#website` } },
})
</script>
<template><LegalPage kind="privacy" version="0.3-draft" /></template>
