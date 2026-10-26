/**
 * BRD-04 — portal theme (behind FEATURE_DARK_MODE, off by default; public pages always stay light).
 * Preference: the account's `preferences.theme` when signed in, else this browser's choice; `system` follows
 * prefers-color-scheme. layouts/portal.vue puts `theme-dark` on <html> while `dark` is true.
 */
import type { DisplayPreferences, ThemePref } from '~/types/platform'
import { readStoredTheme, storeThemeChoice } from '~/utils/displayPrefs'

export const usePortalTheme = () => {
  const auth = useAuthStore()
  const tax = useTaxonomyStore()
  const local = useState<ThemePref | null>('theme-local', () => null)
  if (import.meta.client && local.value === null) local.value = readStoredTheme() ?? 'system'

  const enabled = computed(() => !!tax.settings?.features?.dark_mode)
  const pref = computed<ThemePref>(() => auth.me?.preferences?.theme ?? local.value ?? 'system')
  const systemDark = usePreferredDark()
  const dark = computed(() => enabled.value && (pref.value === 'dark' || (pref.value === 'system' && systemDark.value)))

  /** Saved in this browser and, when signed in, on the account (PATCH /me/preferences). */
  const setTheme = async (v: ThemePref) => {
    local.value = v
    storeThemeChoice(v)
    const me = auth.me
    if (!me) return
    me.preferences = { calendar: me.preferences?.calendar ?? 'gregorian', theme: v }
    try {
      const r = await useApi().patch<{ data: { preferences: DisplayPreferences } }>('/me/preferences', { theme: v })
      if (auth.me) auth.me.preferences = r.data.preferences
    } catch {
      /* kept for this browser; the account keeps its previous value */
    }
  }

  return { enabled, pref, dark, setTheme }
}
