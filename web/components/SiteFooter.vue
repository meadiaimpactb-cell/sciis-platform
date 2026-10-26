<script setup lang="ts">
/**
 * Public footer (falak.sa structure): dark, link groups in three columns + a contact column, centred copyright.
 * The layout renders it as `<LazySiteFooter hydrate-on-visible>` — fully server-rendered (links crawlable), but its
 * JavaScript only loads and hydrates when it scrolls into view.
 */
defineProps<{ groups: { key: string; links: { to: string; key: string }[] }[] }>()
const localePath = useLocalePath()
const contact = usePlatformContact()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="surface-dark relative overflow-hidden">
    <NightSky :orbits="false" />
    <div class="container-x relative pt-[3.75rem]">
      <div class="grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_minmax(0,1.15fr)]">
        <section v-for="g in groups" :key="g.key" :aria-labelledby="`ft-${g.key}`">
          <h2 :id="`ft-${g.key}`" class="text-base font-bold text-white">{{ $t(g.key) }}</h2>
          <ul class="mt-6 space-y-5 text-sm">
            <li v-for="l in g.links" :key="l.to"><NuxtLink :to="localePath(l.to)" class="text-white/80 transition hover:text-white">{{ $t(l.key) }}</NuxtLink></li>
          </ul>
        </section>
        <!-- contact column: end side, spanning both rows on desktop -->
        <section class="lg:col-start-4 lg:row-span-2 lg:row-start-1" aria-labelledby="ft-contact">
          <h2 id="ft-contact" class="text-base font-bold text-white">{{ $t('footer.groups.contact') }}</h2>
          <ul class="mt-6 space-y-5 text-sm">
            <li v-if="contact.email.value"><a :href="`mailto:${contact.email.value}`" class="ltr inline-block text-white/80 transition hover:text-white">{{ contact.email.value }}</a></li>
            <li v-if="contact.phone.value"><a :href="`tel:${contact.dial(contact.phone.value)}`" class="ltr num inline-block text-white/80 transition hover:text-white">{{ contact.phone.value }}</a></li>
            <li v-if="contact.whatsapp.value"><a :href="`https://wa.me/${contact.dial(contact.whatsapp.value).replace('+', '')}`" target="_blank" rel="noopener" class="ltr num inline-block text-white/80 transition hover:text-white">WhatsApp {{ contact.whatsapp.value }}</a></li>
            <li><NuxtLink :to="localePath('/contact')" class="text-white/80 transition hover:text-white">{{ $t('nav.contact') }}</NuxtLink></li>
          </ul>
          <SocialLinks v-if="contact.social.value.length" dark class="mt-6" />
          <BrandLogo dark size="md" class="mt-10" />
          <p class="mt-4 max-w-xs text-sm text-white/60">{{ $t('footer.about') }}</p>
        </section>
      </div>
      <div class="mt-16 border-t border-white/10 py-8 text-center text-sm text-white/50">
        <p>{{ $t('footer.rights', { year }) }}</p>
        <p class="mt-1">{{ $t('footer.disclaimerLine') }}</p>
        <!-- UX-05 — dates on the site in Gregorian / Hijri (this browser's choice) -->
        <CalendarSwitch dark class="mt-4" />
      </div>
    </div>
  </footer>
</template>
