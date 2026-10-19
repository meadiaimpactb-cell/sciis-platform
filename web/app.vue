<script setup lang="ts">
const { locale, t } = useI18n()
const head = useLocaleHead({ dir: true, lang: true })
useHead({
  htmlAttrs: { lang: () => locale.value, dir: () => (locale.value === 'ar' ? 'rtl' : 'ltr') },
  link: () => head.value.link ?? [],
  meta: () => [...(head.value.meta ?? []), { name: 'description', content: t('app.tagline') }],
})
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
    <ToastHost />
  </div>
</template>
