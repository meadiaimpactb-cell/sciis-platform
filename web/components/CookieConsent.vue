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
        class="cookie fixed bottom-4 inset-x-4 sm:inset-x-auto sm:end-6 sm:max-w-md z-50"
        role="dialog"
        aria-live="polite"
        :aria-label="$t('app.cookie.title')"
      >
        <div class="flex items-start gap-3">
          <span class="cookie__icon h-9 w-9 rounded-lg flex items-center justify-center shrink-0" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3a9 9 0 109 9 4 4 0 01-4-4 4 4 0 01-5-5z" /><circle cx="9" cy="10" r="1" /><circle cx="14" cy="15" r="1" /><circle cx="8" cy="15" r="1" /></svg>
          </span>
          <div class="min-w-0">
            <p class="cookie__title">{{ $t('app.cookie.title') }}</p>
            <p class="cookie__text mt-1">
              {{ $t('app.cookie.text') }}
              <NuxtLink :to="localePath('/legal/privacy')" class="cookie__link">{{ $t('app.cookie.learnMore') }}</NuxtLink>
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
              <button type="button" class="site-btn site-btn--primary kbd-focus" @click="consent.accept()">{{ $t('app.cookie.accept') }}</button>
              <button type="button" class="site-btn site-btn--outline kbd-focus" @click="consent.reject()">{{ $t('app.cookie.reject') }}</button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </ClientOnly>
</template>

<style scoped>
/* Follows the public theme (design v3). */
.cookie {
  padding: 20px;
  border-radius: 16px;
  background: var(--bg1);
  color: var(--fg);
  border: 1px solid rgba(var(--fg-rgb), 0.14);
  box-shadow: 0 30px 60px -20px rgba(var(--shadow-rgb), 0.6);
  font-family: var(--fb);
}
.cookie__icon { background: rgba(88, 176, 168, 0.16); color: var(--accent-text); }
.cookie__title { font: 600 15px var(--fb); color: var(--fg); }
.cookie__text { font: 400 14px/1.7 var(--fb); color: var(--t2); }
.cookie__link { color: var(--accent-text); text-decoration: underline; text-underline-offset: 3px; }
</style>
