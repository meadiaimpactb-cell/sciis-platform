// Investor portal guard (approved membership NOT required here — dashboard shows the gate).
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.isAuthenticated) return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  if (!auth.isInvestor) return navigateTo(localePath(auth.homePath))
})
