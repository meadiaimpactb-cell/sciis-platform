<script setup lang="ts">
/** AGR-04/05 — one agreement, investor side. */
definePageMeta({ layout: 'portal', middleware: ['member'] })
const route = useRoute()
</script>

<template>
  <AgreementPartyFile portal="investor" :uuid="String(route.params.uuid)" />
</template>
