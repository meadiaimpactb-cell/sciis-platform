// Bootstraps the session once per request (SSR, cookie forwarded) and hydrates the client from the payload,
// so the header/portal state is identical on both sides (no hydration mismatch).
export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()
  if (auth.loaded) return
  // Skip the /me round-trip for anonymous first-time visitors (no cookies at all) to avoid a noisy 401.
  const hasCookie = import.meta.server ? !!useRequestHeaders(['cookie']).cookie : typeof document !== 'undefined' && document.cookie.length > 0
  if (!hasCookie) {
    auth.loaded = true
    return
  }
  await auth.fetchMe()
})
