<script setup lang="ts">
/**
 * Full public page for a self-assessment tool: hero, the quiz, "what it measures", FAQ and the other tool.
 * SEO: title/description/canonical/OG + JSON-LD WebApplication, FAQPage and BreadcrumbList (prerender-friendly: all
 * copy comes from the typed config / i18n, nothing is fetched).
 */
import { investorReadiness, projectReadiness } from '~/utils/assessments'

const props = defineProps<{ tool: 'investor' | 'project' }>()
const { t, locale } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
const siteUrl = useSiteUrl()
const L = (v: { ar: string; en: string }) => pickL10n(v, locale.value)

const config = props.tool === 'investor' ? investorReadiness : projectReadiness
const other = props.tool === 'investor' ? 'project' : 'investor'
const otherPath = props.tool === 'investor' ? '/tools/project-readiness' : '/tools/investor-readiness'
const title = t(`tools.${props.tool}.title`)
const lead = t(`tools.${props.tool}.lead`)
const cta = props.tool === 'investor'
  ? { to: '/auth/register?type=investor_individual', label: t('tools.investor.cta') }
  : { to: '/auth/register?type=entrepreneur_entity', label: t('tools.project.cta') }
const learn = props.tool === 'investor'
  ? { to: '/for-investors', label: t('tools.investor.learn') }
  : { to: '/for-entrepreneurs', label: t('tools.project.learn') }
const minutes = props.tool === 'investor' ? 3 : 4

const url = `${siteUrl}${route.path === '/' ? '' : route.path.replace(/\/$/, '')}`
useSeo({
  title: t(`tools.${props.tool}.metaTitle`),
  description: t(`tools.${props.tool}.metaDescription`),
  jsonLd: [
    {
      '@type': 'WebApplication',
      '@id': `${url}#app`,
      name: title,
      url,
      description: lead,
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Any',
      browserRequirements: 'Requires JavaScript',
      isAccessibleForFree: true,
      inLanguage: locale.value === 'ar' ? 'ar-SA' : 'en',
      offers: { '@type': 'Offer', price: '0', priceCurrency: 'SAR' },
      publisher: { '@id': `${siteUrl}/#organization` },
    },
    {
      '@type': 'FAQPage',
      mainEntity: config.faqs.map((f) => ({ '@type': 'Question', name: L(f.q), acceptedAnswer: { '@type': 'Answer', text: L(f.a) } })),
    },
    {
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: t('nav.home'), item: `${siteUrl}${localePath('/') === '/' ? '' : localePath('/')}` },
        { '@type': 'ListItem', position: 2, name: t('tools.title'), item: `${siteUrl}${localePath('/tools')}` },
        { '@type': 'ListItem', position: 3, name: title, item: url },
      ],
    },
  ],
})
</script>

<template>
  <div>
    <!-- hero: the PageHero surface with the breadcrumb above the pill label -->
    <section class="surface-hero">
      <OrbitBackdrop />
      <div class="container-x relative flex flex-col items-center text-center py-20 md:py-24">
        <nav :aria-label="$t('tools.breadcrumb')" class="text-sm text-t3">
          <ol class="flex flex-wrap items-center justify-center gap-1.5">
            <li><NuxtLink :to="localePath('/')" class="hover:text-ink transition">{{ $t('nav.home') }}</NuxtLink></li>
            <li aria-hidden="true">/</li>
            <li><NuxtLink :to="localePath('/tools')" class="hover:text-ink transition">{{ $t('tools.title') }}</NuxtLink></li>
          </ol>
        </nav>
        <SectionLabel pill class="mt-6">{{ $t('tools.eyebrow') }}</SectionLabel>
        <h1 class="display mt-6 max-w-4xl text-ink text-balance">{{ title }}</h1>
        <p class="lead mx-auto mt-6 max-w-3xl text-t2">{{ lead }}</p>
        <ul class="mt-8 flex flex-wrap justify-center gap-2 text-sm text-t2" role="list">
          <li class="rounded-full border border-ink/20 bg-ink/5 px-3.5 py-1.5 num">{{ $t(config.questions.length <= 10 ? 'tools.questionsFew' : 'tools.questionsCount', { n: config.questions.length }) }}</li>
          <li class="rounded-full border border-ink/20 bg-ink/5 px-3.5 py-1.5 num">{{ $t('tools.minutes', { n: minutes }) }}</li>
          <li class="rounded-full border border-ink/20 bg-ink/5 px-3.5 py-1.5">{{ $t('tools.noSignup') }}</li>
          <li class="rounded-full border border-ink/20 bg-ink/5 px-3.5 py-1.5">{{ $t('tools.private') }}</li>
        </ul>
      </div>
    </section>

    <div class="container-x section-y grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-8 items-start">
      <AssessmentQuiz :config="config" :tool-name="title" :cta="cta" :learn="learn" />

      <aside class="space-y-5 lg:sticky lg:top-24">
        <section class="card card-pad" aria-labelledby="measures-title">
          <h2 id="measures-title" class="card-title">{{ $t('tools.measuresTitle') }}</h2>
          <div v-for="a in config.axes" :key="a.id" class="mt-4">
            <h3 class="text-sm font-semibold text-navy">{{ L(a.label) }}</h3>
            <p class="text-xs text-muted leading-5 mt-0.5">{{ L(a.description) }}</p>
            <ul class="mt-2 space-y-1.5 text-sm" role="list">
              <li v-for="q in config.questions.filter((x) => x.axis === a.id)" :key="q.id" class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 rounded-full bg-teal shrink-0" aria-hidden="true" />{{ L(q.short) }}</li>
            </ul>
          </div>
        </section>
        <section class="rounded-2xl border border-teal/30 bg-teal-soft/50 p-5" aria-labelledby="privacy-title">
          <h2 id="privacy-title" class="font-semibold text-ink flex items-center gap-2"><svg class="h-5 w-5 text-teal-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z" /><path d="M9 12l2 2 4-4" /></svg>{{ $t('tools.privacyTitle') }}</h2>
          <p class="mt-2 text-sm text-ink/80 leading-6">{{ $t('tools.privacyText') }}</p>
        </section>
      </aside>
    </div>

    <section class="bg-surface border-y border-border">
      <div class="container-x section-y grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-8">
        <div>
          <h2 class="section-title">{{ $t('tools.faqTitle') }}</h2>
          <div class="mt-8 rounded-2xl border border-border bg-bg divide-y divide-border">
            <details v-for="(f, i) in config.faqs" :key="i" class="group">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-base font-bold text-ink kbd-focus rounded-2xl [&::-webkit-details-marker]:hidden">{{ L(f.q) }}<svg class="h-4 w-4 text-muted transition shrink-0 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></summary>
              <p class="px-5 pb-5 text-desc text-muted">{{ L(f.a) }}</p>
            </details>
          </div>
        </div>
        <div class="self-end">
          <SectionLabel>{{ $t('tools.otherTool') }}</SectionLabel>
          <NuxtLink :to="localePath(otherPath)" class="site-glass mt-3 block p-6 transition hover:border-accent/60 kbd-focus">
            <span class="card-title block">{{ $t(`tools.${other}.title`) }}</span>
            <span class="block mt-1.5 text-sm text-muted leading-6">{{ $t(`tools.${other}.lead`) }}</span>
          </NuxtLink>
          <AppButton to="/tools" variant="link" class="mt-3">{{ $t('tools.allTools') }}</AppButton>
        </div>
      </div>
    </section>
  </div>
</template>
