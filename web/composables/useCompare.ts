/**
 * OPP-12 — opportunities picked for side-by-side comparison (2–4), kept across catalogue ↔ detail navigation for the
 * session (Nuxt state). Each entry keeps the name for the compare bar.
 */
export const COMPARE_MAX = 4
export const COMPARE_MIN = 2

export interface ComparePick { uuid: string; name: string }

export const useCompare = () => {
  const picks = useState<ComparePick[]>('opportunity-compare', () => [])
  const has = (uuid: string) => picks.value.some((p) => p.uuid === uuid)
  const full = computed(() => picks.value.length >= COMPARE_MAX)
  /** Adds or removes; returns false when the list is full. */
  const toggle = (pick: ComparePick): boolean => {
    if (has(pick.uuid)) {
      picks.value = picks.value.filter((p) => p.uuid !== pick.uuid)
      return true
    }
    if (full.value) return false
    picks.value = [...picks.value, pick]
    return true
  }
  const clear = () => { picks.value = [] }
  const remove = (uuid: string) => { picks.value = picks.value.filter((p) => p.uuid !== uuid) }
  const path = computed(() => `/app/opportunities/compare?ids=${picks.value.map((p) => p.uuid).join(',')}`)
  return { picks, has, full, toggle, clear, remove, path }
}
