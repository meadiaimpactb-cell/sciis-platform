<script setup lang="ts">
/** Slim promo band for the self-assessment tools (home after "how it works", for-investors, for-entrepreneurs). */
const props = withDefaults(defineProps<{ focus?: 'investor' | 'entrepreneur' | 'both' }>(), { focus: 'both' })
const localePath = useLocalePath()
const tools = computed(() => [
  { key: 'investor', to: '/tools/investor-readiness', meta: { n: investorReadiness.questions.length, min: 3 } },
  { key: 'project', to: '/tools/project-readiness', meta: { n: projectReadiness.questions.length, min: 4 } },
].filter((x) => props.focus === 'both' || (props.focus === 'investor' ? x.key === 'investor' : x.key === 'project')))
</script>

<template>
  <section class="container-x" :aria-label="$t('footer.tools')">
    <div class="relative overflow-hidden rounded-md border border-border bg-bg/60 px-5 py-6 sm:px-8 flex flex-col lg:flex-row lg:items-center gap-5 lg:gap-8">
      <div class="flex items-start gap-4 lg:max-w-sm">
        <span class="h-11 w-11 shrink-0 rounded-md border border-teal/50 text-teal-text flex items-center justify-center" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" /></svg></span>
        <div>
          <h2 class="card-title">{{ $t('tools.band.title') }}</h2>
          <p class="mt-2 text-sm text-muted">{{ $t('tools.band.text') }}</p>
        </div>
      </div>
      <ul class="flex-1 grid gap-3" :class="tools.length > 1 ? 'sm:grid-cols-2' : ''" role="list">
        <li v-for="x in tools" :key="x.key">
          <NuxtLink :to="localePath(x.to)" class="group h-full flex items-center justify-between gap-3 rounded-md bg-surface border border-border px-4 py-3.5 transition hover:border-navy/40 kbd-focus">
            <span class="min-w-0">
              <span class="block text-base font-bold text-ink group-hover:text-navy">{{ $t(`tools.${x.key}.title`) }}</span>
              <span class="block text-xs text-muted mt-0.5 num">{{ $t(x.meta.n <= 10 ? 'tools.questionsFew' : 'tools.questionsCount', { n: x.meta.n }) }} · {{ $t('tools.minutes', { n: x.meta.min }) }} · {{ $t('tools.noSignup') }}</span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-teal-text transition group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
          </NuxtLink>
        </li>
      </ul>
    </div>
  </section>
</template>
