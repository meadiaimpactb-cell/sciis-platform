// Only for unauthenticated visitors (login/register). Authenticated users go to their portal home —
// except while a 2FA challenge is pending (login step 2 lives on the login page) or the account still
// needs OTP verification (→ /auth/verify).
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  // Static build: auth-init already decided (no signed-in hint → guest, without a 401 round-trip).
  if (!auth.loaded) await auth.fetchMe()
  if (auth.isAuthenticated) {
    const localePath = useLocalePath()
    const gate = await auth.gate()
    if (gate) {
      if (to.path === localePath(gate.path)) return
      return navigateTo({ path: localePath(gate.path), query: gate.query })
    }
    return navigateTo(localePath(auth.homePath))
  }
})
