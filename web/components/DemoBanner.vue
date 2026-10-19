<script setup lang="ts">
const { mock } = useApiBase()
</script>

<template>
  <div v-if="mock" class="bg-warning/10 text-warning text-xs text-center py-1.5 px-4 border-b border-warning/20" role="status">
    {{ $t('app.demoBanner') }}
    <NuxtLink :to="$localePath('/prototype')" class="underline underline-offset-2 ms-2 font-medium">{{ $t('nav.prototype') }}</NuxtLink>
  </div>
</template>
