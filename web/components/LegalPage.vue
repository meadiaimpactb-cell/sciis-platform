<script setup lang="ts">
/**
 * Legal page (CMS-03): loads the published page from GET /public/pages/{slug}, renders its body (sanitized),
 * shows version + last-updated date, and falls back to the bundled i18n draft when the API has no page.
 */
import type { PublicPage } from '~/types/api'

const props = defineProps<{ kind: 'privacy' | 'terms' | 'disclaimer'; version: string }>()
const { tm, rt, locale } = useI18n()
const { date } = useFormat()
const api = useApi()

const SLUGS: Record<typeof props.kind, string> = { privacy: 'privacy-policy', terms: 'terms', disclaimer: 'disclaimer' }
const SETTINGS_KEYS: Record<typeof props.kind, string> = { privacy: 'privacy_policy', terms: 'terms', disclaimer: 'disclaimer' }

const { data: page } = await useAsyncData(`legal-page-${props.kind}-${locale.value}`, () =>
  api.get<{ data: PublicPage }>(`/public/pages/${SLUGS[props.kind]}`).then((r) => r.data).catch(() => null),
)
const { data: legalVersions } = await useAsyncData('legal-versions', () =>
  api.get<{ data: { legal_versions?: Record<string, string | null> } }>('/settings/public').then((r) => r.data.legal_versions ?? {}).catch(() => ({}) as Record<string, string | null>),
)

const fromCms = computed(() => !!page.value && !!(page.value.body ?? page.value.body_ar))
const html = computed(() => (fromCms.value ? renderCmsBody(page.value?.body ?? page.value?.body_ar) : ''))
const version = computed(() => page.value?.version || legalVersions.value?.[SETTINGS_KEYS[props.kind]] || props.version)
const updatedAt = computed(() => page.value?.updated_at ?? page.value?.published_at ?? null)
const pending = computed(() => !fromCms.value || isPendingLegal(page.value?.body ?? page.value?.body_ar))
const sections = computed(() => (fromCms.value ? [] : (tm(`legal.sections.${props.kind}`) as { h: string; p: string }[]).map((s) => ({ h: rt(s.h), p: rt(s.p) }))))
</script>

<template>
  <div>
    <!-- hero: the PageHero surface; the version line sits in the pill label -->
    <section class="surface-hero">
      <OrbitBackdrop />
      <div class="container-x relative flex flex-col items-center text-center py-20 md:py-24">
        <SectionLabel pill class="num">
          <template v-if="updatedAt">{{ $t('legal.versionLabel', { v: version, date: date(updatedAt) }) }}</template>
          <template v-else>{{ $t('legal.versionOnly', { v: version }) }}</template>
        </SectionLabel>
        <h1 class="display mt-6 max-w-4xl text-ink text-balance">{{ page?.title || $t(`legal.${kind}.title`) }}</h1>
      </div>
    </section>
    <section class="container-x section-y max-w-3xl">
      <InlineAlert v-if="pending" tone="warning" :title="$t('app.pendingLegal')">{{ $t('legal.pendingNote') }}</InlineAlert>
      <!-- eslint-disable-next-line vue/no-v-html -- body is sanitized/escaped by renderCmsBody -->
      <article v-if="fromCms" class="mt-6 prose-basic text-base" v-html="html" />
      <article v-else class="mt-6 prose-basic text-base">
        <template v-for="s in sections" :key="s.h"><h2>{{ s.h }}</h2><p>{{ s.p }}</p></template>
      </article>
      <p class="mt-4 text-xs text-muted num">{{ $t('legal.lastUpdated', { v: version, date: updatedAt ? date(updatedAt) : $t('legal.draftDate') }) }}</p>
    </section>
  </div>
</template>
