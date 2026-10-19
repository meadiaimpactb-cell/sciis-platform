// Staff roles only (SPEC §1.2). Optional `to.meta.roles` narrows to specific roles; super_admin always passes.
import type { StaffRole } from '~/types/enums'

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchMe()
  const localePath = useLocalePath()
  if (!auth.isAuthenticated) return navigateTo({ path: localePath('/auth/login'), query: { redirect: to.fullPath } })
  if (!auth.isStaff) return navigateTo(localePath(auth.homePath))
  const required = (to.meta.roles as StaffRole[] | undefined) ?? []
  if (required.length && !auth.hasRole(...required)) {
    return navigateTo({ path: localePath('/app/admin/dashboard'), query: { gate: 'role' } })
  }
})
