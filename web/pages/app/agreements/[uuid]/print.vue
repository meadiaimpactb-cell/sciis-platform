<script setup lang="ts">
/**
 * AGR-04 — clean print view of one agreement version (staff and the two parties), with the signature certificate
 * (parties, time, method, SHA-256 of the exact text). "Print / Save as PDF" uses the browser — no PDF library.
 * ?version=N (default: current / last approved) · ?lang=both|ar|en
 */
import type { AgreementPartyFile, AgreementSignatureItem, AgreementStaff, AgreementVersionItem } from '~/types/api'

definePageMeta({ layout: false, middleware: ['auth'] })
const api = useApi()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const { dateTime } = useFormat()
const uuid = String(route.params.uuid)

type Doc = { number: string; title: string | null; status: string; opportunity: AgreementStaff['opportunity']; owner: AgreementStaff['owner']; investor: AgreementStaff['investor']; versions: AgreementVersionItem[]; defaultVersion: number | null; signatures: AgreementSignatureItem[]; signedDocument: AgreementStaff['signed_document']; signatureMethod: string | null; backTo: string }
const loadError = ref(false)
const { data: doc, loading } = useAsyncResource<Doc | null>(async () => {
  try {
    if (auth.isStaff) {
      const a = (await api.get<{ data: AgreementStaff }>(`/admin/agreements/${uuid}`)).data
      return { number: a.number, title: a.title, status: a.status, opportunity: a.opportunity, owner: a.owner, investor: a.investor, versions: a.versions, defaultVersion: a.current_version?.version ?? null, signatures: a.signatures, signedDocument: a.signed_document, signatureMethod: a.signature_method, backTo: `/app/admin/agreements/${uuid}` }
    }
    const portal = auth.isEntrepreneur ? 'entrepreneur' : 'investor'
    const a = (await api.get<{ data: AgreementPartyFile }>(`/${portal}/agreements/${uuid}`)).data
    return { number: a.number, title: a.title, status: a.status, opportunity: a.opportunity, owner: a.owner, investor: a.investor, versions: a.versions, defaultVersion: a.version?.version ?? null, signatures: a.signatures, signedDocument: a.signed_document, signatureMethod: a.signature_method, backTo: `/app/${portal}/agreements/${uuid}` }
  } catch { loadError.value = true; return null }
})

const versionNo = computed(() => Number(route.query.version) || doc.value?.defaultVersion || null)
const version = computed(() => doc.value?.versions.find((v) => v.version === versionNo.value) ?? null)
const lang = computed<'both' | 'ar' | 'en'>(() => (['ar', 'en'].includes(String(route.query.lang)) ? (String(route.query.lang) as 'ar' | 'en') : 'both'))
const setLang = (v: string) => router.replace({ query: { ...route.query, lang: v === 'both' ? undefined : v } })
const signatures = computed(() => (doc.value?.signatures ?? []).filter((s) => s.version === versionNo.value))
const wetCopy = computed(() => (doc.value?.signatureMethod === 'wet' && version.value?.version === doc.value?.defaultVersion ? doc.value?.signedDocument : null))
const generatedAt = new Date().toISOString()
watch(doc, (d) => d && useHead({ title: `${d.number} — ${d.title ?? ''}` }))
const print = () => window.print()
</script>

<template>
  <!-- Always the light palette (paper), whatever the site theme: tokens inside this subtree resolve to their light values. -->
  <div data-theme="light" class="min-h-screen bg-bg text-text print-root">
    <div class="no-print sticky top-0 z-10 bg-surface/95 backdrop-blur border-b border-border">
      <div class="mx-auto max-w-[210mm] px-4 py-3 flex flex-wrap items-center gap-3">
        <NuxtLink v-if="doc" :to="localePath(doc.backTo)" class="text-sm text-muted hover:text-accent-text transition">← {{ $t('agreements.print.back') }}</NuxtLink>
        <span class="flex-1" />
        <label class="text-sm text-muted" for="print-lang">{{ $t('agreements.print.language') }}</label>
        <select id="print-lang" class="input w-44 text-sm" :value="lang" @change="setLang(($event.target as HTMLSelectElement).value)">
          <option value="both">{{ $t('agreements.print.both') }}</option>
          <option value="ar">{{ $t('agreements.text.ar') }}</option>
          <option value="en">{{ $t('agreements.text.en') }}</option>
        </select>
        <AppButton size="sm" :disabled="!version" @click="print">{{ $t('agreements.print.button') }}</AppButton>
      </div>
    </div>

    <div v-if="loading && !doc" class="p-10 text-center text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="loadError || !doc || !version" class="mx-auto max-w-[210mm] p-6"><EmptyState :title="$t('agreements.detail.loadFailed')" /></div>
    <article v-else class="paper mx-auto my-6 max-w-[210mm] bg-surface shadow-card border border-border rounded-2xl px-6 py-8 sm:px-10 sm:py-12 text-ink">
      <header class="flex items-start justify-between gap-6 border-b border-border pb-5">
        <BrandLogo size="sm" tone="navy" />
        <dl class="text-xs text-muted text-end space-y-0.5">
          <div><dt class="inline">{{ $t('agreements.columns.number') }}:</dt> <dd class="inline num font-medium text-ink">{{ doc.number }}</dd></div>
          <div><dt class="inline">{{ $t('common.version') }}:</dt> <dd class="inline num font-medium text-ink">v{{ version.version }}</dd></div>
          <div><dt class="inline">{{ $t('common.status') }}:</dt> <dd class="inline font-medium text-ink">{{ $t(`enums.agreementStatus.${doc.status}`) }}</dd></div>
        </dl>
      </header>

      <p v-if="!version.is_approved" class="mt-5 rounded-xl border border-warning/40 bg-warning/10 text-warning px-4 py-2 text-sm text-center font-medium">{{ $t('agreements.print.draft') }}</p>

      <section v-if="lang !== 'en'" class="mt-8"><AgreementText :text="version.body" lang="ar" /></section>
      <section v-if="lang !== 'ar' && version.body_en" class="mt-8" :class="lang === 'both' && 'page-break'"><AgreementText :text="version.body_en" lang="en" /></section>

      <!-- signature certificate -->
      <section class="mt-10 border-t-2 border-navy pt-5 avoid-break" aria-labelledby="cert-title">
        <h2 id="cert-title" class="text-base font-bold text-ink">{{ $t('agreements.print.certificate') }}</h2>
        <table class="mt-3 w-full text-sm border-collapse">
          <tbody>
            <tr v-for="p in (['owner', 'investor'] as const)" :key="p" class="border-b border-border">
              <th class="py-2 text-start font-medium w-36">{{ $t(`enums.agreementParty.${p}`) }}</th>
              <td class="py-2">{{ (p === 'owner' ? doc.owner?.name : doc.investor?.name) ?? '—' }}</td>
              <td class="py-2 text-end num">
                <template v-if="signatures.find((s) => s.party === p)">{{ $t('agreements.signatures.signedAt', { date: dateTime(signatures.find((s) => s.party === p)!.signed_at) }) }} · {{ $t('agreements.signatures.method.electronic') }}</template>
                <template v-else-if="wetCopy">{{ $t('agreements.signatures.method.wet') }}</template>
                <template v-else>{{ $t('agreements.print.unsigned') }}</template>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="mt-3 text-xs text-muted">{{ $t('agreements.text.hash') }}: <span class="ltr inline-block break-all num">{{ version.text_hash }}</span></p>
        <p v-if="version.approved_at" class="mt-1 text-xs text-muted">{{ $t('agreements.party.approvedOn', { date: dateTime(version.approved_at) }) }}</p>
        <p v-if="wetCopy" class="mt-1 text-xs text-muted">{{ $t('agreements.print.wetCopy', { name: wetCopy.title }) }} — SHA-256 <span class="ltr inline-block break-all">{{ wetCopy.sha256 }}</span></p>
        <p class="mt-1 text-xs text-muted">{{ $t('agreements.print.councilNote') }}</p>
        <p class="mt-1 text-xs text-muted num">{{ $t('agreements.print.generatedAt', { date: dateTime(generatedAt) }) }}</p>
      </section>
    </article>
  </div>
</template>

<style scoped>
@page {
  size: A4;
  margin: 16mm 14mm;
}
@media print {
  .no-print { display: none !important; }
  .print-root { background: var(--surface) !important; min-height: 0; }
  .paper { box-shadow: none !important; border: 0 !important; border-radius: 0 !important; margin: 0 !important; padding: 0 !important; max-width: none !important; }
  .page-break { break-before: page; page-break-before: always; }
  .avoid-break { break-inside: avoid; page-break-inside: avoid; }
}
</style>
