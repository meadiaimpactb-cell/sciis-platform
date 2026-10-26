<script setup lang="ts">
/**
 * Language switch ar ⇄ en (prefix_except_default).
 * `outlined` = the header's bordered button (falak.sa: 16 px, 32 px tall, 5 px radius); `dark` = light text for navy surfaces.
 */
withDefaults(defineProps<{ dark?: boolean; outlined?: boolean }>(), { dark: false, outlined: false })
const { locale } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const other = computed(() => (locale.value === 'ar' ? 'en' : 'ar'))
</script>

<template>
  <NuxtLink
    :to="switchLocalePath(other)"
    class="inline-flex items-center transition kbd-focus"
    :class="[
      outlined ? 'h-8 px-[0.9375rem] rounded-[5px] border text-base' : 'gap-1.5 rounded-lg px-2.5 h-9 text-sm font-medium',
      outlined
        ? (dark ? 'border-white/80 text-white hover:bg-white hover:text-navy-950' : 'border-ink text-ink hover:bg-ink hover:text-white')
        : (dark ? 'text-white/80 hover:text-white hover:bg-white/10' : 'text-ink hover:bg-bg'),
    ]"
    :aria-label="$t('components.langSwitch.label')"
    :lang="other"
    :dir="other === 'ar' ? 'rtl' : 'ltr'"
  >
    <svg v-if="!outlined" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" /></svg>
    {{ $t('components.langSwitch.switchTo') }}
  </NuxtLink>
</template>
