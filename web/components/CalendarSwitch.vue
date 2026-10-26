<script setup lang="ts">
/**
 * UX-05 — public-site calendar switch (Gregorian / Hijri Umm al-Qura / both) in the footer. Saved in this browser;
 * a signed-in user's account preference is updated too, so the portal and the site agree.
 */
import type { CalendarPref, DisplayPreferences } from '~/types/platform'
import { storeCalendarChoice } from '~/utils/displayPrefs'

// `site` follows the public theme (design v3 footer); `dark` is for fixed navy surfaces.
withDefaults(defineProps<{ dark?: boolean; site?: boolean }>(), { dark: false, site: false })
const mode = useCalendarMode()
const auth = useAuthStore()
const id = `cal-${useId()}`

const choose = async (v: CalendarPref) => {
  mode.value = v
  storeCalendarChoice(v)
  if (!auth.me) return
  try {
    const r = await useApi().patch<{ data: { preferences: DisplayPreferences } }>('/me/preferences', { calendar: v })
    if (auth.me) auth.me.preferences = r.data.preferences
  } catch {
    /* kept for this browser */
  }
}
</script>

<template>
  <div class="inline-flex items-center gap-2 text-sm">
    <label :for="id" :class="site ? 'sr-only' : dark ? 'text-white/80' : 'text-muted'">{{ $t('platform.calendar.label') }}</label>
    <select
      :id="id"
      :value="mode"
      class="h-9 rounded-md border px-2 text-sm kbd-focus"
      :class="site ? 'site-select' : dark ? 'border-white/40 bg-navy-950 text-white' : 'border-[rgb(var(--c-input-border))] bg-surface text-ink'"
      @change="choose(($event.target as HTMLSelectElement).value as CalendarPref)"
    >
      <option value="gregorian">{{ $t('platform.calendar.gregorian') }}</option>
      <option value="hijri">{{ $t('platform.calendar.hijri') }}</option>
      <option value="both">{{ $t('platform.calendar.both') }}</option>
    </select>
  </div>
</template>
