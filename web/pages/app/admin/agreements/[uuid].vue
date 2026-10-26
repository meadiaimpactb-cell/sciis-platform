<script setup lang="ts">
/**
 * AGR-02..05 — agreement file (staff): text by version and language, versions + line-level compare, legal review
 * (approve / request changes, four-eyes on the API), send for signature, both parties' signature status and evidence,
 * wet-signed PDF upload, archive / cancel, audit trail, print view. Buttons follow `allowed_actions` from the API.
 * ReviseAgreementRequest: body 20..100000, body_en ≤100000, change_note 3..2000 · Review note 3..2000 (changes) ·
 * Cancel reason 3..2000 · signed copy: PDF only.
 */
import { z } from 'zod'
import type { AgreementAction, AgreementCompare, AgreementStaff, AgreementVersionItem } from '~/types/api'
import { optionalText, requiredString, RULES } from '~/utils/validation'
import { confirmDialog, toastApiError, toastSuccess } from '~/utils/notify'

definePageMeta({ layout: 'portal', middleware: ['role'] })
const { t, te, locale } = useI18n()
const api = useApi()
const route = useRoute()
const localePath = useLocalePath()
const { date, dateTime, fileSize } = useFormat()
const uuid = route.params.uuid as string
const TEXT_MAX = 100000

const loadError = ref(false)
const { data: a, loading, refresh } = useAsyncResource(async () => {
  loadError.value = false
  try { return (await api.get<{ data: AgreementStaff }>(`/admin/agreements/${uuid}`)).data } catch { loadError.value = true; return null }
})
watch(a, (v) => v && useHead({ title: `${v.number} — ${v.title ?? ''}` }))
const can = (action: AgreementAction) => !!a.value?.allowed_actions.includes(action)
const current = computed(() => a.value?.versions.find((v) => v.version === a.value?.current_version?.version) ?? a.value?.versions[0] ?? null)

const tab = ref((route.query.tab as string) ?? 'text')
const tabs = computed(() => [
  { key: 'text', label: t('agreements.tabs.text') },
  { key: 'versions', label: t('agreements.tabs.versions'), count: a.value?.versions.length },
  { key: 'compare', label: t('agreements.tabs.compare') },
  { key: 'reviews', label: t('agreements.tabs.reviews'), count: a.value?.reviews.length },
  { key: 'signatures', label: t('agreements.tabs.signatures'), count: a.value?.signatures.length },
  { key: 'audit', label: t('agreements.tabs.audit') },
])

// ---------------------------------------------------------------- text by version / language
const shownVersion = ref<number | null>(null)
watch(a, (v) => { if (v && (shownVersion.value === null || !v.versions.some((x) => x.version === shownVersion.value))) shownVersion.value = v.current_version?.version ?? null }, { immediate: true })
const shown = computed<AgreementVersionItem | null>(() => a.value?.versions.find((v) => v.version === shownVersion.value) ?? current.value)
const lang = ref<'ar' | 'en'>('ar')
const langTabs = computed(() => [{ key: 'ar', label: t('agreements.text.ar') }, { key: 'en', label: t('agreements.text.en') }])
const showVersion = (n: number) => { shownVersion.value = n; tab.value = 'text' }
const placeholderLabel = (key: string) => (te(`agreements.placeholders.${key}`) ? t(`agreements.placeholders.${key}`) : key)
const openPrint = (n?: number) => window.open(localePath(`/app/agreements/${uuid}/print${n ? `?version=${n}` : ''}`), '_blank', 'noopener')

// ---------------------------------------------------------------- compare (AGR-02)
const cmpFrom = ref<number | ''>('')
const cmpTo = ref<number | ''>('')
const diff = ref<AgreementCompare | null>(null)
const comparing = ref(false)
watch(a, (v) => {
  if (!v || v.versions.length < 2) return
  const nums = v.versions.map((x) => x.version).sort((x, y) => y - x)
  if (!cmpTo.value || !nums.includes(Number(cmpTo.value))) cmpTo.value = nums[0]
  if (!cmpFrom.value || !nums.includes(Number(cmpFrom.value))) cmpFrom.value = nums[1]
}, { immediate: true })
const loadCompare = async () => {
  if (!cmpFrom.value || !cmpTo.value || cmpFrom.value === cmpTo.value) { diff.value = null; return }
  comparing.value = true
  try { diff.value = (await api.get<{ data: AgreementCompare }>(`/admin/agreements/${uuid}/compare`, { from: cmpFrom.value, to: cmpTo.value })).data } catch (e) { toastApiError(e) } finally { comparing.value = false }
}
watch([cmpFrom, cmpTo, tab], () => { if (tab.value === 'compare') loadCompare() })
const compareWithPrevious = (n: number) => { cmpFrom.value = n - 1; cmpTo.value = n; tab.value = 'compare' }
const versionOptions = computed(() => (a.value?.versions ?? []).map((v) => ({ value: v.version, label: `${t('agreements.text.version', { n: v.version })}${v.is_approved ? ` — ${t('agreements.versions.approved')}` : ''}` })))

// ---------------------------------------------------------------- actions
const busy = ref<string | null>(null)
const run = async (key: string, fn: () => Promise<unknown>, ok: () => string) => {
  busy.value = key
  try { await fn(); await refresh(); toastSuccess(ok()) } catch (e) { toastApiError(e) } finally { busy.value = null }
}
const num = computed(() => a.value?.number ?? '')

// edit → new version (AGR-02)
const editOpen = ref(false)
const editForm = useZodForm(
  z.object({ body: requiredString('agreements.edit.bodyAr', 20, TEXT_MAX), body_en: optionalText(TEXT_MAX), change_note: requiredString('agreements.edit.changeNote', 3, RULES.noteMax) }),
  { body: '', body_en: '', change_note: '' },
)
const resetsApproval = computed(() => a.value?.status === 'approved' || a.value?.status === 'sent_for_signature')
const openEdit = () => { editForm.reset({ body: current.value?.body ?? '', body_en: current.value?.body_en ?? '', change_note: '' }); editOpen.value = true }
const unfilled = computed(() => {
  const keys = new Set<string>()
  for (const m of `${editForm.values.body}\n${editForm.values.body_en ?? ''}`.matchAll(/\{\{?\s*([a-z][a-z0-9_]*)\s*\}?\}/gi)) keys.add(m[1].toLowerCase())
  return [...keys].map(placeholderLabel).join(locale.value === 'ar' ? '، ' : ', ')
})
const saveEdit = () => editForm.submit(async (v) => {
  if (resetsApproval.value && !(await confirmDialog(t('agreements.edit.resetTitle'), t('agreements.edit.resetText', { number: num.value }), t('agreements.edit.save')))) return
  const r = await api.post<{ data: AgreementStaff }>(`/admin/agreements/${uuid}/versions`, { body: v.body, body_en: v.body_en?.trim() || undefined, change_note: v.change_note.trim() })
  editOpen.value = false
  a.value = r.data
  shownVersion.value = r.data.current_version?.version ?? null
  toastSuccess(t('agreements.toast.versionSaved', { version: r.data.current_version?.version ?? '', number: num.value }))
})

// submit for legal review (AGR-03)
const submitOpen = ref(false)
const submitForm = useZodForm(z.object({ note: optionalText(RULES.noteMax) }), { note: '' })
const openSubmit = () => { submitForm.reset(); submitOpen.value = true }
const submitReview = () => submitForm.submit(async (v) => {
  await api.post(`/admin/agreements/${uuid}/submit-review`, { note: v.note?.trim() || undefined })
  submitOpen.value = false
  await refresh()
  toastSuccess(t('agreements.toast.submitted', { number: num.value }))
})

// approve (agreements.approve) / request changes
const approve = async () => {
  const v = current.value?.version ?? ''
  if (!(await confirmDialog(t('agreements.review.approveTitle'), t('agreements.review.approveText', { version: v, number: num.value }), t('agreements.actions.approve')))) return
  await run('approve', () => api.post(`/admin/agreements/${uuid}/review`, { decision: 'approved' }), () => t('agreements.toast.approved', { version: v, number: num.value }))
}
const changesOpen = ref(false)
const changesForm = useZodForm(z.object({ note: requiredString('agreements.review.changesNote', 3, RULES.noteMax) }), { note: '' })
const openChanges = () => { changesForm.reset(); changesOpen.value = true }
const requestChanges = () => changesForm.submit(async (v) => {
  await api.post(`/admin/agreements/${uuid}/review`, { decision: 'changes_requested', note: v.note.trim() })
  changesOpen.value = false
  await refresh()
  toastSuccess(t('agreements.toast.changesRequested', { number: num.value }))
})

// send for signature (AGR-05)
const sendForSignature = async () => {
  if (!(await confirmDialog(t('agreements.send.confirmTitle'), t('agreements.send.confirmText', { number: num.value }), t('agreements.actions.sendForSignature')))) return
  await run('send', () => api.post(`/admin/agreements/${uuid}/send-for-signature`), () => t('agreements.toast.sent', { number: num.value }))
}

// wet-signed PDF (AGR-05 alternative)
const uploadOpen = ref(false)
const uploadFile = ref<File | null>(null)
const uploadError = ref('')
const openUpload = () => { uploadFile.value = null; uploadError.value = ''; uploadOpen.value = true }
const pickFile = (f: File) => {
  uploadError.value = ''
  if (f.type !== 'application/pdf' && !/\.pdf$/i.test(f.name)) { uploadError.value = t('agreements.upload.pdfOnly'); uploadFile.value = null; return }
  uploadFile.value = f
}
const upload = async () => {
  if (!uploadFile.value) { uploadError.value = t('agreements.upload.required'); return }
  if (!(await confirmDialog(t('agreements.upload.confirmTitle'), t('agreements.upload.confirmText', { number: num.value, name: uploadFile.value.name }), t('agreements.upload.submit')))) return
  const fd = new FormData()
  fd.append('file', uploadFile.value)
  busy.value = 'upload'
  try {
    await api.post(`/admin/agreements/${uuid}/signed-copy`, fd)
    uploadOpen.value = false
    await refresh()
    toastSuccess(t('agreements.toast.uploaded', { number: num.value }))
  } catch (e) {
    const p = e as { errors?: Record<string, string[]> }
    if (p.errors?.file?.[0]) uploadError.value = p.errors.file[0]
    else toastApiError(e)
  } finally { busy.value = null }
}
const downloadSigned = async () => {
  busy.value = 'download'
  try {
    const r = await api.get<{ data: { url: string } }>(`/admin/agreements/${uuid}/signed-copy/download-url`)
    window.open(r.data.url, '_blank', 'noopener')
    toastSuccess(t('agreements.toast.downloadOpened', { name: a.value?.signed_document?.title ?? num.value }))
  } catch (e) { toastApiError(e) } finally { busy.value = null }
}

// archive / cancel (AGR-04)
const archive = async () => {
  if (!(await confirmDialog(t('agreements.archive.confirmTitle'), t('agreements.archive.confirmText', { number: num.value }), t('agreements.actions.archive')))) return
  await run('archive', () => api.post(`/admin/agreements/${uuid}/archive`), () => t('agreements.toast.archived', { number: num.value }))
}
const cancelOpen = ref(false)
const cancelForm = useZodForm(z.object({ reason: requiredString('agreements.cancel.reason', 3, RULES.noteMax) }), { reason: '' })
const openCancel = () => { cancelForm.reset(); cancelOpen.value = true }
const cancelAgreement = () => cancelForm.submit(async (v) => {
  if (!(await confirmDialog(t('agreements.cancel.confirmTitle'), t('agreements.cancel.confirmText', { number: num.value }), t('agreements.actions.cancel')))) return
  await api.post(`/admin/agreements/${uuid}/cancel`, { reason: v.reason.trim() })
  cancelOpen.value = false
  await refresh()
  toastSuccess(t('agreements.toast.cancelled', { number: num.value }))
})

const auditLabel = (action: string) => { const k = `agreements.audit.${action.replace(/\./g, '_')}`; return te(k) ? t(k) : action }
const partyName = (p: 'owner' | 'investor') => (p === 'owner' ? a.value?.owner?.name : a.value?.investor?.name) ?? '—'
</script>

<template>
  <div>
    <div v-if="loading && !a" class="text-muted">{{ $t('common.loading') }}</div>
    <EmptyState v-else-if="loadError || !a" :title="$t('agreements.detail.loadFailed')"><AppButton size="sm" variant="secondary" to="/app/admin/agreements">{{ $t('agreements.title') }}</AppButton></EmptyState>
    <template v-else>
      <nav class="text-xs text-muted mb-3" aria-label="breadcrumb"><NuxtLink :to="$localePath('/app/admin/agreements')" class="hover:text-blue">{{ $t('agreements.title') }}</NuxtLink> / <span class="num">{{ a.number }}</span></nav>

      <header class="card card-pad">
        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2"><AppBadge kind="agreement" :value="a.status" :soft="false" size="md" /><span class="text-sm text-muted num">{{ a.number }}</span><span v-if="a.current_version" class="text-sm text-muted num">· v{{ a.current_version.version }}</span></div>
            <h1 class="mt-2 text-2xl font-semibold">{{ a.title }}</h1>
            <p v-if="a.opportunity" class="text-sm text-muted"><NuxtLink :to="$localePath(`/app/admin/opportunities/${a.opportunity.uuid}`)" class="hover:text-blue"><span class="num">{{ a.opportunity.reference_no ?? '' }}</span> {{ a.opportunity.name }}</NuxtLink></p>
          </div>
          <div class="flex flex-wrap gap-2 shrink-0">
            <AppButton v-if="can('edit')" variant="secondary" @click="openEdit">{{ $t('agreements.actions.edit') }}</AppButton>
            <AppButton v-if="can('submit_review')" @click="openSubmit">{{ $t('agreements.actions.submitReview') }}</AppButton>
            <AppButton v-if="can('approve')" :loading="busy === 'approve'" @click="approve">{{ $t('agreements.actions.approve') }}</AppButton>
            <AppButton v-if="can('request_changes')" variant="secondary" @click="openChanges">{{ $t('agreements.actions.requestChanges') }}</AppButton>
            <AppButton v-if="can('send_for_signature')" :loading="busy === 'send'" @click="sendForSignature">{{ $t('agreements.actions.sendForSignature') }}</AppButton>
            <AppButton v-if="can('upload_signed')" variant="secondary" @click="openUpload">{{ $t('agreements.actions.uploadSigned') }}</AppButton>
            <AppButton v-if="can('archive')" :loading="busy === 'archive'" @click="archive">{{ $t('agreements.actions.archive') }}</AppButton>
            <AppButton v-if="a.signed_document" variant="secondary" :loading="busy === 'download'" @click="downloadSigned">{{ $t('agreements.actions.downloadSigned') }}</AppButton>
            <AppButton variant="ghost" @click="openPrint()">{{ $t('agreements.actions.print') }}</AppButton>
            <AppButton v-if="can('cancel')" variant="ghost" class="text-danger" @click="openCancel">{{ $t('agreements.actions.cancel') }}</AppButton>
          </div>
        </div>
        <div class="mt-5 border-t border-border pt-4"><AgreementFlow :status="a.status" /></div>
        <dl class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
          <div><dt class="text-xs text-muted">{{ $t('enums.agreementParty.owner') }}</dt><dd class="font-medium">{{ a.owner?.name ?? '—' }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('enums.agreementParty.investor') }}</dt><dd class="font-medium">{{ a.investor?.name ?? '—' }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('agreements.detail.template') }}</dt><dd class="font-medium">{{ a.template ? `${a.template.title} (v${a.template.version})` : '—' }}</dd></div>
          <div><dt class="text-xs text-muted">{{ $t('agreements.detail.createdBy') }}</dt><dd class="font-medium">{{ a.created_by?.name ?? '—' }} <span class="text-xs text-muted num">· {{ date(a.created_at) }}</span></dd></div>
          <div v-if="a.approved_at"><dt class="text-xs text-muted">{{ $t('agreements.detail.approvedBy') }}</dt><dd class="font-medium">{{ a.approved_by?.name ?? '—' }} <span class="text-xs text-muted num">· {{ date(a.approved_at) }}</span></dd></div>
          <div v-if="a.sent_for_signature_at"><dt class="text-xs text-muted">{{ $t('agreements.detail.sentAt') }}</dt><dd class="font-medium num">{{ dateTime(a.sent_for_signature_at) }}</dd></div>
          <div v-if="a.signed_at"><dt class="text-xs text-muted">{{ $t('agreements.detail.signedAt') }}</dt><dd class="font-medium"><span class="num">{{ dateTime(a.signed_at) }}</span> <span class="text-xs text-muted">· {{ a.signature_method ? $t(`agreements.signatures.method.${a.signature_method}`) : '' }}</span></dd></div>
          <div v-if="a.archived_at"><dt class="text-xs text-muted">{{ $t('agreements.detail.archivedAt') }}</dt><dd class="font-medium num">{{ dateTime(a.archived_at) }}</dd></div>
        </dl>
        <InlineAlert v-if="a.status === 'cancelled'" tone="danger" class="mt-4" :title="$t('agreements.detail.cancelledAt', { date: dateTime(a.cancelled_at) })">{{ a.cancel_reason }}</InlineAlert>
        <InlineAlert v-else-if="a.status === 'changes_requested' && a.reviews[0]" tone="warning" class="mt-4" :title="$t('agreements.detail.changesRequested', { name: a.reviews[0].reviewer?.name ?? '—' })">{{ a.reviews[0].note }}</InlineAlert>
        <InlineAlert v-else-if="a.status === 'signed' || a.status === 'archived'" tone="success" class="mt-4">{{ $t('agreements.detail.readOnly') }}</InlineAlert>
      </header>

      <Tabs v-model="tab" :tabs="tabs" class="mt-6" />
      <div class="mt-5">
        <!-- TEXT -->
        <SectionCard v-show="tab === 'text'" :title="shown ? $t('agreements.text.version', { n: shown.version }) : $t('agreements.tabs.text')" :sub="shown ? `${dateTime(shown.created_at)}${shown.proposed_by ? ` · ${$t('agreements.versions.by', { name: shown.proposed_by.name })}` : ''}` : undefined">
          <template #actions>
            <select v-if="a.versions.length > 1" v-model.number="shownVersion" class="input w-48 text-sm" :aria-label="$t('agreements.tabs.versions')"><option v-for="o in versionOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select>
          </template>
          <template v-if="shown">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
              <Tabs v-model="lang" :tabs="langTabs" variant="pills" />
              <div class="flex flex-wrap items-center gap-2"><AppBadge v-if="shown.is_approved" kind="agreement" value="approved" :label="$t('agreements.versions.approved')" /><AppBadge v-if="shown.version === a.current_version?.version" kind="generic" value="new" :label="$t('agreements.versions.current')" /><AppButton size="sm" variant="ghost" @click="openPrint(shown.version)">{{ $t('agreements.actions.print') }}</AppButton></div>
            </div>
            <p v-if="shown.change_note" class="mb-4 text-sm rounded-lg bg-bg p-3"><span class="text-muted">{{ $t('agreements.versions.note') }}:</span> {{ shown.change_note }}</p>
            <div class="rounded-xl border border-border bg-surface p-5 lg:p-8">
              <AgreementText v-if="lang === 'ar'" :text="shown.body" lang="ar" />
              <AgreementText v-else-if="shown.body_en" :text="shown.body_en" lang="en" />
              <p v-else class="text-sm text-muted">{{ $t('agreements.text.noEn') }}</p>
            </div>
            <p class="mt-3 text-xs text-muted">{{ $t('agreements.text.hash') }}: <code class="ltr inline-block break-all num">{{ shown.text_hash }}</code></p>
            <details v-if="shown.placeholders && Object.keys(shown.placeholders).length" class="mt-3 text-sm">
              <summary class="cursor-pointer text-blue">{{ $t('agreements.text.values') }}</summary>
              <KeyValue class="mt-2" :cols="2" :items="Object.entries(shown.placeholders).map(([k, v]) => ({ label: placeholderLabel(k), value: v || '—' }))" />
            </details>
          </template>
        </SectionCard>

        <!-- VERSIONS -->
        <SectionCard v-show="tab === 'versions'" :title="$t('agreements.tabs.versions')" :sub="$t('agreements.versions.sub')" flush>
          <div class="overflow-x-auto">
            <table class="table-base">
              <thead><tr><th>{{ $t('common.version') }}</th><th>{{ $t('common.createdAt') }}</th><th>{{ $t('agreements.versions.author') }}</th><th>{{ $t('agreements.versions.note') }}</th><th>{{ $t('agreements.versions.review') }}</th><th><span class="sr-only">{{ $t('common.actions') }}</span></th></tr></thead>
              <tbody>
                <tr v-for="v in a.versions" :key="v.id" class="align-top">
                  <td class="num font-medium whitespace-nowrap">v{{ v.version }}<span v-if="v.version === a.current_version?.version" class="ms-1 text-[10px] rounded-full bg-blue-soft text-blue px-1.5">{{ $t('agreements.versions.current') }}</span></td>
                  <td class="num whitespace-nowrap text-muted">{{ dateTime(v.created_at) }}</td>
                  <td>{{ v.proposed_by?.name ?? '—' }}</td>
                  <td class="min-w-[12rem]">{{ v.change_note ?? '—' }}</td>
                  <td><AppBadge v-if="v.review" kind="agreement" :value="v.review.decision === 'approved' ? 'approved' : 'changes_requested'" /><span v-else class="text-xs text-muted">—</span></td>
                  <td class="whitespace-nowrap"><div class="flex gap-1 justify-end"><AppButton size="sm" variant="ghost" @click="showVersion(v.version)">{{ $t('common.view') }}</AppButton><AppButton v-if="v.version > 1" size="sm" variant="outline" @click="compareWithPrevious(v.version)">{{ $t('agreements.versions.compare') }}</AppButton></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </SectionCard>

        <!-- COMPARE (AGR-02) -->
        <SectionCard v-show="tab === 'compare'" :title="$t('agreements.compare.title')" :sub="$t('agreements.compare.sub')">
          <p v-if="a.versions.length < 2" class="text-sm text-muted">{{ $t('agreements.compare.needTwo') }}</p>
          <template v-else>
            <div class="flex flex-wrap items-end gap-3 mb-4">
              <div><label class="label" for="cmp-from">{{ $t('agreements.compare.from') }}</label><select id="cmp-from" v-model.number="cmpFrom" class="input w-44"><option v-for="o in versionOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
              <div><label class="label" for="cmp-to">{{ $t('agreements.compare.to') }}</label><select id="cmp-to" v-model.number="cmpTo" class="input w-44"><option v-for="o in versionOptions" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
              <p v-if="diff" class="text-sm pb-2"><span class="text-success font-medium num">+{{ diff.added }}</span> · <span class="text-danger font-medium num">−{{ diff.removed }}</span> <span class="text-muted">{{ $t('agreements.compare.lines') }}</span></p>
            </div>
            <p v-if="cmpFrom === cmpTo" class="text-sm text-muted">{{ $t('agreements.compare.pickTwo') }}</p>
            <div v-else-if="comparing && !diff" class="text-sm text-muted">{{ $t('common.loading') }}</div>
            <div v-else-if="diff" class="space-y-5">
              <div><h3 class="font-semibold mb-2">{{ $t('agreements.text.ar') }}</h3><AgreementDiff :part="diff.body" lang="ar" :caption="$t('agreements.text.ar')" /></div>
              <div><h3 class="font-semibold mb-2">{{ $t('agreements.text.en') }}</h3><AgreementDiff :part="diff.body_en" lang="en" :caption="$t('agreements.text.en')" /></div>
            </div>
          </template>
        </SectionCard>

        <!-- REVIEWS (AGR-03) -->
        <SectionCard v-show="tab === 'reviews'" :title="$t('agreements.tabs.reviews')" :sub="$t('agreements.review.sub')">
          <p v-if="!a.reviews.length" class="text-sm text-muted">{{ $t('agreements.review.empty') }}</p>
          <ol v-else class="space-y-3">
            <li v-for="r in a.reviews" :key="r.id" class="rounded-lg border border-border p-4">
              <div class="flex flex-wrap items-center gap-2"><AppBadge kind="agreement" :value="r.decision === 'approved' ? 'approved' : 'changes_requested'" :label="$t(`agreements.review.decision.${r.decision}`)" /><span class="text-sm font-medium">{{ r.reviewer?.name ?? '—' }}</span><span class="text-xs text-muted num">· v{{ r.version }} · {{ dateTime(r.created_at) }}</span></div>
              <p v-if="r.note" class="mt-2 text-sm leading-6 whitespace-pre-line">{{ r.note }}</p>
            </li>
          </ol>
        </SectionCard>

        <!-- SIGNATURES (AGR-05) -->
        <div v-show="tab === 'signatures'" class="space-y-5">
          <div class="grid md:grid-cols-2 gap-4">
            <div v-for="p in (['owner', 'investor'] as const)" :key="p" class="card card-pad">
              <p class="text-xs text-muted">{{ $t(`enums.agreementParty.${p}`) }}</p>
              <p class="mt-1 font-semibold">{{ partyName(p) }}</p>
              <p class="mt-2 text-sm" :class="a.parties_status[p].signed ? 'text-success' : 'text-muted'">{{ a.parties_status[p].signed ? $t('agreements.signatures.signedAt', { date: dateTime(a.parties_status[p].signed_at) }) : a.status === 'sent_for_signature' ? $t('agreements.signatures.pending') : '—' }}</p>
            </div>
          </div>
          <SectionCard v-if="a.signed_document" :title="$t('agreements.signatures.wetTitle')">
            <p class="text-sm"><span class="font-medium">{{ a.signed_document.title }}</span> <span class="text-xs text-muted num">· {{ fileSize(a.signed_document.size_bytes) }} · {{ dateTime(a.signed_document.uploaded_at) }}</span></p>
            <p class="mt-1 text-xs text-muted">SHA-256: <code class="ltr inline-block break-all">{{ a.signed_document.sha256 }}</code></p>
            <AppButton class="mt-3" size="sm" variant="secondary" :loading="busy === 'download'" :disabled="!a.signed_document.downloadable" @click="downloadSigned">{{ $t('agreements.actions.downloadSigned') }}</AppButton>
          </SectionCard>
          <SectionCard :title="$t('agreements.signatures.evidence')" :sub="$t('agreements.signatures.evidenceSub')" flush>
            <p v-if="!a.signatures.length" class="px-5 py-4 text-sm text-muted">{{ $t('agreements.signatures.none') }}</p>
            <div v-else class="overflow-x-auto">
              <table class="table-base">
                <thead><tr><th>{{ $t('agreements.signatures.party') }}</th><th>{{ $t('agreements.signatures.signer') }}</th><th>{{ $t('common.version') }}</th><th>{{ $t('agreements.signatures.time') }}</th><th>{{ $t('agreements.signatures.ip') }}</th><th>{{ $t('agreements.signatures.device') }}</th><th>{{ $t('agreements.signatures.hash') }}</th></tr></thead>
                <tbody>
                  <tr v-for="s in a.signatures" :key="s.id" class="align-top">
                    <td class="whitespace-nowrap">{{ $t(`enums.agreementParty.${s.party}`) }}</td>
                    <td>{{ s.signer_name }}<span v-if="s.signer_email" class="block text-xs text-muted ltr text-start">{{ s.signer_email }}</span></td>
                    <td class="num">v{{ s.version }}</td>
                    <td class="num whitespace-nowrap">{{ dateTime(s.signed_at) }}<span class="block text-xs text-muted">{{ s.provider }}</span></td>
                    <td class="num ltr text-start">{{ s.ip ?? '—' }}</td>
                    <td class="text-xs text-muted max-w-[14rem] break-words ltr text-start">{{ s.user_agent ?? '—' }}</td>
                    <td class="text-xs"><code class="ltr inline-block break-all max-w-[10rem]">{{ s.text_hash }}</code></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </SectionCard>
        </div>

        <!-- AUDIT (AGR-04) -->
        <SectionCard v-show="tab === 'audit'" :title="$t('agreements.tabs.audit')" :sub="$t('agreements.auditSub')">
          <p v-if="!a.audit.length" class="text-sm text-muted">{{ $t('common.noData') }}</p>
          <ol v-else class="relative border-s border-border ms-2 space-y-4">
            <li v-for="e in a.audit" :key="e.id" class="ps-5 relative">
              <span class="absolute -start-[5px] top-1.5 h-2.5 w-2.5 rounded-full bg-teal" aria-hidden="true" />
              <p class="text-sm"><span class="font-medium">{{ auditLabel(e.action) }}</span> <span class="text-muted">— {{ e.user?.name ?? $t('agreements.auditSystem') }}</span></p>
              <p class="text-xs text-muted num">{{ dateTime(e.created_at) }}<template v-if="e.after?.version"> · v{{ e.after.version }}</template><template v-if="e.after?.party"> · {{ $t(`enums.agreementParty.${e.after.party}`) }}</template></p>
              <p v-if="e.after?.note || e.after?.reason" class="mt-1 text-sm text-text/85">{{ e.after.note ?? e.after.reason }}</p>
            </li>
          </ol>
        </SectionCard>
      </div>
    </template>

    <!-- edit → new version -->
    <Modal :open="editOpen" :title="$t('agreements.edit.title')" size="lg" @close="editOpen = false">
      <form class="space-y-4" novalidate @submit.prevent="saveEdit">
        <p class="text-sm text-muted">{{ $t('agreements.edit.hint') }}</p>
        <InlineAlert v-if="resetsApproval" tone="warning">{{ $t('agreements.edit.resetWarning') }}</InlineAlert>
        <FormField v-model="editForm.values.body" name="body" type="textarea" :label="$t('agreements.edit.bodyAr')" :rows="14" :maxlength="TEXT_MAX" :error="editForm.errors.value.body" required />
        <div>
          <label class="label" for="agr-body-en">{{ $t('agreements.edit.bodyEn') }} <span class="text-xs text-muted font-normal ms-1">({{ $t('common.optional') }})</span></label>
          <textarea id="agr-body-en" v-model="editForm.values.body_en" name="body_en" dir="ltr" lang="en" rows="10" :maxlength="TEXT_MAX" class="input resize-y ltr text-start" :class="editForm.errors.value.body_en && 'input-error'" :aria-invalid="!!editForm.errors.value.body_en" @blur="editForm.validateField('body_en')" />
          <p v-if="editForm.errors.value.body_en" class="error-text" role="alert">{{ editForm.errors.value.body_en }}</p>
        </div>
        <InlineAlert v-if="unfilled" tone="info">{{ $t('agreements.edit.unfilled', { fields: unfilled }) }}</InlineAlert>
        <FormField v-model="editForm.values.change_note" name="change_note" :label="$t('agreements.edit.changeNote')" :help="$t('agreements.edit.changeNoteHint')" :maxlength="RULES.noteMax" :error="editForm.errors.value.change_note" required />
      </form>
      <template #footer><FormAlert :message="editForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="editOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="editForm.submitting.value" @click="saveEdit">{{ $t('agreements.edit.save') }}</AppButton></template>
    </Modal>

    <!-- submit for review -->
    <Modal :open="submitOpen" :title="$t('agreements.review.submitTitle')" @close="submitOpen = false">
      <form class="space-y-3" novalidate @submit.prevent="submitReview">
        <p class="text-sm text-muted">{{ $t('agreements.review.submitText', { version: a?.current_version?.version ?? '' }) }}</p>
        <FormField v-model="submitForm.values.note" name="note" type="textarea" :rows="3" :label="$t('agreements.review.note')" :maxlength="RULES.noteMax" :error="submitForm.errors.value.note" />
      </form>
      <template #footer><FormAlert :message="submitForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="submitOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="submitForm.submitting.value" @click="submitReview">{{ $t('agreements.actions.submitReview') }}</AppButton></template>
    </Modal>

    <!-- request changes -->
    <Modal :open="changesOpen" :title="$t('agreements.actions.requestChanges')" @close="changesOpen = false">
      <form class="space-y-3" novalidate @submit.prevent="requestChanges">
        <FormField v-model="changesForm.values.note" name="note" type="textarea" :rows="4" :label="$t('agreements.review.changesNote')" :help="$t('agreements.review.changesHint')" :maxlength="RULES.noteMax" :error="changesForm.errors.value.note" required />
      </form>
      <template #footer><FormAlert :message="changesForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="changesOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="changesForm.submitting.value" @click="requestChanges">{{ $t('agreements.actions.requestChanges') }}</AppButton></template>
    </Modal>

    <!-- wet-signed PDF -->
    <Modal :open="uploadOpen" :title="$t('agreements.upload.title')" @close="uploadOpen = false">
      <div class="space-y-3">
        <p class="text-sm text-muted">{{ $t('agreements.upload.hint') }}</p>
        <FileUpload accept=".pdf,application/pdf" :error="uploadError" @file="pickFile" @error="(m: string) => (uploadError = m)" />
        <p v-if="uploadFile" class="text-sm"><span class="font-medium">{{ uploadFile.name }}</span> <span class="text-xs text-muted num">· {{ fileSize(uploadFile.size) }}</span></p>
      </div>
      <template #footer><AppButton variant="ghost" @click="uploadOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy === 'upload'" :disabled="!uploadFile" @click="upload">{{ $t('agreements.upload.submit') }}</AppButton></template>
    </Modal>

    <!-- cancel -->
    <Modal :open="cancelOpen" :title="$t('agreements.actions.cancel')" @close="cancelOpen = false">
      <form class="space-y-3" novalidate @submit.prevent="cancelAgreement">
        <FormField v-model="cancelForm.values.reason" name="reason" type="textarea" :rows="3" :label="$t('agreements.cancel.reason')" :maxlength="RULES.noteMax" :error="cancelForm.errors.value.reason" required />
      </form>
      <template #footer><FormAlert :message="cancelForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="cancelOpen = false">{{ $t('common.cancel') }}</AppButton><AppButton variant="danger" :loading="cancelForm.submitting.value" @click="cancelAgreement">{{ $t('agreements.actions.cancel') }}</AppButton></template>
    </Modal>
  </div>
</template>
