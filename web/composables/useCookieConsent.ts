/**
 * CMS-04 — cookie consent state. Stored per browser in localStorage; analytics or any non-essential script must
 * check `accepted.value === true` before loading. Reads/writes are wrapped because storage can be blocked.
 */
const KEY = 'sciis.cookie-consent'
type Choice = 'accepted' | 'rejected' | null

export function useCookieConsent() {
  const choice = useState<Choice>('cookie-consent', () => null)
  const loaded = useState<boolean>('cookie-consent-loaded', () => false)

  const load = () => {
    if (import.meta.server || loaded.value) return
    try {
      const v = localStorage.getItem(KEY)
      choice.value = v === 'accepted' || v === 'rejected' ? v : null
    } catch {
      choice.value = null
    }
    loaded.value = true
  }
  const set = (v: Exclude<Choice, null>) => {
    choice.value = v
    try {
      localStorage.setItem(KEY, v)
    } catch {
      /* storage blocked — the banner will simply show again next visit */
    }
  }

  return {
    choice,
    loaded,
    load,
    accept: () => set('accepted'),
    reject: () => set('rejected'),
    accepted: computed(() => choice.value === 'accepted'),
    pending: computed(() => loaded.value && choice.value === null),
  }
}
