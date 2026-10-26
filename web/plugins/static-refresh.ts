/**
 * Static hosting build only (runtimeConfig.public.staticMode === 'true'): prerendered pages carry the data that existed
 * at build time. The build also records the API's public content version (GET /public/content-version) in the payload;
 * once the page is interactive and the main thread is idle, the browser fetches the current version and re-runs the
 * page's useAsyncData calls ONLY when it changed — so content edited in the admin panel still shows up, without a
 * second full render (and the API calls behind it) on every visit.
 * Client-side navigations already fetch fresh data because payload extraction is disabled in that build.
 */
export default defineNuxtPlugin(async (nuxtApp) => {
  if (useRuntimeConfig().public.staticMode !== 'true') return
  const api = useApi()
  const fetchVersion = () => api.get<{ data: { version: string } }>('/public/content-version').then((r) => r.data.version).catch(() => null)
  const built = useState<string | null>('content-version', () => null)

  if (import.meta.server) {
    built.value = await fetchVersion()
    return
  }

  nuxtApp.hook('app:mounted', () => {
    const run = async () => {
      const current = await fetchVersion()
      // Unknown build version → refresh to be safe; unreachable API → keep the prerendered content.
      if (current && current !== built.value) {
        built.value = current
        await refreshNuxtData().catch(() => {})
      }
    }
    const w = window as Window & { requestIdleCallback?: (cb: () => void, opts?: { timeout: number }) => number }
    if (typeof w.requestIdleCallback === 'function') w.requestIdleCallback(() => void run(), { timeout: 4000 })
    else setTimeout(() => void run(), 2000)
  })
})
