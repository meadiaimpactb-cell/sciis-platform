/**
 * UX-05 / BRD-04 — this browser's display choices (localStorage). Visitors use them on the public site; signed-in
 * users carry the same values on their account (PATCH /me/preferences), which wins when known.
 * Kept tiny: imported by a global plugin.
 */
import type { CalendarPref, ThemePref } from '~/types/platform'

const CALENDAR_KEY = 'sciis.calendar'
const THEME_KEY = 'sciis.theme'

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

export const readStoredTheme = (): ThemePref | null => {
  const v = readKey(THEME_KEY)
  return v === 'system' || v === 'light' || v === 'dark' ? v : null
}
export const storeThemeChoice = (v: ThemePref) => writeKey(THEME_KEY, v)
