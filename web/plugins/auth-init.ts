// Bootstraps the session once per request (SSR, cookie forwarded) and hydrates the client from the payload,
// so the header/portal state is identical on both sides (no hydration mismatch).
// Static hosting build: pages are prerendered once for everyone (their payload says "no session") or served as the
// SPA shell. The browser re-checks /me itself — without blocking hydration (the session-dependent header is
// ClientOnly) — but only when this browser signed in before (`signedInHint`): Laravel sets cookies for anonymous
// visitors too, so cookie presence would make every guest page view end in a 401. Portal routes (/app) are left to
// their route middleware, which always asks.
import { signedInHint } from '~/stores/auth'

export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()
  const staticBuild = useRuntimeConfig().public.staticMode === 'true'

  if (import.meta.client && staticBuild) {
    if (signedInHint.get()) {
      auth.loaded = false
      void auth.fetchMe()
      return
    }
    const portal = /^\/(en\/)?app(\/|$)/.test(window.location.pathname)
    auth.loaded = !portal
    return
  }

  if (auth.loaded) return
  // Skip the /me round-trip for anonymous first-time visitors (no cookies at all) to avoid a noisy 401.
  const hasCookie = import.meta.server ? !!useRequestHeaders(['cookie']).cookie : typeof document !== 'undefined' && document.cookie.length > 0
  if (!hasCookie) {
    auth.loaded = true
    return
  }
  await auth.fetchMe()
})
