/**
 * Portal notification bell (NTF-01): unread count polled every 60s while the tab is visible, the latest items loaded
 * when the dropdown opens, and "mark all read". Shared state so the desktop and mobile bars stay in sync.
 */
export interface PortalNotification {
  id: string
  type?: string
  title?: string
  body?: string
  opportunity_uuid?: string
  /** AGR-03/05 — agreement notices */
  agreement_uuid?: string
  reference_no?: string
  read_at: string | null
  created_at: string | null
}

interface NotificationsResponse {
  data: PortalNotification[]
  meta: { unread_count?: number; total?: number }
}

export function useNotifications() {
  const api = useApi()
  const auth = useAuthStore()
  const items = useState<PortalNotification[]>('portal-notifications', () => [])
  const unread = useState<number>('portal-notifications-unread', () => 0)
  const loading = useState<boolean>('portal-notifications-loading', () => false)

  const refreshCount = async () => {
    if (!auth.isAuthenticated) return
    try {
      const r = await api.get<NotificationsResponse>('/me/notifications', { per_page: 1, unread: 1 })
      unread.value = r.meta?.unread_count ?? r.meta?.total ?? 0
    } catch {
      /* transient — keep the last value */
    }
  }

  const load = async () => {
    loading.value = true
    try {
      const r = await api.get<NotificationsResponse>('/me/notifications', { per_page: 8 })
      items.value = r.data ?? []
      unread.value = r.meta?.unread_count ?? unread.value
    } catch {
      items.value = []
    } finally {
      loading.value = false
    }
  }

  const markAllRead = async () => {
    await api.post('/me/notifications/read', {})
    items.value = items.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
    unread.value = 0
  }

  /** Deep link for a notification, depending on the viewer's portal. */
  const linkFor = (n: PortalNotification): string | null => {
    const t = n.type ?? ''
    if (t.startsWith('agreement.') && n.agreement_uuid) return auth.isStaff ? `/app/admin/agreements/${n.agreement_uuid}` : auth.isEntrepreneur ? `/app/entrepreneur/agreements/${n.agreement_uuid}` : `/app/investor/agreements/${n.agreement_uuid}`
    if (n.opportunity_uuid) {
      if (auth.isStaff) return `/app/admin/opportunities/${n.opportunity_uuid}`
      // ENT-04 — completion requests / reminders open the form; anything else (status, evaluation result…) the project journey.
      if (auth.isEntrepreneur) return t.startsWith('opportunity.completion') ? `/app/entrepreneur/opportunities/${n.opportunity_uuid}/edit` : `/app/entrepreneur/opportunities/${n.opportunity_uuid}`
      return `/app/opportunities/${n.opportunity_uuid}`
    }
    if (t.startsWith('membership')) return auth.isStaff ? '/app/admin/memberships' : '/app/investor/profile'
    if (t.startsWith('meeting')) return auth.isStaff ? '/app/admin/meetings' : auth.isEntrepreneur ? '/app/entrepreneur/meetings' : '/app/investor/meetings'
    if (t.startsWith('contact')) return '/app/admin/content'
    if (t.startsWith('info_request')) return auth.isStaff ? '/app/admin/info-requests' : auth.isEntrepreneur ? '/app/entrepreneur/info-requests' : '/app/investor/info-requests'
    return null
  }

  /** Starts polling; call from a component's setup (stops on unmount). */
  const startPolling = () => {
    if (import.meta.server) return
    let timer: ReturnType<typeof setInterval> | null = null
    onMounted(() => {
      refreshCount()
      timer = setInterval(() => {
        if (document.visibilityState === 'visible') refreshCount()
      }, 60_000)
    })
    onBeforeUnmount(() => timer && clearInterval(timer))
  }

  return { items, unread, loading, load, markAllRead, refreshCount, linkFor, startPolling }
}
