<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useSeo({
  title: t('legal.terms.metaTitle'),
  description: t('legal.terms.metaDescription'),
  jsonLd: { '@type': 'WebPage', name: t('legal.terms.title'), description: t('legal.terms.metaDescription'), isPartOf: { '@id': `${useSiteUrl()}/#website` } },
})
</script>
<template><LegalPage kind="terms" version="0.3-draft" /></template>
