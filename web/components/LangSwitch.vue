<script setup lang="ts">
/**
 * Language switch ar ⇄ en (prefix_except_default), on the theme tokens.
 * `pill` = the outlined pill of the headers and the footer (design v3); otherwise a compact globe + label button.
 */
withDefaults(defineProps<{ pill?: boolean }>(), { pill: false })
const { locale } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const other = computed(() => (locale.value === 'ar' ? 'en' : 'ar'))
</script>

<template>
  <NuxtLink
    :to="switchLocalePath(other)"
    class="kbd-focus"
    :class="pill ? 'site-btn site-btn--outline' : 'inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-ink transition hover:bg-ink/10'"
    :aria-label="$t('components.langSwitch.label')"
    :lang="other"
    :dir="other === 'ar' ? 'rtl' : 'ltr'"
  >
    <svg v-if="!pill" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" /></svg>
    {{ $t('components.langSwitch.switchTo') }}
  </NuxtLink>
</template>
