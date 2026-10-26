<script setup lang="ts">
/**
 * Public footer (design v3): the statement and contact pills on the start side, five numbered link groups on the end
 * side, then the legal row with language, theme, calendar and "back to top". The layout renders it as
 * `<LazySiteFooter hydrate-on-visible>` — fully server-rendered (links crawlable), hydrated when it scrolls into view.
 */
defineProps<{ groups: { key: string; links: { to: string; key: string }[] }[] }>()
const localePath = useLocalePath()
const contact = usePlatformContact()
const year = new Date().getFullYear()
const pad = (n: number) => String(n).padStart(2, '0')
const toTop = () => window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
</script>

<template>
  <footer class="site-footer site-surface">
    <div class="site-wrap site-footer__inner">
      <div class="site-footer__top">
        <div class="site-footer__brand">
          <img class="on-dark site-footer__logo" src="/brand/logo-white.webp" :alt="$t('app.name')" width="165" height="56" loading="lazy" decoding="async">
          <img class="on-light site-footer__logo" src="/brand/logo.webp" :alt="$t('app.name')" width="165" height="56" loading="lazy" decoding="async">
          <h2 class="site-footer__statement">{{ $t('landing.footer.statement') }}</h2>
          <p class="site-footer__tagline">{{ $t('landing.footer.tagline') }}</p>
          <div class="site-footer__contact">
            <a v-if="contact.email.value" :href="`mailto:${contact.email.value}`" class="site-footer__email kbd-focus" dir="ltr"><span class="site-footer__dot" aria-hidden="true" />{{ contact.email.value }}</a>
            <NuxtLink :to="localePath('/contact')" class="site-btn site-btn--outline site-footer__pill kbd-focus">{{ $t('landing.footer.contact') }}</NuxtLink>
          </div>
          <SocialLinks v-if="contact.social.value.length" site />
        </div>

        <nav class="site-footer__groups" :aria-label="$t('footer.quickLinks')">
          <section v-for="(g, i) in groups" :key="g.key" class="site-footer__group" :aria-labelledby="`ft-${i}`">
            <h3 :id="`ft-${i}`" class="site-footer__group-title"><span class="site-footer__group-num" aria-hidden="true">{{ pad(i + 1) }}</span>{{ $t(g.key) }}</h3>
            <NuxtLink v-for="l in g.links" :key="l.to" :to="localePath(l.to)" class="site-footer__link">{{ $t(l.key) }}</NuxtLink>
          </section>
        </nav>
      </div>

      <div class="site-footer__legal">
        <div class="site-footer__legal-text">
          <span class="site-footer__disclaimer"><span class="site-footer__dot site-footer__dot--accent" aria-hidden="true" />{{ $t('landing.footer.disclaimer') }}</span>
          <span>{{ $t('footer.rights', { year }) }}</span>
        </div>
        <div class="site-footer__tools">
          <!-- UX-05 — dates on the site in Gregorian / Hijri (this browser's choice) -->
          <CalendarSwitch site />
          <LangSwitch pill />
          <SiteThemeToggle />
          <button type="button" class="site-icon-btn site-footer__top-btn kbd-focus" :aria-label="$t('landing.footer.top')" :title="$t('landing.footer.top')" @click="toTop">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 16V4M4.5 9.5 10 4l5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
        </div>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.site-footer {
  position: relative;
  background: var(--bg-footer);
  /* laid out only when it nears the viewport (first-paint cost); the size is remembered after the first render */
  content-visibility: auto;
  contain-intrinsic-size: auto 760px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.08);
}
.site-footer__inner {
  padding-block: 104px 36px;
  display: flex;
  flex-direction: column;
  gap: 80px;
}
.site-footer__top {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
  gap: 64px 96px;
  align-items: start;
}
.site-footer__brand {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 26px;
  max-width: 520px;
}
.site-footer__logo { height: 56px; width: auto; }
.site-footer__statement { font: 800 44px/1.3 var(--fd); }
.site-footer__tagline { margin: 0; font: 400 16px/1.8 var(--fb); color: var(--t2); }
.site-footer__contact { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; }
.site-footer__email {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  height: 48px;
  padding-inline: 20px;
  border-radius: 999px;
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  background: rgba(88, 176, 168, 0.08);
  color: var(--accent-text);
  font: 500 15px var(--fb);
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.site-footer__email:hover { background: var(--scii-teal); border-color: var(--scii-teal); color: var(--scii-teal-ink); }
.site-footer__pill { height: 48px; padding-inline: 20px; font-size: 15px; }
.site-footer__dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex: none; }
.site-footer__dot--accent { background: var(--accent-hi); }

.site-footer__groups {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
  gap: 36px 28px;
}
.site-footer__group {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-top: 18px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.14);
}
.site-footer__group-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  font: 700 14px var(--fd);
}
.site-footer__group-num { font: 800 12px var(--fd); color: var(--accent-text); }
.site-footer__link {
  font: 400 14px/1.5 var(--fb);
  color: var(--t3);
  transition: color 0.15s ease;
}
.site-footer__link:hover { color: var(--fg); }

.site-footer__legal {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px 32px;
  padding-top: 24px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.1);
}
.site-footer__legal-text {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 24px;
  font: 400 13px/1.6 var(--fb);
  color: var(--t3);
}
.site-footer__disclaimer { display: inline-flex; align-items: center; gap: 8px; }
.site-footer__tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.site-footer__top-btn:hover { background: var(--scii-teal); border-color: var(--scii-teal); color: var(--scii-teal-ink); }

@media (max-width: 640px) {
  .site-footer__inner { padding-block: 64px 28px; gap: 56px; }
  .site-footer__statement { font-size: 32px; }
}
</style>
