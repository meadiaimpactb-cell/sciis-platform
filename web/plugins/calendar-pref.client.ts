/**
 * UX-05 — applies the calendar preference once the app is hydrated (prerendered pages are always Gregorian, so the
 * switch happens after hydration, never as a mismatch): a signed-in user's account preference, else this browser's.
 */
import { readStoredCalendar } from '~/utils/displayPrefs'

export default defineNuxtPlugin((nuxtApp) => {
  const mode = useCalendarMode()
  const auth = useAuthStore()
  nuxtApp.hook('app:mounted', () => {
    const local = readStoredCalendar()
    if (local) mode.value = local
    watch(() => auth.me?.preferences?.calendar, (c) => { if (c) mode.value = c }, { immediate: true })
  })
})
