// Requires an authenticated session. Redirects to /auth/login with `redirect` query.
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  if (!auth.isAuthenticated) {
    const localePath = useLocalePath()
    return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  }
})
