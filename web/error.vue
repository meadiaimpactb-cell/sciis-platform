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
  <div class="surface-hero min-h-screen flex items-center justify-center p-6">
    <OrbitBackdrop />
    <BrandMark :width="376" :framed="false" class="absolute -end-20 -bottom-24 opacity-[0.06] pointer-events-none select-none" />
    <GlassCard pad="lg" class="max-w-md w-full text-center flex flex-col items-center">
      <BrandMark :width="40" />
      <p class="display mt-6 num text-gradient inline-block">{{ code }}</p>
      <h1 class="card-title mt-3">{{ $t(`errors.${code}.title`) }}</h1>
      <p class="text-base text-t2 mt-2">{{ $t(`errors.${code}.text`) }}</p>
      <AppButton class="mt-8" size="lg" @click="handleBack">{{ $t('errors.backHome') }}</AppButton>
    </GlassCard>
  </div>
</template>
