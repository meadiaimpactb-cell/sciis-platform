import type { PublicSettings } from '~/types/api'

export type SocialNetwork = 'x' | 'linkedin' | 'instagram' | 'youtube'
export const SOCIAL_NETWORKS: SocialNetwork[] = ['x', 'linkedin', 'instagram', 'youtube']

/**
 * Official contact channels (CMS-05 / gap #3 "one official channel per medium"), managed from admin → settings
 * (`platform.official_email`, `platform.official_phone`, `platform.whatsapp`, per-audience e-mails
 * `platform.email_investors|email_entrepreneurs` and `platform.social.{x,linkedin,instagram,youtube}`).
 * Fetched once per request with a shared key so app.vue, the footer and the contact page reuse the same SSR payload.
 */
export function usePlatformContact() {
  const api = useApi()
  const { data } = useAsyncData('platform-contact', () =>
    api.get<{ data: PublicSettings }>('/settings/public').then((r) => ({ ...(r.data?.platform ?? {}), ga4: r.data?.analytics?.ga4 ?? null })).catch(() => ({})),
  )
  const platform = computed(() => (data.value ?? {}) as Partial<PublicSettings['platform'] & { ga4: string | null }>)
  const str = (v: unknown) => (typeof v === 'string' ? v.trim() : '')
  const email = computed(() => str(platform.value.official_email))
  const phone = computed(() => str(platform.value.official_phone))
  const whatsapp = computed(() => str(platform.value.whatsapp))
  const emailInvestors = computed(() => str(platform.value.email_investors))
  const emailEntrepreneurs = computed(() => str(platform.value.email_entrepreneurs))
  /** Only https links that are actually set, in a stable order. */
  const social = computed(() =>
    SOCIAL_NETWORKS.map((network) => ({ network, url: str(platform.value.social?.[network]) })).filter((s) => /^https:\/\//i.test(s.url)),
  )
  /** tel:/wa.me need digits only (keep a leading +). */
  const dial = (v: string) => v.replace(/[^\d+]/g, '')
  /** CMS-04: GA4 measurement id from admin → settings (empty = no analytics). */
  const ga4 = computed(() => str(platform.value.ga4))
  return { email, phone, whatsapp, emailInvestors, emailEntrepreneurs, social, dial, ga4 }
}
