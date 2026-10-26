// Requires an authenticated session. Redirects to /auth/login with `redirect` query.
// A pending 2FA challenge goes back to the login (step 2); an unverified account goes to /auth/verify
// unless the local-dev flag `verification_optional` is on (see auth store `gate()`).
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.isAuthenticated) {
    return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  }
  const gate = await auth.gate()
  if (gate && to.path !== localePath(gate.path)) return navigateTo({ path: localePath(gate.path), query: gate.query })
})
