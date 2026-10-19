// Project-owner portal guard.
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.isAuthenticated) return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  if (!auth.isEntrepreneur) return navigateTo(localePath(auth.homePath))
})
