<script setup lang="ts">
/** Legal page body: placeholder sections marked "بانتظار الاعتماد القانوني" (CMS-01). */
const props = defineProps<{ kind: 'privacy' | 'terms' | 'disclaimer'; version: string }>()
const { tm, rt } = useI18n()
const { date } = useFormat()
const sections = computed(() => (tm(`legal.sections.${props.kind}`) as { h: string; p: string }[]).map((s) => ({ h: rt(s.h), p: rt(s.p) })))
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 max-w-3xl">
      <h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t(`legal.${kind}.title`) }}</h1>
      <p class="mt-3 text-white/60 text-sm">{{ $t('legal.versionLabel', { v: version, date: date(new Date().toISOString()) }) }}</p>
    </div></section>
    <section class="container-x py-12 max-w-3xl">
      <InlineAlert tone="warning" :title="$t('app.pendingLegal')">{{ $t('legal.pendingNote') }}</InlineAlert>
      <article class="card card-pad mt-6 prose-basic">
        <template v-for="s in sections" :key="s.h"><h2>{{ s.h }}</h2><p>{{ s.p }}</p></template>
      </article>
    </section>
  </div>
</template>
