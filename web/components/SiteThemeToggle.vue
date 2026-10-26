<script setup lang="ts">
/** Public dark / light switch (design v3). Both icons are rendered; CSS shows the sun in dark and the moon in light. */
const { theme, sync, toggle } = useSiteTheme()
onMounted(sync)
</script>

<template>
  <button type="button" class="site-icon-btn kbd-focus" :aria-pressed="theme === 'light'" :aria-label="$t('landing.themeToggle')" :title="$t('landing.themeToggle')" @click="toggle">
    <svg class="on-dark" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2" />
      <path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
    <svg class="on-light" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
    </svg>
  </button>
</template>
