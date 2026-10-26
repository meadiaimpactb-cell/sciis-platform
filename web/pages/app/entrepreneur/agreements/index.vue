<script setup lang="ts">
/** AGR-04 — the project owner's agreements with connected investors (read-only; sign when sent for signature). */
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const { t } = useI18n()
useHead({ title: t('agreements.party.title') })
</script>

<template>
  <div>
    <PageHeader :title="$t('agreements.party.title')" :sub="$t('agreements.party.subOwner')" />
    <AgreementPartyList portal="entrepreneur" />
  </div>
</template>
