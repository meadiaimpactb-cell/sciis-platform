<script setup lang="ts">
/** BRD-04 — portal header switch between the light and dark theme (only while FEATURE_DARK_MODE is on). */
const theme = usePortalTheme()
const toggle = () => theme.setTheme(theme.dark.value ? 'light' : 'dark')
</script>

<template>
  <button
    v-if="theme.enabled.value"
    type="button"
    class="h-10 w-10 rounded-lg border border-border text-ink/80 hover:text-navy hover:border-navy/40 hover:bg-navy-50 flex items-center justify-center transition kbd-focus"
    :aria-pressed="theme.dark.value"
    :aria-label="$t('platform.theme.darkMode')"
    :title="theme.dark.value ? $t('platform.theme.toLight') : $t('platform.theme.toDark')"
    @click="toggle"
  >
    <svg v-if="theme.dark.value" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
    <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" /></svg>
  </button>
</template>
