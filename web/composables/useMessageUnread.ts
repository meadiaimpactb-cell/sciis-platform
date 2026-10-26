/**
 * MTG-03 — unread record-message count for the portal sidebar badge (shared state). Staff read
 * `/admin/messages/unread-count` (opportunity threads need opportunities.transition, interest / nomination
 * threads nominations.manage); owners and investors read `/messages/unread-count`.
 * Call it during setup (it captures the API client); `refresh()` can then run from any handler.
 */
export const useMessageUnread = () => {
  const count = useState<number>('sciis-messages-unread', () => 0)
  const api = useApi()
  const auth = useAuthStore()
  const enabled = computed(() => !!auth.me && (!auth.isStaff || auth.canAny('opportunities.transition', 'nominations.manage')))

  const refresh = async () => {
    if (!import.meta.client || !enabled.value) return
    try {
      const r = await api.get<{ data: { count: number } }>(`${auth.isStaff ? '/admin' : ''}/messages/unread-count`)
      count.value = Number(r.data?.count) || 0
    } catch {
      /* the badge is a hint only — keep the last value */
    }
  }

  return { count, enabled, refresh }
}
