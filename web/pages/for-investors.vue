<script setup lang="ts">
definePageMeta({ layout: 'default' })
const { t, tm, rt } = useI18n()
useHead({ title: t('forInvestors.metaTitle') })
const why = computed(() => (tm('forInvestors.why') as { title: string; text: string }[]).map((x) => ({ title: rt(x.title), text: rt(x.text) })))
const journey = computed(() => (tm('forInvestors.journey') as { title: string; text: string }[]).map((x) => ({ title: rt(x.title), text: rt(x.text) })))
const requirements = computed(() => (tm('forInvestors.requirements') as string[]).map((x) => rt(x)))
const benefits = computed(() => (tm('home.benefits') as string[]).map((x) => rt(x)))
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 lg:py-20 grid lg:grid-cols-2 gap-10 items-center">
      <div><h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t('forInvestors.title') }}</h1><p class="mt-4 text-white/75 text-lg leading-8">{{ $t('forInvestors.lead') }}</p><AppButton to="/auth/register" size="lg" class="mt-6">{{ $t('forInvestors.cta') }}</AppButton><Disclaimer variant="dark" class="mt-4" /></div>
      <PlaceholderImage ratio="aspect-[4/3]" :intent="$t('home.heroImages.2')" :variant="2" />
    </div></section>
    <section class="container-x py-14">
      <h2 class="section-title">{{ $t('forInvestors.whyTitle') }}</h2>
      <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-5"><article v-for="w in why" :key="w.title" class="card card-pad"><h3 class="font-semibold">{{ w.title }}</h3><p class="mt-2 text-sm text-muted leading-6">{{ w.text }}</p></article></div>
    </section>
    <section class="bg-surface border-y border-border"><div class="container-x py-14 grid lg:grid-cols-2 gap-10">
      <div>
        <h2 class="section-title">{{ $t('forInvestors.journeyTitle') }}</h2>
        <ol class="mt-6 space-y-4">
          <li v-for="(j, i) in journey" :key="j.title" class="flex gap-4"><span class="num h-9 w-9 rounded-full bg-blue text-white flex items-center justify-center shrink-0">{{ i + 1 }}</span><div><h3 class="font-semibold">{{ j.title }}</h3><p class="text-sm text-muted leading-6">{{ j.text }}</p></div></li>
        </ol>
      </div>
      <div>
        <h2 class="section-title">{{ $t('forInvestors.requirementsTitle') }}</h2>
        <ul class="mt-6 space-y-3"><li v-for="r in requirements" :key="r" class="card p-4 flex gap-3 text-sm"><svg class="h-5 w-5 text-success shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ r }}</li></ul>
      </div>
    </div></section>
    <section class="container-x py-14">
      <h2 class="section-title">{{ $t('home.benefitsTitle') }}</h2>
      <ul class="mt-6 grid sm:grid-cols-2 lg:grid-cols-4 gap-4"><li v-for="b in benefits" :key="b" class="card p-4 text-sm leading-6">{{ b }}</li></ul>
      <AppButton to="/auth/register" size="lg" class="mt-8">{{ $t('forInvestors.cta') }}</AppButton>
    </section>
  </div>
</template>
