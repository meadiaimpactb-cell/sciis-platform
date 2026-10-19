<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t, tm, rt } = useI18n()
useHead({ title: t('about.metaTitle') })
const goals = computed(() => (tm('about.goalsList') as string[]).map((g) => rt(g)))
const bodies = ['assembly', 'board', 'membership', 'evaluation', 'matching', 'impact', 'advisory', 'secretariat'] as const
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 lg:py-20 max-w-3xl"><h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t('about.title') }}</h1><p class="mt-4 text-white/75 text-lg leading-8">{{ $t('about.lead') }}</p></div></section>
    <section class="container-x py-14 grid lg:grid-cols-3 gap-6">
      <article class="card card-pad"><h2 class="text-xl font-semibold">{{ $t('about.vision') }}</h2><p class="mt-3 text-muted leading-7">{{ $t('about.visionText') }}</p></article>
      <article class="card card-pad"><h2 class="text-xl font-semibold">{{ $t('about.mission') }}</h2><p class="mt-3 text-muted leading-7">{{ $t('about.missionText') }}</p></article>
      <article class="card card-pad">
        <h2 class="text-xl font-semibold">{{ $t('about.goals') }}</h2>
        <ol class="mt-3 space-y-2 text-sm">
          <li v-for="(g, i) in goals" :key="g" class="flex gap-3"><span class="num h-6 w-6 rounded-md bg-blue-soft text-blue text-xs flex items-center justify-center shrink-0">{{ i + 1 }}</span><span class="leading-6">{{ g }}</span></li>
        </ol>
      </article>
    </section>
    <section class="container-x pb-16">
      <div class="card card-pad">
        <h2 class="text-xl font-semibold">{{ $t('about.governance') }}</h2>
        <p class="mt-2 text-muted leading-7 max-w-3xl">{{ $t('about.governanceText') }}</p>
        <div class="mt-6 rounded-xl border border-dashed border-border bg-bg p-6" role="img" :aria-label="$t('about.governancePlaceholder')">
          <p class="text-xs text-muted text-center mb-5">{{ $t('about.governancePlaceholder') }} — {{ $t('app.pendingLegal') }}</p>
          <div class="flex flex-col items-center gap-3">
            <div class="rounded-lg bg-ink text-white px-4 py-2 text-sm">{{ $t(`about.governanceBodies.assembly`) }}</div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="rounded-lg bg-navy text-white px-4 py-2 text-sm">{{ $t(`about.governanceBodies.board`) }}</div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 w-full max-w-3xl">
              <div v-for="b in bodies.slice(2, 7)" :key="b" class="rounded-lg bg-surface border border-border px-3 py-2 text-xs text-center">{{ $t(`about.governanceBodies.${b}`) }}</div>
            </div>
            <div class="h-5 w-px bg-border" aria-hidden="true" />
            <div class="rounded-lg bg-blue-soft text-blue px-4 py-2 text-sm">{{ $t(`about.governanceBodies.secretariat`) }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
