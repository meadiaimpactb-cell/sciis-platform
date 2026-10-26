<script setup lang="ts">
import type { NuxtError } from '#app'
const props = defineProps<{ error: NuxtError }>()
const code = computed(() => ([403, 404, 500].includes(props.error.statusCode ?? 500) ? (props.error.statusCode as number) : 500))
const handleBack = () => clearError({ redirect: '/' })
const { t } = useI18n()
// Error pages must never be indexed (avoids soft-404s when the static host serves the SPA shell).
useHead({ title: () => t(`errors.${code.value}.title`), meta: [{ name: 'robots', content: 'noindex, nofollow' }] })
</script>

<template>
  <div class="min-h-screen bg-bg flex items-center justify-center p-6 relative overflow-hidden">
    <img src="/brand/mark.svg" width="100" height="136" alt="" aria-hidden="true" class="w-auto absolute -end-20 -bottom-24 h-[32rem] opacity-[0.05] pointer-events-none select-none">
    <div class="card card-pad max-w-md w-full text-center relative">
      <BrandLogo variant="mark" size="md" class="mx-auto" />
      <p class="mt-5 text-6xl font-semibold num text-gradient inline-block">{{ code }}</p>
      <h1 class="text-xl font-semibold mt-3">{{ $t(`errors.${code}.title`) }}</h1>
      <p class="text-muted mt-2">{{ $t(`errors.${code}.text`) }}</p>
      <AppButton class="mt-6" @click="handleBack">{{ $t('errors.backHome') }}</AppButton>
    </div>
  </div>
</template>
