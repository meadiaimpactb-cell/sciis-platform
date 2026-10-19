// Only for unauthenticated visitors (login/register). Authenticated users go to their portal home.
export default defineNuxtRouteMiddleware(async () => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  if (auth.isAuthenticated) {
    const localePath = useLocalePath()
    return navigateTo(localePath(auth.homePath))
  }
})
