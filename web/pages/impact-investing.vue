<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t, tm, rt } = useI18n()
useHead({ title: t('impactInvesting.metaTitle') })
const chars = ['intent', 'evidence', 'management', 'contribution'] as const
const networks = computed(() => (tm('impactInvesting.networksList') as { name: string; desc: string }[]).map((n) => ({ name: rt(n.name), desc: rt(n.desc) })))
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 lg:py-20 max-w-3xl"><h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t('impactInvesting.title') }}</h1><p class="mt-4 text-white/75 text-lg leading-8">{{ $t('impactInvesting.lead') }}</p></div></section>
    <section class="container-x py-14 grid lg:grid-cols-[1fr_1.2fr] gap-8">
      <article class="card card-pad"><h2 class="text-xl font-semibold">{{ $t('impactInvesting.definition') }}</h2><p class="mt-3 leading-8 text-text/85">{{ $t('impactInvesting.definitionText') }}</p></article>
      <article class="card card-pad"><h2 class="text-xl font-semibold">{{ $t('impactInvesting.impactDef') }}</h2><p class="mt-3 leading-8 text-text/85">{{ $t('impactInvesting.impactDefText') }}</p><PlaceholderImage class="mt-4" ratio="aspect-[21/9]" :intent="$t('impactInvesting.impactDef')" :variant="3" /></article>
    </section>
    <section class="bg-surface border-y border-border"><div class="container-x py-14">
      <h2 class="section-title">{{ $t('impactInvesting.characteristics') }}</h2>
      <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <article v-for="(c, i) in chars" :key="c" class="rounded-xl border border-border p-5 bg-bg/40">
          <span class="num text-2xl font-semibold text-blue">0{{ i + 1 }}</span>
          <h3 class="mt-2 font-semibold">{{ $t(`impactInvesting.chars.${c}.title`) }}</h3>
          <p class="mt-1 text-sm text-muted leading-6">{{ $t(`impactInvesting.chars.${c}.text`) }}</p>
        </article>
      </div>
    </div></section>
    <section class="container-x py-14">
      <h2 class="section-title">{{ $t('impactInvesting.networks') }}</h2>
      <p class="section-sub">{{ $t('impactInvesting.networksText') }}</p>
      <ul class="mt-8 grid md:grid-cols-2 gap-4">
        <li v-for="n in networks" :key="n.name" class="card p-5 flex gap-4"><span class="h-10 w-10 rounded-lg bg-ink text-white flex items-center justify-center shrink-0 text-xs font-bold" aria-hidden="true">●</span><div><h3 class="font-semibold">{{ n.name }}</h3><p class="mt-1 text-sm text-muted leading-6">{{ n.desc }}</p></div></li>
      </ul>
    </section>
  </div>
</template>
