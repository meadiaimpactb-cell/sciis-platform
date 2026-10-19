// Approved membership required (INV-02 / doc §6.6) — used by /app/opportunities.
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.isAuthenticated) return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  if (!auth.isInvestor) return navigateTo(localePath(auth.homePath))
  if (!auth.isApprovedMember) return navigateTo({ path: localePath('/app/investor/dashboard'), query: { gate: 'membership' } })
})
