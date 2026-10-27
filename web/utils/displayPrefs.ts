/**
 * UX-05 — this browser's calendar choice (localStorage). Visitors use it on the public site; signed-in users carry
 * the same value on their account (PATCH /me/preferences), which wins when known. The theme has its own store
 * (composables/useSiteTheme.ts). Kept tiny: imported by a global plugin.
 */
import type { CalendarPref } from '~/types/platform'

const CALENDAR_KEY = 'sciis.calendar'

const readKey = (key: string): string | null => {
  if (!import.meta.client) return null
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}
const writeKey = (key: string, value: string) => {
  if (!import.meta.client) return
  try {
    localStorage.setItem(key, value)
  } catch {
    /* storage blocked (private mode) — the choice lasts for this page view only */
  }
}

export const readStoredCalendar = (): CalendarPref | null => {
  const v = readKey(CALENDAR_KEY)
  return v === 'gregorian' || v === 'hijri' || v === 'both' ? v : null
}
export const storeCalendarChoice = (v: CalendarPref) => writeKey(CALENDAR_KEY, v)
