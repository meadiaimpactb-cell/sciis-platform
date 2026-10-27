<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS copy (`impact-investing` page → meta.sections) with the i18n texts as fallback.
const { text, items } = await usePageContent('impact-investing')
const lead = text('lead', 'impactInvesting.lead')
useSeo({ title: t('impactInvesting.metaTitle'), description: lead, jsonLd: { '@type': 'WebPage', name: text('title', 'impactInvesting.title'), description: lead, isPartOf: { '@id': `${useSiteUrl()}/#website` } } })
const chars = computed(() => items<{ title?: string; text?: string }>('characteristics', 'impactInvesting.chars'))
const networks = computed(() => items<{ name?: string; desc?: string }>('networks', 'impactInvesting.networksList'))
</script>

<template>
  <div>
    <PageHero :title="text('title', 'impactInvesting.title')" :lead="text('lead', 'impactInvesting.lead')" />
    <section class="section-y">
      <div class="container-x grid items-start gap-5 lg:grid-cols-2">
        <GlassCard as="article" pad="lg"><h2 class="section-label">{{ $t('impactInvesting.definition') }}</h2><p class="text-desc mt-4 text-t2">{{ text('definition_text', 'impactInvesting.definitionText') }}</p></GlassCard>
        <GlassCard as="article" pad="lg"><h2 class="section-label">{{ $t('impactInvesting.impactDef') }}</h2><p class="text-desc mt-4 text-t2">{{ text('impact_def_text', 'impactInvesting.impactDefText') }}</p><PlaceholderImage class="mt-6" ratio="aspect-[21/9]" :intent="$t('impactInvesting.impactDef')" :variant="3" /></GlassCard>
      </div>
    </section>
    <section class="surface-hero">
      <OrbitBackdrop :orbits="false" />
      <div class="container-x relative section-y">
        <h2 class="section-title">{{ $t('impactInvesting.characteristics') }}</h2>
        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          <GlassCard v-for="(c, i) in chars" :key="i" as="article">
            <span class="num font-display text-3xl font-extrabold text-accent-text">{{ String(i + 1).padStart(2, '0') }}</span>
            <h3 class="mt-5 text-lg font-bold text-ink">{{ c.title }}</h3>
            <p class="mt-2 text-sm leading-6 text-t2">{{ c.text }}</p>
          </GlassCard>
        </div>
      </div>
    </section>
    <section class="section-y">
      <div class="container-x">
        <h2 class="section-title">{{ $t('impactInvesting.networks') }}</h2>
        <p class="section-sub">{{ text('networks_text', 'impactInvesting.networksText') }}</p>
        <ul class="mt-12 grid border-t border-border md:grid-cols-2">
          <li v-for="(n, i) in networks" :key="i" class="flex gap-4 border-b border-border py-8 md:odd:pe-8 md:even:border-s md:even:ps-8"><span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-accent/35 bg-accent/10 text-accent-text" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18" /></svg></span><div><h3 class="card-title">{{ n.name }}</h3><p class="text-desc mt-2 text-muted">{{ n.desc }}</p></div></li>
        </ul>
      </div>
    </section>
  </div>
</template>
