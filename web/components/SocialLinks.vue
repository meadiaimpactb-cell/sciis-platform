<script setup lang="ts">
/** Official social accounts from settings (`platform.social.*`); renders nothing until at least one is set. */
import type { SocialNetwork } from '~/composables/usePlatformContact'

// `site` follows the public theme (design v3 footer); `dark` is for fixed navy surfaces.
withDefaults(defineProps<{ dark?: boolean; site?: boolean }>(), { dark: false, site: false })
const { social } = usePlatformContact()
const NAMES: Record<SocialNetwork, string> = { x: 'X', linkedin: 'LinkedIn', instagram: 'Instagram', youtube: 'YouTube' }
// Simple Icons (CC0) brand marks, 24×24.
const ICONS: Record<SocialNetwork, string> = {
  x: 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z',
  linkedin: 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
  instagram: 'M12 2.163c3.204 0 3.584.012 4.85.07 1.17.054 1.805.249 2.227.415.56.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.054 1.17-.249 1.805-.413 2.227-.217.562-.477.96-.896 1.382-.42.419-.819.679-1.382.896-.422.164-1.057.36-2.227.413-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.17-.054-1.805-.249-2.227-.413a3.7 3.7 0 01-1.381-.896 3.7 3.7 0 01-.896-1.382c-.164-.422-.36-1.057-.413-2.227-.058-1.266-.07-1.646-.07-4.85s.012-3.584.07-4.85c.054-1.17.249-1.805.413-2.227.217-.562.477-.96.896-1.381a3.7 3.7 0 011.381-.896c.422-.166 1.057-.36 2.227-.415 1.266-.058 1.646-.07 4.85-.07M12 0C8.741 0 8.332.014 7.052.072 5.775.13 4.903.333 4.14.63a5.9 5.9 0 00-2.126 1.384A5.9 5.9 0 00.63 4.14C.333 4.903.13 5.775.072 7.052.014 8.332 0 8.741 0 12s.014 3.668.072 4.948c.058 1.277.261 2.149.558 2.912a5.9 5.9 0 001.384 2.126A5.9 5.9 0 004.14 23.37c.763.297 1.635.5 2.912.558C8.332 23.986 8.741 24 12 24s3.668-.014 4.948-.072c1.277-.058 2.149-.261 2.912-.558a5.9 5.9 0 002.126-1.384 5.9 5.9 0 001.384-2.126c.297-.763.5-1.635.558-2.912.058-1.28.072-1.689.072-4.948s-.014-3.668-.072-4.948c-.058-1.277-.261-2.149-.558-2.912a5.9 5.9 0 00-1.384-2.126A5.9 5.9 0 0019.86.63C19.097.333 18.225.13 16.948.072 15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z',
  youtube: 'M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
}
</script>

<template>
  <ul v-if="social.length" class="flex flex-wrap items-center gap-2" role="list">
    <li v-for="s in social" :key="s.network">
      <a
        :href="s.url" target="_blank" rel="me noopener"
        class="h-10 w-10 rounded-lg flex items-center justify-center transition kbd-focus"
        :class="site ? 'site-social' : dark ? 'bg-white/10 text-white/85 hover:bg-teal hover:text-navy-900' : 'bg-navy-50 text-navy hover:bg-navy hover:text-white'"
        :aria-label="$t('footer.socialLabel', { network: NAMES[s.network] })"
      >
        <svg class="h-[1.1rem] w-[1.1rem]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path :d="ICONS[s.network]" /></svg>
      </a>
    </li>
  </ul>
</template>
