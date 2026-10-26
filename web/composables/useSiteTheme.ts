/**
 * Public site theme (design v3): dark by default, light on request. The choice lives on <html data-theme> and in
 * localStorage ('scii-theme'); nuxt.config applies it before the first paint. It is deliberately not managed by
 * useHead, which would put the server default back on hydration.
 */
type SiteTheme = 'dark' | 'light'
const STORAGE_KEY = 'scii-theme'

export function useSiteTheme() {
  const theme = useState<SiteTheme>('site-theme', () => 'dark')

  const sync = () => {
    theme.value = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'
  }
  const toggle = () => {
    const next: SiteTheme = theme.value === 'dark' ? 'light' : 'dark'
    document.documentElement.setAttribute('data-theme', next)
    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {
      /* storage blocked: the choice lasts for this page view */
    }
    theme.value = next
  }

  return { theme, sync, toggle }
}
