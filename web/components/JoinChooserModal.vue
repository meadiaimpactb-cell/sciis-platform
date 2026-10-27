<script setup lang="ts">
/**
 * «أنا…» join chooser (Falak-style "I'm a startup / investor"): two cards routing to the register page with the
 * account type preselected. One instance lives in the default layout; open it with useJoinChooser().open($event).
 */
const { isOpen, close } = useJoinChooser()
const localePath = useLocalePath()
const route = useRoute()
watch(() => route.fullPath, () => { if (isOpen.value) close(false) })

const paths = [
  {
    key: 'investor', type: 'investor_individual', alt: 'investor_institutional', accent: 'bg-accent-hi', tint: 'border border-accent/35 bg-accent/10 text-accent-text',
    icon: 'M4 20V10M10 20V4M16 20v-6M22 20H2',
  },
  {
    key: 'entrepreneur', type: 'entrepreneur_entity', alt: 'entrepreneur_individual', accent: 'bg-ink/40', tint: 'border border-ink/20 bg-ink/5 text-ink',
    icon: 'M12 2a7 7 0 017 7c0 3-2 5-3 6v3H8v-3C7 14 5 12 5 9a7 7 0 017-7zM9 22h6',
  },
] as const
const register = (type: string) => localePath({ path: '/auth/register', query: { type } })
</script>

<template>
  <Modal :open="isOpen" :title="$t('join.title')" size="lg" @close="close()">
    <p class="text-sm text-muted -mt-1 mb-4">{{ $t('join.sub') }}</p>
    <ul class="grid sm:grid-cols-2 gap-4" role="list">
      <li v-for="p in paths" :key="p.key" class="site-glass p-5 flex flex-col overflow-hidden transition hover:border-accent/60 focus-within:ring-2 focus-within:ring-accent-text">
        <span class="absolute inset-x-0 top-0 h-1" :class="p.accent" aria-hidden="true" />
        <span class="h-11 w-11 rounded-full flex items-center justify-center" :class="p.tint" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="p.icon" /></svg></span>
        <h3 class="card-title mt-4">
          <NuxtLink :to="register(p.type)" class="after:absolute after:inset-0 focus:outline-none" @click="close(false)">{{ $t(`join.${p.key}.title`) }}</NuxtLink>
        </h3>
        <p class="mt-1.5 text-sm text-muted leading-6 flex-1">{{ $t(`join.${p.key}.text`) }}</p>
        <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-accent-text" aria-hidden="true">{{ $t(`join.${p.key}.cta`) }}<svg class="h-4 w-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6" /></svg></span>
        <NuxtLink :to="register(p.alt)" class="relative z-10 mt-3 w-fit text-xs text-muted underline-offset-4 hover:underline hover:text-accent-text kbd-focus rounded" @click="close(false)">{{ $t(`join.${p.key}.alt`) }}</NuxtLink>
      </li>
    </ul>
    <div class="mt-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm">
      <p class="text-muted">{{ $t('join.notSure') }} <NuxtLink :to="localePath('/tools')" class="link font-medium" @click="close(false)">{{ $t('join.tryTools') }}</NuxtLink></p>
      <p class="text-muted">{{ $t('join.haveAccount') }} <NuxtLink :to="localePath('/auth/login')" class="link font-medium" @click="close(false)">{{ $t('nav.login') }}</NuxtLink></p>
    </div>
  </Modal>
</template>
