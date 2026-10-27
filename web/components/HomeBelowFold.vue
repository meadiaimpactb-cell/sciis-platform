<script setup lang="ts">
/**
 * Home page below the first screen (design v3): what impact means (+ indicator card), the two paths, how the platform
 * works (stepper), membership benefits and the call to join, the latest news. Rendered as
 * `<LazyHomeBelowFold hydrate-on-visible>` — server-rendered, hydrated when it scrolls into view.
 */
import type { ContentItem } from '~/types/api'

/** Copy helpers of the home page (CMS home → meta.sections with the landing.* texts as the fallback). */
type Content = Pick<Awaited<ReturnType<typeof usePageContent>>, 'text' | 'list' | 'items'>
const props = defineProps<{ content: Content; news: ContentItem | null }>()
const { text, list } = props.content
const localePath = useLocalePath()
const join = useJoinChooser()
const { date } = useFormat()
const pad = (n: number) => String(n).padStart(2, '0')
const criteria = computed(() => list('impact_criteria', 'landing.impact.criteria'))
const benefits = computed(() => list('benefits_list', 'landing.benefits.items'))
const steps = computed(() => props.content.items<{ title?: string; text?: string; meta?: string }>('how_steps_list', 'landing.how.steps'))
const paths = computed(() => [
  { key: 'investor', cta: '/auth/register?type=investor_individual', tool: '/tools/investor-readiness', primary: true },
  { key: 'project', cta: '/auth/register?type=entrepreneur_entity', tool: '/tools/project-readiness', primary: false },
].map((p) => ({
  ...p,
  title: text(`path_${p.key}_title`, `landing.paths.${p.key}.title`),
  text: text(`path_${p.key}_text`, `landing.paths.${p.key}.text`),
  points: list(`path_${p.key}_points`, `landing.paths.${p.key}.points`),
  ctaLabel: text(`path_${p.key}_cta`, `landing.paths.${p.key}.cta`),
  toolLabel: text(`path_${p.key}_tool`, `landing.paths.${p.key}.tool`),
})))
</script>

<template>
  <div>
    <!-- What we mean by impact -->
    <section class="impact site-deferred" aria-labelledby="home-impact">
      <div class="site-wrap impact__inner">
        <div class="impact__copy">
          <span class="site-label">{{ text('impact_label', 'landing.impact.label') }}</span>
          <h2 id="home-impact" class="impact__title">{{ text('impact_heading', 'landing.impact.title') }}</h2>
          <p class="impact__text">{{ text('impact_body', 'landing.impact.text') }}</p>
          <ol class="impact__criteria">
            <li v-for="(c, i) in criteria" :key="i"><span class="impact__num">{{ pad(i + 1) }}</span>{{ c }}</li>
          </ol>
        </div>
        <IndicatorCard />
      </div>
    </section>

    <!-- The two paths -->
    <section id="paths" class="paths" :aria-label="$t('home.pathsTitle')">
      <div class="paths__grid">
        <div class="paths__badge" aria-hidden="true"><BrandMark :width="20" :framed="false" /></div>
        <article v-for="(p, i) in paths" :key="p.key" class="paths__half" :class="`paths__half--${p.key}`">
          <span class="paths__num">{{ pad(i + 1) }}</span>
          <h3 class="paths__title">{{ p.title }}</h3>
          <p class="paths__text">{{ p.text }}</p>
          <ul class="site-checks paths__points">
            <li v-for="(point, n) in p.points" :key="n">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" :stroke="p.primary ? 'var(--accent-hi)' : 'var(--fg)'" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
              {{ point }}
            </li>
          </ul>
          <div class="paths__actions">
            <NuxtLink :to="localePath(p.cta)" class="site-btn site-btn--lg kbd-focus" :class="p.primary ? 'site-btn--primary' : 'site-btn--secondary'">{{ p.ctaLabel }}</NuxtLink>
            <NuxtLink :to="localePath(p.tool)" class="site-link kbd-focus" :class="!p.primary && 'paths__tool--plain'">{{ p.toolLabel }} <span class="inline-block rtl:rotate-180" aria-hidden="true">→</span></NuxtLink>
          </div>
        </article>
      </div>
    </section>

    <!-- How the platform works -->
    <section class="how-section site-deferred" aria-labelledby="home-how">
      <div class="site-wrap how-section__inner">
        <div class="how-section__head">
          <span class="site-label">{{ text('how_label', 'landing.how.label') }}</span>
          <h2 id="home-how" class="section-heading">{{ text('how_heading', 'landing.how.title') }}</h2>
        </div>
        <HowItWorksStepper class="how-section__stepper" :steps="steps" :label="text('how_heading', 'landing.how.title')" />
        <p class="how-section__note">{{ text('how_note', 'landing.how.note') }}</p>
      </div>
    </section>

    <!-- Membership benefits + call to join -->
    <section id="join" class="join site-deferred" aria-labelledby="home-benefits">
      <div class="site-stars join__stars" aria-hidden="true" />
      <svg class="join__rings" viewBox="0 0 1400 900" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
        <defs>
          <linearGradient id="home-ring" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="var(--mark-1)" stop-opacity=".9" />
            <stop offset=".5" stop-color="var(--scii-teal)" stop-opacity=".25" />
            <stop offset="1" stop-color="var(--bg1)" stop-opacity="0" />
          </linearGradient>
        </defs>
        <circle cx="700" cy="1020" r="560" fill="none" stroke="url(#home-ring)" stroke-width="1.2" />
        <circle cx="700" cy="1020" r="760" fill="none" stroke="rgba(var(--fg-rgb), .1)" stroke-width="1" />
      </svg>
      <div class="site-wrap join__inner">
        <div class="join__benefits">
          <div class="join__head">
            <span class="site-label">{{ text('benefits_label', 'landing.benefits.label') }}</span>
            <h2 id="home-benefits" class="join__title">{{ text('benefits_heading', 'landing.benefits.title') }}</h2>
          </div>
          <ul class="join__tiles">
            <li v-for="(b, n) in benefits" :key="n" class="join__tile">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke="var(--accent-hi)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
              {{ b }}
            </li>
          </ul>
        </div>
        <div class="join__cta">
          <BrandMark :width="44" />
          <h2 class="join__cta-title">{{ text('join_title', 'landing.cta.title') }}</h2>
          <p class="join__cta-text">{{ text('join_text', 'landing.cta.text') }}</p>
          <div class="join__cta-actions">
            <button type="button" class="site-btn site-btn--primary site-btn--xl site-btn--glow kbd-focus" aria-haspopup="dialog" @click="join.open($event)">{{ $t('landing.cta.join') }}</button>
            <NuxtLink :to="localePath('/auth/login')" class="join__login kbd-focus">{{ $t('landing.cta.login') }}</NuxtLink>
          </div>
        </div>
      </div>
    </section>

    <!-- Latest news (one line) -->
    <section v-if="news" id="news" class="news site-deferred" aria-labelledby="home-news">
      <div class="site-wrap news__inner">
        <h2 id="home-news" class="site-label news__label">{{ $t('landing.news.label') }}</h2>
        <div class="news__item">
          <time v-if="news.published_at" class="news__date num" :datetime="news.published_at">{{ date(news.published_at) }}</time>
          <NuxtLink :to="localePath(`/news/${news.slug}`)" class="news__title kbd-focus">{{ news.title ?? news.title_ar }}</NuxtLink>
        </div>
        <NuxtLink :to="localePath('/news')" class="site-link kbd-focus">{{ $t('landing.news.all') }} <span class="inline-block rtl:rotate-180" aria-hidden="true">→</span></NuxtLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.section-heading { font: 800 var(--fs-title)/1.4 var(--fd); }

/* ------------------------------------------------------------------------------------------------ impact */
.impact { position: relative; overflow: hidden; background: var(--bg0); }
.impact__inner {
  padding-block: 128px 120px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 440px), 1fr));
  gap: 64px 96px;
  align-items: center;
}
.impact__copy { display: flex; flex-direction: column; gap: 26px; }
.impact__title { font: 800 var(--fs-title)/1.4 var(--fd); }
.impact__text { margin: 0; max-width: 560px; font: 400 var(--fs-desc)/1.85 var(--fb); color: var(--t2); }
.impact__criteria { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; }
.impact__criteria li {
  display: flex;
  align-items: center;
  gap: 18px;
  padding-block: 16px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.12);
  font: 500 var(--fs-desc)/1.5 var(--fb);
  color: var(--fg);
}
.impact__criteria li:last-child { border-bottom: 1px solid rgba(var(--fg-rgb), 0.12); }
.impact__num { min-width: 28px; font: 800 14px var(--fd); color: var(--accent-text); }

/* ------------------------------------------------------------------------------------------------- paths */
.paths {
  position: relative;
  background: var(--bg1);
  border-block: 1px solid rgba(var(--fg-rgb), 0.1);
}
.paths__grid {
  position: relative;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 480px), 1fr));
  gap: 1px;
  background: rgba(var(--fg-rgb), 0.12);
}
.paths__badge {
  position: absolute;
  left: 50%;
  top: 0;
  z-index: 2;
  transform: translate(-50%, -50%);
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--bg1);
  border: 1px solid rgba(var(--fg-rgb), 0.14);
  display: flex;
  align-items: center;
  justify-content: center;
}
.paths__half {
  position: relative;
  overflow: hidden;
  padding: 120px clamp(24px, 6vw, 96px) 112px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.paths__half--investor { background: linear-gradient(180deg, rgba(var(--scii-teal-rgb), 0.14), transparent 60%) var(--bg1); }
.paths__half--project { background: linear-gradient(180deg, var(--tint2), transparent 60%) var(--bg1); }
.paths__num { font: 800 var(--fs-body) var(--fd); color: var(--accent-text); }
.paths__half--project .paths__num { color: var(--t2); }
.paths__title { font: 800 var(--fs-subtitle)/1.4 var(--fd); }
.paths__text { margin: 0; max-width: 520px; font: 400 var(--fs-desc)/1.85 var(--fb); color: var(--t2); }
.paths__points { margin-top: 8px; }
.paths__actions { display: flex; align-items: center; flex-wrap: wrap; gap: 14px 28px; margin-top: 18px; }
.paths__actions .site-btn { height: 54px; padding-inline: 28px; }
.paths__tool--plain { color: var(--fg); border-color: rgba(var(--fg-rgb), 0.4); }
.paths__tool--plain:hover { color: var(--accent-text); border-color: var(--accent-text); }

/* --------------------------------------------------------------------------------------------------- how */
.how-section { background: var(--bg0); }
.how-section__inner { padding-block: 120px; }
.how-section__head { max-width: 760px; display: flex; flex-direction: column; gap: 14px; }
.how-section__stepper { margin-top: 64px; }
.how-section__note { margin: 48px 0 0; font: 400 14px/1.6 var(--fb); color: var(--t3); }

/* -------------------------------------------------------------------------------------------------- join */
.join {
  position: relative;
  overflow: hidden;
  background: radial-gradient(900px 600px at 50% 110%, var(--glow) 0%, var(--bg1) 55%, var(--bg0) 100%);
  border-top: 1px solid rgba(var(--fg-rgb), 0.08);
}
.join__stars {
  background-image:
    radial-gradient(1.5px 1.5px at 8% 30%, rgba(var(--fg-rgb), 0.8), transparent 60%),
    radial-gradient(1px 1px at 26% 70%, rgba(var(--fg-rgb), 0.6), transparent 60%),
    radial-gradient(1.5px 1.5px at 44% 20%, rgba(var(--fg-rgb), 0.7), transparent 60%),
    radial-gradient(2px 2px at 62% 80%, rgba(var(--accent-rgb), 0.9), transparent 60%),
    radial-gradient(1px 1px at 80% 36%, rgba(var(--fg-rgb), 0.8), transparent 60%),
    radial-gradient(1.5px 1.5px at 94% 64%, rgba(var(--fg-rgb), 0.6), transparent 60%);
}
.join__rings {
  position: absolute;
  left: 50%;
  bottom: 0;
  width: 1600px;
  max-width: none;
  height: 900px;
  transform: translateX(-50%);
  pointer-events: none;
}
.join__inner { position: relative; padding-block: 120px 136px; display: flex; flex-direction: column; gap: 80px; }
.join__benefits { display: flex; flex-direction: column; gap: 32px; }
.join__head { display: flex; flex-direction: column; gap: 14px; max-width: 760px; }
.join__title { font: 800 var(--fs-title)/1.4 var(--fd); }
.join__tiles {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
  gap: 14px;
}
.join__tile {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 20px 22px;
  border-radius: 14px;
  background: rgba(var(--fg-rgb), 0.05);
  border: 1px solid rgba(var(--fg-rgb), 0.1);
  font: 500 var(--fs-body)/1.7 var(--fb);
  color: var(--fg);
}
.join__tile svg { flex: none; margin-top: 3px; }
.join__cta { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 22px; padding-top: 16px; }
.join__cta-title { font: 800 var(--fs-display)/1.3 var(--fd); }
.join__cta-text { margin: 0; font: 400 var(--fs-lead)/1.7 var(--fb); color: var(--t2); }
.join__cta-actions { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 16px 28px; margin-top: 8px; }
.join__login {
  font: 500 15px var(--fb);
  color: var(--t2);
  border-bottom: 1px solid rgba(var(--fg-rgb), 0.3);
  padding-bottom: 2px;
  transition: color 0.15s ease, border-color 0.15s ease;
}
.join__login:hover { color: var(--fg); border-color: var(--fg); }

/* -------------------------------------------------------------------------------------------------- news */
.news { background: var(--bg1); border-top: 1px solid rgba(var(--fg-rgb), 0.08); }
.news__inner { padding-block: 52px; display: flex; flex-wrap: wrap; align-items: center; gap: 16px 40px; }
.news__label { flex: none; margin: 0; font-family: var(--fb); color: var(--accent-text); }
.news__item { flex: 1; min-width: 260px; display: flex; flex-direction: column; gap: 6px; }
.news__date { font: 400 var(--fs-small) var(--fb); color: var(--t3); }
.news__title { font: 700 var(--fs-card)/1.5 var(--fd); color: var(--fg); transition: color 0.15s ease; }
.news__title:hover { color: var(--accent-text); }

@media (max-width: 640px) {
  .impact__inner { padding-block: 80px; }
  .paths__half { padding-block: 88px 72px; }
  .how-section__inner { padding-block: 80px; }
  .join__inner { padding-block: 80px 96px; gap: 56px; }
}
</style>
