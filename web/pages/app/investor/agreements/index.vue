<script setup lang="ts">
/** AGR-04 — the connected investor's agreements (read-only; sign when sent for signature). */
definePageMeta({ layout: 'portal', middleware: ['member'] })
const { t } = useI18n()
useHead({ title: t('agreements.party.title') })
</script>

<template>
  <div>
    <PageHeader :title="$t('agreements.party.title')" :sub="$t('agreements.party.subInvestor')" />
    <AgreementPartyList portal="investor" />
  </div>
</template>
