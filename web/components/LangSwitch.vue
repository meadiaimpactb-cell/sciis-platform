<script setup lang="ts">
/** Language switch ar ⇄ en (prefix_except_default). */
withDefaults(defineProps<{ dark?: boolean }>(), { dark: false })
const { locale } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const other = computed(() => (locale.value === 'ar' ? 'en' : 'ar'))
</script>

<template>
  <NuxtLink
    :to="switchLocalePath(other)"
    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 h-9 text-sm font-medium transition kbd-focus"
    :class="dark ? 'text-white/80 hover:text-white hover:bg-white/10' : 'text-ink hover:bg-bg'"
    :aria-label="$t('components.langSwitch.label')"
    :lang="other"
    :dir="other === 'ar' ? 'rtl' : 'ltr'"
  >
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" /></svg>
    {{ $t('components.langSwitch.switchTo') }}
  </NuxtLink>
</template>
