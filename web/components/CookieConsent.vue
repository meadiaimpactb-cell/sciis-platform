<script setup lang="ts">
/** CMS-04 — cookie notice. Rendered only on the client after mount so SSR output never shifts. */
const consent = useCookieConsent()
const localePath = useLocalePath()
const { ga4 } = usePlatformContact()

/** GA4 loads only after the visitor accepted cookies and only when an id is set in admin → settings. */
const loadAnalytics = (id: string) => {
  const w = window as unknown as { dataLayer?: unknown[]; gtag?: (...args: unknown[]) => void }
  if (!/^G-[A-Z0-9]{4,16}$/.test(id) || w.gtag) return
  w.dataLayer = w.dataLayer ?? []
  // gtag.js reads the `arguments` object itself, so this must stay a plain function.
  w.gtag = function () {
    w.dataLayer!.push(arguments)
  }
  w.gtag('js', new Date())
  w.gtag('config', id, { anonymize_ip: true })
  const s = document.createElement('script')
  s.async = true
  s.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`
  document.head.appendChild(s)
}
onMounted(() => {
  consent.load()
  watch([consent.accepted, ga4], ([ok, id]) => ok && id && loadAnalytics(id), { immediate: true })
})
</script>

<template>
  <ClientOnly>
    <Transition name="page">
      <div
        v-if="consent.pending.value"
        class="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:end-6 sm:max-w-md z-50 card shadow-pop p-5 border-navy/20"
        role="dialog"
        aria-live="polite"
        :aria-label="$t('app.cookie.title')"
      >
        <div class="flex items-start gap-3">
          <span class="h-9 w-9 rounded-lg bg-teal-soft text-teal-text flex items-center justify-center shrink-0" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3a9 9 0 109 9 4 4 0 01-4-4 4 4 0 01-5-5z" /><circle cx="9" cy="10" r="1" /><circle cx="14" cy="15" r="1" /><circle cx="8" cy="15" r="1" /></svg>
          </span>
          <div class="min-w-0">
            <p class="font-semibold text-ink">{{ $t('app.cookie.title') }}</p>
            <p class="mt-1 text-sm text-muted leading-6">
              {{ $t('app.cookie.text') }}
              <NuxtLink :to="localePath('/legal/privacy')" class="link">{{ $t('app.cookie.learnMore') }}</NuxtLink>
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
              <AppButton size="sm" @click="consent.accept()">{{ $t('app.cookie.accept') }}</AppButton>
              <AppButton size="sm" variant="secondary" @click="consent.reject()">{{ $t('app.cookie.reject') }}</AppButton>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </ClientOnly>
</template>
