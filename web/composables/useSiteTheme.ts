/**
 * Theme (design v3) for the public site and the portals: dark by default, light on request, or following the device.
 * The resolved theme lives on <html data-theme>, the choice in localStorage ('scii-theme'); nuxt.config applies it
 * before the first paint. Deliberately not managed by useHead, which would put the server default back on hydration.
 * Signed-in users keep the choice on their account too (PATCH /me/preferences), so it follows them across devices.
 */
import type { DisplayPreferences, ThemePref } from '~/types/platform'

type SiteTheme = 'dark' | 'light'
const STORAGE_KEY = 'scii-theme'

const resolve = (p: ThemePref): SiteTheme => (p === 'system' ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : p)

export function useSiteTheme() {
  const theme = useState<SiteTheme>('site-theme', () => 'dark')
  const pref = useState<ThemePref>('site-theme-pref', () => 'dark')
  const auth = useAuthStore()
  const api = useApi()

  const sync = () => {
    theme.value = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'
    try {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved === 'system' || saved === 'light' || saved === 'dark') pref.value = saved
    } catch {
      /* storage blocked */
    }
  }

  /** Applies a choice to this page and browser only. */
  const apply = (p: ThemePref) => {
    pref.value = p
    theme.value = resolve(p)
    document.documentElement.setAttribute('data-theme', theme.value)
    try {
      localStorage.setItem(STORAGE_KEY, p)
    } catch {
      /* storage blocked: the choice lasts for this page view */
    }
  }

  /** The visitor's choice — also saved on the account when signed in. */
  const set = async (p: ThemePref) => {
    apply(p)
    const me = auth.me
    if (!me || me.preferences?.theme === p) return
    me.preferences = { calendar: me.preferences?.calendar ?? 'gregorian', theme: p }
    try {
      const r = await api.patch<{ data: { preferences: DisplayPreferences } }>('/me/preferences', { theme: p })
      if (auth.me) auth.me.preferences = r.data.preferences
    } catch {
      /* kept for this browser; the account keeps its previous value */
    }
  }

  const toggle = () => set(theme.value === 'dark' ? 'light' : 'dark')

  return { theme, pref, sync, apply, set, toggle }
}
