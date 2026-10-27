<script setup lang="ts">
/**
 * AGR-04/05 — a party's agreement file (read-only): the approved text (Arabic / English), both parties' signature
 * status, click-to-sign with an explicit consent (SignAgreementRequest: consent accepted + the version number and
 * SHA-256 shown, refused with 409 if the text changed), the signed copy and the print view.
 */
import { z } from 'zod'
import type { AgreementPartyFile } from '~/types/api'
import { acceptField } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'

const props = defineProps<{ portal: 'investor' | 'entrepreneur'; uuid: string }>()
const { t, locale } = useI18n()
const api = useApi()
const localePath = useLocalePath()
const { dateTime } = useFormat()
const base = computed(() => `/${props.portal}/agreements/${props.uuid}`)

const loadError = ref(false)
const { data: a, loading, refresh } = useAsyncResource(async () => {
  loadError.value = false
  try { return (await api.get<{ data: AgreementPartyFile }>(base.value)).data } catch { loadError.value = true; return null }
})
watch(a, (v) => v && useHead({ title: `${v.number} — ${v.title ?? ''}` }))

const shownNo = ref<number | null>(null)
watch(a, (v) => { if (v) shownNo.value = v.version?.version ?? null }, { immediate: true })
const shown = computed(() => a.value?.versions.find((v) => v.version === shownNo.value) ?? a.value?.version ?? null)
const lang = ref<'ar' | 'en'>(locale.value === 'en' ? 'en' : 'ar')
const langTabs = computed(() => [{ key: 'ar', label: t('agreements.text.ar') }, { key: 'en', label: t('agreements.text.en') }])
const revising = computed(() => !!a.value && ['draft', 'legal_review', 'changes_requested'].includes(a.value.status))
const openPrint = () => window.open(localePath(`/app/agreements/${props.uuid}/print${shown.value ? `?version=${shown.value.version}` : ''}`), '_blank', 'noopener')
const partyName = (p: 'owner' | 'investor') => (p === 'owner' ? a.value?.owner?.name : a.value?.investor?.name) ?? '—'

// ---------------------------------------------------------------- sign (AGR-05)
const signForm = useZodForm(z.object({ consent: acceptField }), { consent: false })
const sign = () => signForm.submit(async () => {
  const v = a.value?.version
  if (!a.value || !v) return
  if (!(await confirmDialog(t('agreements.sign.confirmTitle'), t('agreements.sign.confirmText', { number: a.value.number, version: v.version }), t('agreements.sign.confirm')))) return
  try {
    const r = await api.post<{ data: AgreementPartyFile }>(`${base.value}/sign`, { consent: true, version: v.version, text_hash: v.text_hash, locale: locale.value === 'en' ? 'en' : 'ar' })
    if (r.data.redirect_url) { window.location.assign(r.data.redirect_url); return }
    a.value = r.data
    signForm.reset({ consent: false })
    toastSuccess(t(r.data.status === 'signed' ? 'agreements.toast.signedBoth' : 'agreements.toast.signed', { number: r.data.number }))
  } catch (e) {
    if ((e as { status?: number }).status === 409) { toastError(t('agreements.sign.textChanged')); await refresh(); return }
    throw e
  }
})

const downloading = ref(false)
const downloadSigned = async () => {
  downloading.value = true
  try {
    const r = await api.get<{ data: { url: string } }>(`${base.value}/signed-copy/download-url`)
    window.open(r.data.url, '_blank', 'noopener')
    toastSuccess(t('agreements.toast.downloadOpened', { name: a.value?.signed_document?.title ?? a.value?.number ?? '' }))
  } catch (e) { toastApiError(e) } finally { downloading.value = false }
}
</script>

<template>
  <div>
    <div v-if="loading && !a" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="loadError || !a" :title="$t('agreements.detail.loadFailed')"><AppButton size="sm" variant="secondary" :to="`/app/${portal}/agreements`">{{ $t('agreements.party.title') }}</AppButton></EmptyState>
    <template v-else>
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath(`/app/${portal}/agreements`)" class="hover:text-accent-text transition">{{ $t('agreements.party.title') }}</NuxtLink> / <span class="num">{{ a.number }}</span></nav>

      <header class="card card-pad">
        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="agreement" :value="a.status" :soft="false" size="md" /><span class="text-sm text-muted num">{{ a.number }}</span><span v-if="a.version" class="text-sm text-muted num">· v{{ a.version.version }}</span></div>
            <h1 class="mt-2 font-display text-2xl md:text-3xl font-extrabold text-ink">{{ a.title }}</h1>
            <p class="text-sm text-muted">{{ a.opportunity?.name }}<template v-if="a.my_party"> · {{ $t('agreements.party.youAre', { party: $t(`enums.agreementParty.${a.my_party}`) }) }}</template></p>
          </div>
          <div class="flex flex-wrap gap-2 shrink-0">
            <AppButton v-if="a.signed_document" variant="secondary" :loading="downloading" @click="downloadSigned">{{ $t('agreements.actions.downloadSigned') }}</AppButton>
            <AppButton variant="ghost" @click="openPrint">{{ $t('agreements.actions.print') }}</AppButton>
          </div>
        </div>
        <div class="mt-5 border-t border-border pt-4"><AgreementFlow :status="a.status" /></div>
        <InlineAlert v-if="a.status === 'cancelled'" tone="danger" class="mt-4">{{ $t('agreements.party.cancelled') }}</InlineAlert>
        <InlineAlert v-else-if="revising" tone="info" class="mt-4">{{ $t('agreements.party.revising') }}</InlineAlert>
        <InlineAlert v-else-if="a.status === 'signed' || a.status === 'archived'" tone="success" class="mt-4">{{ $t('agreements.party.signedReadOnly') }}</InlineAlert>
      </header>

      <div class="mt-6 grid lg:grid-cols-[1fr_340px] gap-5 items-start">
        <SectionCard :title="shown ? $t('agreements.text.version', { n: shown.version }) : $t('agreements.tabs.text')" :sub="shown?.approved_at ? $t('agreements.party.approvedOn', { date: dateTime(shown.approved_at) }) : undefined">
          <template #actions>
            <select v-if="a.versions.length > 1" v-model.number="shownNo" class="input w-44 text-sm" :aria-label="$t('agreements.tabs.versions')"><option v-for="v in a.versions" :key="v.id" :value="v.version">{{ $t('agreements.text.version', { n: v.version }) }}</option></select>
          </template>
          <template v-if="shown">
            <Tabs v-model="lang" :tabs="langTabs" variant="pills" class="mb-4" />
            <div class="rounded-2xl border border-border bg-bg/40 p-5 lg:p-8">
              <AgreementText v-if="lang === 'ar'" :text="shown.body" lang="ar" :highlight="false" />
              <AgreementText v-else-if="shown.body_en" :text="shown.body_en" lang="en" :highlight="false" />
              <p v-else class="text-sm text-muted">{{ $t('agreements.text.noEn') }}</p>
            </div>
            <p class="mt-3 text-xs text-muted">{{ $t('agreements.text.hash') }}: <code class="ltr inline-block break-all num">{{ shown.text_hash }}</code></p>
          </template>
        </SectionCard>

        <div class="space-y-4 lg:sticky lg:top-20">
          <!-- AGR-05 click-to-sign -->
          <section v-if="a.can_sign" class="card card-pad border-accent/60 ring-1 ring-accent/30" aria-labelledby="sign-title">
            <h2 id="sign-title" class="card-title">{{ $t('agreements.sign.title') }}</h2>
            <p class="mt-1 text-sm text-muted leading-6">{{ $t('agreements.sign.intro', { version: a.version?.version ?? '' }) }}</p>
            <form class="mt-4 space-y-4" novalidate @submit.prevent="sign">
              <FormField v-model="signForm.values.consent" name="consent" type="checkbox" :label="a.consent_text ?? $t('agreements.sign.consentFallback')" :error="signForm.errors.value.consent" required />
              <FormAlert :message="signForm.formError.value" />
              <AppButton type="submit" block :loading="signForm.submitting.value">{{ $t('agreements.sign.button') }}</AppButton>
            </form>
            <p class="mt-3 text-xs text-muted leading-5">{{ $t('agreements.sign.evidence') }}</p>
          </section>

          <SectionCard :title="$t('agreements.tabs.signatures')">
            <ul class="space-y-3">
              <li v-for="p in (['owner', 'investor'] as const)" :key="p" class="flex items-start gap-3">
                <!-- same marks as DualConsent: accent check once the party signed, a clock while waiting -->
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border" :class="a.parties_status[p].signed || a.signed_document ? 'border-accent bg-accent text-on-accent' : 'border-ink/20 text-t3'" aria-hidden="true">
                  <svg v-if="a.parties_status[p].signed || a.signed_document" class="h-4 w-4" viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8" /><path d="M12 8v4l2.5 2" /></svg>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-xs text-muted">{{ $t(`enums.agreementParty.${p}`) }}</p>
                  <p class="font-medium text-ink truncate">{{ partyName(p) }}</p>
                  <p v-if="a.parties_status[p].signed" class="text-xs text-accent-text num">{{ $t('agreements.signatures.signedAt', { date: dateTime(a.parties_status[p].signed_at) }) }}</p>
                  <p v-else-if="a.signed_document" class="text-xs text-accent-text">{{ $t('agreements.signatures.method.wet') }}</p>
                  <p v-else class="text-xs text-muted">{{ a.status === 'sent_for_signature' ? $t('agreements.signatures.pending') : '—' }}</p>
                </div>
              </li>
            </ul>
            <div v-for="s in a.signatures.filter((x) => x.party === a?.my_party && x.ip)" :key="s.id" class="mt-4 rounded-xl border border-border bg-glass p-3 text-xs text-muted space-y-1">
              <p class="font-medium text-ink">{{ $t('agreements.party.yourEvidence') }}</p>
              <p class="num">{{ dateTime(s.signed_at) }} · <span class="ltr inline-block">{{ s.ip }}</span></p>
              <p class="break-all"><code class="ltr inline-block">{{ s.text_hash }}</code></p>
            </div>
          </SectionCard>
        </div>
      </div>
    </template>
  </div>
</template>
