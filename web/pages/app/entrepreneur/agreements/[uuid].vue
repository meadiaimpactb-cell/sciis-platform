<script setup lang="ts">
/** AGR-04/05 — one agreement, project owner side. */
definePageMeta({ layout: 'portal', middleware: ['entrepreneur'] })
const route = useRoute()
</script>

<template>
  <AgreementPartyFile portal="entrepreneur" :uuid="String(route.params.uuid)" />
</template>
