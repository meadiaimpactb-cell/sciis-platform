<script setup lang="ts">
import type { NuxtError } from '#app'
const props = defineProps<{ error: NuxtError }>()
const code = computed(() => ([403, 404, 500].includes(props.error.statusCode ?? 500) ? (props.error.statusCode as number) : 500))
const handleBack = () => clearError({ redirect: '/' })
</script>

<template>
  <div class="min-h-screen bg-bg flex items-center justify-center p-6">
    <div class="card card-pad max-w-md w-full text-center">
      <p class="text-6xl font-semibold num text-blue">{{ code }}</p>
      <h1 class="text-xl font-semibold mt-3">{{ $t(`errors.${code}.title`) }}</h1>
      <p class="text-muted mt-2">{{ $t(`errors.${code}.text`) }}</p>
      <AppButton class="mt-6" @click="handleBack">{{ $t('errors.backHome') }}</AppButton>
    </div>
  </div>
</template>
