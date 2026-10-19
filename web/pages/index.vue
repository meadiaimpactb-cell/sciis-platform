<script setup lang="ts">
/** Home — doc §7.1 / §10.2 */
import type { OpportunityCard, PublicStats, ContentItem } from '~/types/api'

definePageMeta({ layout: 'default' })
const { t, tm, rt } = useI18n()
useHead({ title: t('home.metaTitle') })
const api = useApi()
const { number } = useFormat()
const { date } = useFormat()

const { data: featured } = await useAsyncData('home-featured', () => api.get<{ data: OpportunityCard[] }>('/public/featured-opportunities').then((r) => r.data).catch(() => []))
const { data: stats } = await useAsyncData('home-stats', () => api.get<{ data: PublicStats }>('/public/stats').then((r) => r.data).catch(() => null))
const { data: news } = await useAsyncData('home-news', () => api.get<{ data: ContentItem[] }>('/public/news').then((r) => r.data.slice(0, 3)).catch(() => []))

// Replaceable hero image area: 3 rotating labelled placeholders
const heroIdx = ref(0)
const heroVariant = computed(() => ((heroIdx.value % 3) + 1) as 1 | 2 | 3)
let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  timer = setInterval(() => (heroIdx.value = (heroIdx.value + 1) % 3), 5000)
})
onBeforeUnmount(() => timer && clearInterval(timer))

const benefits = computed(() => (tm('home.benefits') as string[]).map((b) => rt(b)))
const impactPoints = computed(() => (tm('home.impactPoints') as string[]).map((b) => rt(b)))
const investorPoints = computed(() => (tm('home.investorPath.points') as string[]).map((b) => rt(b)))
const entrepreneurPoints = computed(() => (tm('home.entrepreneurPath.points') as string[]).map((b) => rt(b)))
const steps = [1, 2, 3, 4, 5]
</script>

<template>
  <div>
    <!-- HERO -->
    <section class="bg-ink text-white relative overflow-hidden">
      <div class="absolute inset-0 opacity-40 pointer-events-none" style="background: radial-gradient(60% 60% at 85% 20%, rgba(31,91,255,0.45) 0, transparent 60%), radial-gradient(40% 40% at 10% 90%, rgba(15,118,110,0.4) 0, transparent 60%)" aria-hidden="true" />
      <div class="container-x relative grid lg:grid-cols-[1.05fr_1fr] gap-10 lg:gap-14 py-16 lg:py-24 items-center">
        <div>
          <p class="inline-flex items-center gap-2 rounded-full bg-white/10 border border-white/15 px-3 py-1 text-xs font-medium text-white/85">
            <span class="h-1.5 w-1.5 rounded-full bg-blue" aria-hidden="true" />{{ $t('home.heroEyebrow') }}
          </p>
          <h1 class="mt-5 text-4xl sm:text-5xl lg:text-[3.4rem] font-semibold leading-[1.15] text-white text-balance">{{ $t('home.heroTitle') }}</h1>
          <p class="mt-5 text-lg text-white/75 leading-8 max-w-xl">{{ $t('home.heroText') }}</p>
          <div class="mt-8 flex flex-wrap gap-3">
            <AppButton to="/auth/register" size="lg">{{ $t('home.ctaExplore') }}</AppButton>
            <AppButton to="/for-entrepreneurs" size="lg" variant="secondary">{{ $t('home.ctaSubmit') }}</AppButton>
          </div>
          <Disclaimer variant="dark" class="mt-5" />
          <dl v-if="stats" class="mt-10 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-xl">
            <div v-for="k in (['opportunities', 'members', 'sectors', 'connections'] as const)" :key="k" class="rounded-xl bg-white/5 border border-white/10 px-4 py-3">
              <dt class="text-xs text-white/60">{{ $t(`home.stats.${k}`) }}</dt>
              <dd class="text-2xl font-semibold num">{{ number(stats[k]) }}</dd>
            </div>
          </dl>
        </div>
        <div class="relative">
          <div class="relative aspect-[4/3] rounded-2xl overflow-hidden ring-1 ring-white/10 shadow-pop">
            <Transition name="page" mode="out-in">
              <PlaceholderImage :key="heroIdx" :variant="heroVariant" ratio="h-full" :intent="$t(`home.heroImages.${heroIdx + 1}`)" class="h-full" flush />
            </Transition>
          </div>
          <div class="mt-3 flex items-center justify-center gap-2" role="tablist" aria-label="hero images">
            <button v-for="i in 3" :key="i" type="button" role="tab" :aria-selected="heroIdx === i - 1" class="h-2 rounded-full transition-all kbd-focus" :class="heroIdx === i - 1 ? 'w-8 bg-blue' : 'w-2 bg-white/30 hover:bg-white/60'" :aria-label="`${$t('app.placeholderImage')} ${i}`" @click="heroIdx = i - 1" />
          </div>
        </div>
      </div>
    </section>

    <!-- TWO PATHS -->
    <section class="container-x py-16 lg:py-20">
      <div class="text-center max-w-2xl mx-auto">
        <h2 class="section-title">{{ $t('home.pathsTitle') }}</h2>
        <p class="section-sub mx-auto">{{ $t('home.pathsSub') }}</p>
      </div>
      <div class="mt-10 grid md:grid-cols-2 gap-6">
        <article class="card card-pad flex flex-col">
          <span class="h-11 w-11 rounded-xl bg-blue-soft text-blue flex items-center justify-center" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 20V10M10 20V4M16 20v-6M22 20H2" /></svg></span>
          <h3 class="mt-4 text-xl font-semibold">{{ $t('home.investorPath.title') }}</h3>
          <p class="mt-2 text-muted leading-7">{{ $t('home.investorPath.text') }}</p>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="p in investorPoints" :key="p" class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 rounded-full bg-blue shrink-0" aria-hidden="true" />{{ p }}</li>
          </ul>
          <AppButton to="/auth/register" class="mt-6 w-fit">{{ $t('home.investorPath.cta') }}</AppButton>
        </article>
        <article class="card card-pad flex flex-col">
          <span class="h-11 w-11 rounded-xl bg-success/10 text-success flex items-center justify-center" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2a7 7 0 017 7c0 3-2 5-3 6v3H8v-3C7 14 5 12 5 9a7 7 0 017-7zM9 22h6" /></svg></span>
          <h3 class="mt-4 text-xl font-semibold">{{ $t('home.entrepreneurPath.title') }}</h3>
          <p class="mt-2 text-muted leading-7">{{ $t('home.entrepreneurPath.text') }}</p>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="p in entrepreneurPoints" :key="p" class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 rounded-full bg-success shrink-0" aria-hidden="true" />{{ p }}</li>
          </ul>
          <AppButton to="/auth/register?type=entrepreneur_entity" variant="dark" class="mt-6 w-fit">{{ $t('home.entrepreneurPath.cta') }}</AppButton>
        </article>
      </div>
    </section>

    <!-- FEATURED -->
    <section v-if="featured?.length" class="bg-surface border-y border-border">
      <div class="container-x py-16">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
          <div><h2 class="section-title">{{ $t('home.featuredTitle') }}</h2><p class="section-sub">{{ $t('home.featuredSub') }}</p></div>
          <AppButton to="/auth/login" variant="outline">{{ $t('home.featuredCta') }}</AppButton>
        </div>
        <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <OpportunityCard v-for="o in featured" :key="o.uuid" :opportunity="o" to="/auth/login" :show-status="false" />
        </div>
        <Disclaimer variant="box" class="mt-6" />
      </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="container-x py-16 lg:py-20">
      <div class="text-center max-w-2xl mx-auto"><h2 class="section-title">{{ $t('home.howTitle') }}</h2><p class="section-sub mx-auto">{{ $t('home.howSub') }}</p></div>
      <ol class="mt-10 grid sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <li v-for="s in steps" :key="s" class="card card-pad relative">
          <span class="num text-3xl font-semibold text-blue/30">0{{ s }}</span>
          <h3 class="mt-2 font-semibold">{{ $t(`home.how.${s}.title`) }}</h3>
          <p class="mt-1 text-sm text-muted leading-6">{{ $t(`home.how.${s}.text`) }}</p>
        </li>
      </ol>
    </section>

    <!-- IMPACT DEFINITION -->
    <section class="bg-navy text-white">
      <div class="container-x py-16 grid lg:grid-cols-2 gap-10 items-center">
        <div>
          <h2 class="text-2xl sm:text-3xl font-semibold text-white">{{ $t('home.impactTitle') }}</h2>
          <p class="mt-4 text-white/75 leading-8">{{ $t('home.impactText') }}</p>
        </div>
        <ul class="grid sm:grid-cols-2 gap-4">
          <li v-for="(p, i) in impactPoints" :key="p" class="rounded-xl bg-white/5 border border-white/10 p-4 flex items-start gap-3">
            <span class="num h-7 w-7 rounded-lg bg-blue text-white text-sm flex items-center justify-center shrink-0">{{ i + 1 }}</span>
            <span class="text-sm leading-6">{{ p }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- MEMBERSHIP BENEFITS -->
    <section class="container-x py-16 lg:py-20">
      <div class="text-center max-w-2xl mx-auto"><h2 class="section-title">{{ $t('home.benefitsTitle') }}</h2><p class="section-sub mx-auto">{{ $t('home.benefitsSub') }}</p></div>
      <ul class="mt-10 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <li v-for="b in benefits" :key="b" class="card p-4 flex items-start gap-3">
          <svg class="h-5 w-5 text-success shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
          <span class="text-sm leading-6">{{ b }}</span>
        </li>
      </ul>
    </section>

    <!-- NEWS -->
    <section v-if="news?.length" class="container-x pb-16">
      <h2 class="section-title">{{ $t('home.latestTitle') }}</h2>
      <div class="mt-6 grid md:grid-cols-3 gap-5">
        <article v-for="n in news" :key="n.id" class="card card-pad">
          <p class="text-xs text-muted">{{ date(n.published_at) }}</p>
          <h3 class="mt-1 font-semibold">{{ n.title_ar }}</h3>
          <p class="mt-2 text-sm text-muted leading-6">{{ n.body_ar }}</p>
        </article>
      </div>
    </section>

    <!-- CTA BAND -->
    <section class="container-x pb-4">
      <div class="rounded-2xl bg-blue text-white p-8 sm:p-10 flex flex-col sm:flex-row items-center justify-between gap-6">
        <div><h2 class="text-2xl font-semibold text-white">{{ $t('home.ctaBandTitle') }}</h2><p class="mt-1 text-white/80">{{ $t('home.ctaBandText') }}</p></div>
        <AppButton to="/auth/register" variant="secondary" size="lg">{{ $t('nav.joinInvestor') }}</AppButton>
      </div>
    </section>
  </div>
</template>
