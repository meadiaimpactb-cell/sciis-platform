<script setup lang="ts">
/**
 * Admin content (CMS-02..05, BRD-03): pages / FAQs / news (with author) / events, the home hero images editor,
 * cover-image uploads (POST /admin/content/media → WebP) and the contact inbox with the API status vocabulary.
 * Growth content: impact stories, partners (published only with a confirmed display approval) and knowledge resources
 * (external link or a file uploaded through POST /admin/content/files) — these three can also be deleted.
 * Validation mirrors CmsContentRequest through utils/validation.ts (slug, lengths, URLs, event dates).
 */
import { z, type ZodType } from 'zod'
import type { ContentItem, ContactMessage, ContactMessageStatus, ContentFile, ContentImage, HeroImage, Paginated } from '~/types/api'
import { endsAfter, imageFileError, optionalNumber, optionalSlugField, optionalText, optionalUrl, parseApiError, RULES, vmsg } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastInfo, toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t, locale } = useI18n()
useHead({ title: t('admin.content.metaTitle') })
const api = useApi()
const { label } = useEnumLabel()
const { date } = useFormat()

type Kind = 'pages' | 'faqs' | 'news' | 'events' | 'stories' | 'partners' | 'resources' | 'home' | 'sections' | 'messages'
const GROWTH: Kind[] = ['stories', 'partners', 'resources']
const kind = ref<Kind>('pages')
const isGrowth = computed(() => GROWTH.includes(kind.value))
const tabs = [
  ...(['pages', 'faqs', 'news', 'events', 'stories', 'partners', 'resources'] as const).map((k) => ({ key: k, label: t(`admin.content.tabs.${k}`) })),
  { key: 'home', label: t('admin.content.tabs.home') },
  { key: 'sections', label: t('admin.content.tabs.publicPages') },
  { key: 'messages', label: t('admin.content.contactMessages') },
]
const contentKind = computed(() => (kind.value === 'home' || kind.value === 'sections' || kind.value === 'messages' ? 'pages' : kind.value))
/** notify.entities.* key of the current content type — every outcome toast names it. */
const ENTITY: Record<string, string> = { pages: 'page', faqs: 'faq', news: 'news', events: 'event', stories: 'story', partners: 'partner', resources: 'resource' }
const entity = computed(() => t(`notify.entities.${ENTITY[contentKind.value] ?? 'page'}`))
const itemName = (c: ContentItem) => c.title_ar ?? c.title ?? c.slug ?? ''
const items = ref<ContentItem[]>([]); const messages = ref<ContactMessage[]>([]); const loading = ref(false)
const homePage = ref<ContentItem | null>(null)

// ---------------------------------------------------------------- home hero images form (BRD-03)
const altFields = { alt_ar: optionalText(RULES.titleMax), alt_en: optionalText(RULES.titleMax) }
const heroAliases = Object.fromEntries(Array.from({ length: RULES.heroImagesMax }, (_, i) => [[`meta.hero_images.${i}.alt_ar`, `hero_images.${i}.alt_ar`], [`meta.hero_images.${i}.alt_en`, `hero_images.${i}.alt_en`]]).flat())
const heroSchema = z.object({ hero_images: z.array(z.looseObject({ url: z.string(), ...altFields })).refine((a) => a.length <= RULES.heroImagesMax, { error: () => t('admin.content.hero.max') }) })
// typed by the API shape (HeroImage), validated by the schema
const heroForm = useZodForm(
  heroSchema as unknown as ZodType<{ hero_images: HeroImage[] }>,
  { hero_images: [] as HeroImage[] },
  { aliases: heroAliases },
)
const heroImages = computed(() => heroForm.values.hero_images)

const load = async () => {
  if (kind.value === 'sections') return // PageSectionsEditor loads its own data
  loading.value = true
  try {
    if (kind.value === 'messages') messages.value = (await api.get<Paginated<ContactMessage>>('/admin/contact-messages')).data
    else if (kind.value === 'home') {
      const list = (await api.get<{ data: ContentItem[] }>('/admin/content/pages', { slug: 'home' })).data
      homePage.value = list.find((p) => p.slug === 'home') ?? null
      heroForm.reset({ hero_images: (homePage.value?.meta?.hero_images ?? []).map((i) => ({ ...i })) })
    } else items.value = (await api.get<{ data: ContentItem[] }>(`/admin/content/${kind.value}`)).data
  } catch (e) { toastApiError(e) } finally { loading.value = false }
}
onMounted(load); watch(kind, load)

// ---------------------------------------------------------------- item form (CmsContentRequest)
const open = ref(false); const editing = ref<ContentItem | null>(null)
const blank = () => ({
  slug: '', title_ar: '', title_en: '', body_ar: '', body_en: '', is_published: false, category: 'general', starts_at: '', ends_at: '', location: '', registration_url: '', author_name: '', author_title: '', author_bio: '', author_avatar_url: '', author_linkedin_url: '', cover: null as ContentImage | null,
  // growth content
  sort: 0 as number | null, link_url: '', sector_ar: '', sector_en: '', beneficiaries: null as number | null, impact_metric_ar: '', impact_metric_en: '',
  approved_for_display: false, audience: 'all' as 'all' | 'investor' | 'entrepreneur', logo: null as ContentImage | null, file: null as ContentFile | null,
})
const titleLabel = computed(() => (contentKind.value === 'partners' ? t('admin.content.growth.nameAr') : t('admin.content.titleAr')))
const titleEnLabel = computed(() => (contentKind.value === 'partners' ? t('admin.content.growth.nameEn') : t('admin.content.titleEn')))
/** title_ar is required on every type; the message names the field as labelled for the current type (title / partner name). */
const titleArField = optionalText(RULES.titleMax).superRefine((v, ctx) => {
  if (!v?.trim()) ctx.addIssue({ code: 'custom', message: vmsg('requiredNamed', { field: titleLabel.value }) })
})
const imageMeta = z.looseObject(altFields).nullable()
const contentSchema = endsAfter(
    z.object({
      slug: optionalSlugField,
      title_ar: titleArField,
      title_en: optionalText(RULES.titleMax),
      body_ar: z.string().nullish(),
      body_en: z.string().nullish(),
      is_published: z.boolean(),
      category: z.string(),
      starts_at: z.string(),
      ends_at: z.string(),
      location: optionalText(RULES.cmsLocationMax),
      registration_url: optionalUrl(RULES.urlMax),
      author_name: optionalText(RULES.cmsAuthorNameMax),
      author_title: optionalText(RULES.cmsAuthorTitleMax),
      author_bio: optionalText(RULES.noteMax),
      author_avatar_url: optionalText(RULES.cmsUrlMax),
      author_linkedin_url: optionalUrl(RULES.cmsUrlMax),
      cover: imageMeta,
      sort: optionalNumber({ min: 0, int: true }),
      link_url: optionalUrl(RULES.cmsUrlMax),
      sector_ar: optionalText(RULES.cmsSectorMax),
      sector_en: optionalText(RULES.cmsSectorMax),
      beneficiaries: optionalNumber({ min: 0, max: RULES.beneficiariesMax, int: true }),
      impact_metric_ar: optionalText(RULES.titleMax),
      impact_metric_en: optionalText(RULES.titleMax),
      approved_for_display: z.boolean(),
      audience: z.string(),
      logo: imageMeta,
      file: z.unknown(),
    }),
    'starts_at',
    'ends_at',
  )
// typed by the form model (blank()), validated by the schema
const contentForm = useZodForm(
  contentSchema as unknown as ZodType<ReturnType<typeof blank>>,
  blank(),
  { aliases: { 'meta.cover.alt_ar': 'cover.alt_ar', 'meta.cover.alt_en': 'cover.alt_en', 'meta.logo.alt_ar': 'logo.alt_ar', 'meta.logo.alt_en': 'logo.alt_en' } },
)
const form = contentForm.values
const err = computed(() => contentForm.errors.value)
const toLocal = (iso?: string | null) => (iso ? new Date(iso).toISOString().slice(0, 16) : '')
const openNew = () => { editing.value = null; contentForm.reset(); open.value = true }
const openEdit = (c: ContentItem) => {
  editing.value = c
  contentForm.reset({
    slug: c.slug ?? '', title_ar: c.title_ar ?? c.title ?? '', title_en: c.title_en ?? '', body_ar: c.body_ar ?? c.body ?? '', body_en: c.body_en ?? '', is_published: c.is_published, category: c.category ?? 'general',
    starts_at: toLocal(c.starts_at), ends_at: toLocal(c.ends_at), location: c.location ?? '', registration_url: c.registration_url ?? '',
    author_name: c.author_name ?? c.author?.name ?? '', author_title: c.author_title ?? c.author?.title ?? '', author_bio: c.author_bio ?? c.author?.bio ?? '', author_avatar_url: c.author_avatar_url ?? c.author?.avatar_url ?? '', author_linkedin_url: c.author_linkedin_url ?? c.author?.linkedin_url ?? '',
    cover: c.meta?.cover ? { ...c.meta.cover } : null,
    sort: c.sort ?? 0, link_url: c.link_url ?? '', sector_ar: c.sector_ar ?? c.sector ?? '', sector_en: c.sector_en ?? '', beneficiaries: c.beneficiaries ?? null,
    impact_metric_ar: c.impact_metric_ar ?? c.impact_metric ?? '', impact_metric_en: c.impact_metric_en ?? '', approved_for_display: !!c.approved_for_display,
    audience: c.audience ?? 'all', logo: c.meta?.logo ? { ...c.meta.logo } : null, file: c.meta?.file ? { ...c.meta.file } : null,
  })
  open.value = true
}
const nullable = (v?: string | null) => ((v ?? '').trim() === '' ? null : (v ?? '').trim())
const payload = () => {
  const k = kind.value
  const base: Record<string, unknown> = { title_ar: form.title_ar.trim(), title_en: nullable(form.title_en), body_ar: nullable(form.body_ar), body_en: nullable(form.body_en), is_published: form.is_published }
  if (form.slug.trim()) base.slug = form.slug.trim()
  if (k === 'faqs') base.category = form.category
  if (k === 'events') Object.assign(base, { starts_at: nullable(form.starts_at), ends_at: nullable(form.ends_at), location: nullable(form.location), registration_url: nullable(form.registration_url) })
  if (k === 'news') Object.assign(base, { author_name: nullable(form.author_name), author_title: nullable(form.author_title), author_bio: nullable(form.author_bio), author_avatar_url: nullable(form.author_avatar_url), author_linkedin_url: nullable(form.author_linkedin_url) })
  if (k === 'news' || k === 'events') base.meta = { ...(editing.value?.meta ?? {}), cover: form.cover }
  if (GROWTH.includes(k)) Object.assign(base, { link_url: nullable(form.link_url), sort: form.sort ?? 0 })
  if (k === 'stories') {
    Object.assign(base, { sector_ar: nullable(form.sector_ar), sector_en: nullable(form.sector_en), beneficiaries: form.beneficiaries ?? null, impact_metric_ar: nullable(form.impact_metric_ar), impact_metric_en: nullable(form.impact_metric_en) })
    base.meta = { cover: form.cover, logo: form.logo }
  }
  if (k === 'partners') { base.approved_for_display = form.approved_for_display; base.meta = { logo: form.logo } }
  if (k === 'resources') { base.audience = form.audience; base.meta = { file: form.file } }
  return base
}
const save = () => contentForm.submit(async () => {
  if (editing.value) await api.patch(`/admin/content/${kind.value}/${editing.value.id}`, payload())
  else await api.post(`/admin/content/${kind.value}`, payload())
  toastSuccess(t(editing.value ? 'notify.updated' : 'notify.created', { item: entity.value }))
  open.value = false
  await load()
})
const togglePublish = async (c: ContentItem) => {
  try {
    await api.patch(`/admin/content/${kind.value}/${c.id}`, { is_published: !c.is_published })
    const item = `${entity.value} «${itemName(c)}»`
    if (c.is_published) toastInfo(t('notify.unpublished', { item }))
    else toastSuccess(t('notify.published', { item }))
    await load()
  } catch (e) { toastApiError(e) }
}
/** Growth content only (stories / partners / resources): confirmation with the item name, then a named outcome. */
const remove = async (c: ContentItem) => {
  if (!isGrowth.value) return
  const name = itemName(c)
  if (!(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name }), t('notify.deleteConfirm')))) return
  try {
    await api.del(`/admin/content/${kind.value}/${c.id}`)
    toastSuccess(t('notify.deleted', { name }))
    await load()
  } catch (e) {
    const p = parseApiError(e)
    toastError(p.status === 403 || p.status === 422 ? (Object.values(p.errors)[0] ?? p.message) : t('notify.deleteFailed', { name }))
  }
}

// ---------------------------------------------------------------- media upload (WebP server-side)
const uploading = ref(false)
const upload = async (file: File, mime: RegExp = RULES.cmsImageMime): Promise<ContentImage | null> => {
  const problem = imageFileError(file, RULES.cmsImageMaxMb, mime)
  if (problem) { toastError(problem); return null }
  uploading.value = true
  try {
    const fd = new FormData(); fd.append('file', file)
    const res = await api.post<{ data: ContentImage }>('/admin/content/media', fd)
    return res.data
  } catch (e) { const p = parseApiError(e); if (p.status === 422 || (p.status ?? 0) >= 500) toastError(Object.values(p.errors)[0] ?? t('notify.uploadFailed')); else toastApiError(e); return null } finally { uploading.value = false }
}
const coverName = () => t('admin.content.cover.legend')
const logoName = () => t('admin.content.growth.logoLegend')
const fileName = () => t('admin.content.growth.fileLegend')
const onCoverFile = async (f: File) => {
  const img = await upload(f)
  if (!img) return
  form.cover = { ...img, alt_ar: form.cover?.alt_ar ?? form.title_ar, alt_en: form.cover?.alt_en ?? form.title_en }
  toastSuccess(t('notify.uploaded', { item: coverName() }))
}
// Logos keep the partner / story name as alt text by default (never an anonymous logo).
const onLogoFile = async (f: File) => {
  const img = await upload(f, RULES.avatarMime)
  if (!img) return
  form.logo = { ...img, alt_ar: form.logo?.alt_ar || form.title_ar, alt_en: form.logo?.alt_en || form.title_en }
  toastSuccess(t('notify.uploaded', { item: logoName() }))
}
const onResourceFile = async (f: File) => {
  uploading.value = true
  try {
    const fd = new FormData(); fd.append('file', f)
    form.file = (await api.post<{ data: ContentFile }>('/admin/content/files', fd)).data
    toastSuccess(t('notify.uploaded', { item: fileName() }))
  } catch (e) { const p = parseApiError(e); if (p.status === 422 || (p.status ?? 0) >= 500) toastError(Object.values(p.errors)[0] ?? t('notify.uploadFailed')); else toastApiError(e) } finally { uploading.value = false }
}
/** Removing an attachment only changes the draft: confirm with its name, then remind that «Save» applies it. */
const removeAttachment = async (field: 'cover' | 'logo' | 'file') => {
  const name = field === 'cover' ? coverName() : field === 'logo' ? logoName() : fileName()
  if (!(await confirmDialog(t('notify.removeTitle'), t('notify.removeText', { name }), t('notify.removeConfirm')))) return
  form[field] = null
  toastInfo(t('admin.content.pendingSave', { name }))
}
const bodyLabel = computed(() => (isGrowth.value ? t('admin.content.growth.summaryAr') : t('admin.content.bodyAr')))
const bodyEnLabel = computed(() => (isGrowth.value ? t('admin.content.growth.summaryEn') : t('admin.content.bodyEn')))
const partnerBlocked = computed(() => contentKind.value === 'partners' && form.is_published && !form.approved_for_display)
const audiences = computed(() => (['all', 'investor', 'entrepreneur'] as const).map((a) => ({ value: a, label: t(`resources.audience.${a}`) })))

// ---------------------------------------------------------------- home hero editor (BRD-03)
const heroAlt = (img: HeroImage) => (locale.value === 'en' && img.alt_en ? img.alt_en : img.alt_ar) || ''
const heroName = (img: HeroImage, i: number) => heroAlt(img) || `${t('admin.content.hero.image')} ${i + 1}`
const onHeroFile = async (f: File) => {
  if (heroImages.value.length >= RULES.heroImagesMax) return toastError(t('admin.content.hero.max'))
  const img = await upload(f)
  if (!img) return
  heroForm.values.hero_images.push({ ...img, alt_ar: '', alt_en: '' })
  toastSuccess(t('notify.uploaded', { item: t('admin.content.hero.image') }))
}
const moveHero = (i: number, dir: -1 | 1) => { const j = i + dir; const arr = heroForm.values.hero_images; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]] }
const removeHero = async (i: number) => {
  const name = heroName(heroImages.value[i], i)
  if (!(await confirmDialog(t('notify.removeTitle'), t('notify.removeText', { name }), t('notify.removeConfirm')))) return
  heroForm.values.hero_images.splice(i, 1)
  heroForm.clearErrors(['hero_images'])
  toastInfo(t('admin.content.pendingSave', { name }))
}
const saveHero = () => heroForm.submit(async (v) => {
  const meta = { ...(homePage.value?.meta ?? {}), hero_images: v.hero_images.map((i) => ({ url: i.url, path: i.path ?? null, alt_ar: nullable(i.alt_ar), alt_en: nullable(i.alt_en), width: i.width ?? null, height: i.height ?? null })) }
  if (homePage.value) await api.patch(`/admin/content/pages/${homePage.value.id}`, { meta })
  else await api.post('/admin/content/pages', { slug: 'home', title_ar: t('admin.content.tabs.home'), title_en: 'Home', is_published: true, meta })
  toastSuccess(t('notify.updated', { item: t('notify.entities.heroImages') }))
  await load()
})

// ---------------------------------------------------------------- contact inbox
const statuses: ContactMessageStatus[] = ['new', 'in_progress', 'closed']
const setStatus = async (m: ContactMessage, status: ContactMessageStatus, ev: Event) => {
  try {
    await api.patch(`/admin/contact-messages/${m.id}`, { status })
    m.status = status
    toastSuccess(t('notify.statusChangedTo', { item: `${t('notify.entities.contactMessage')} ${m.reference_no ?? ''}`.trim(), status: t(`admin.content.status.${status}`) }))
  } catch (e) {
    ;(ev.target as HTMLSelectElement).value = statuses.includes(m.status as ContactMessageStatus) ? m.status : 'new'
    toastApiError(e)
  }
}
const statusTone = (s: ContactMessage['status']) => (s === 'closed' ? 'approved' : s === 'in_progress' ? 'in_progress' : 'pending')
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.content.title')" :sub="$t('admin.content.sub')"><template #actions><AppButton v-if="kind !== 'messages' && kind !== 'home' && kind !== 'sections'" @click="openNew">{{ $t('admin.content.addItem') }}</AppButton></template></PageHeader>
    <Tabs v-model="kind" :tabs="tabs" variant="pills" class="w-fit mb-5" />
    <div v-if="loading" class="text-muted">{{ $t('common.loading') }}</div>

    <!-- Contact inbox -->
    <div v-else-if="kind === 'messages'" class="card overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('common.reference') }}</th><th>{{ $t('contact.category') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('contact.message') }}</th><th>{{ $t('admin.content.assignedTo') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.date') }}</th></tr></thead><tbody>
      <tr v-for="m in messages" :key="m.id"><td class="num">{{ m.reference_no }}</td><td>{{ m.category_label ?? label('contactCategory', m.category) }}</td><td class="font-medium">{{ m.name }}<span class="block text-xs text-muted ltr text-start">{{ m.email }}</span></td><td class="text-sm max-w-xs">{{ m.message }}</td><td class="text-sm text-muted">{{ m.assigned_to ?? '—' }}</td>
        <td><div class="flex items-center gap-2"><AppBadge kind="generic" :value="statusTone(m.status)" :label="$t(`admin.content.status.${m.status}`)" /><label class="sr-only" :for="`st-${m.id}`">{{ $t('common.status') }}</label><select :id="`st-${m.id}`" class="input h-8 py-0 text-xs w-auto" :value="statuses.includes(m.status as ContactMessageStatus) ? m.status : 'new'" @change="setStatus(m, ($event.target as HTMLSelectElement).value as ContactMessageStatus, $event)"><option v-for="s in statuses" :key="s" :value="s">{{ $t(`admin.content.status.${s}`) }}</option></select></div></td>
        <td class="text-muted">{{ date(m.created_at) }}</td></tr>
      <tr v-if="!messages.length"><td colspan="7" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr>
    </tbody></table></div>

    <!-- Public pages marketing copy (pages.meta.sections) -->
    <PageSectionsEditor v-else-if="kind === 'sections'" />

    <!-- Home hero images (BRD-03) -->
    <div v-else-if="kind === 'home'" class="space-y-5">
      <SectionCard :title="$t('admin.content.hero.title')" :sub="$t('admin.content.hero.sub')">
        <form novalidate @submit.prevent="saveHero">
          <InlineAlert v-if="!homePage" tone="info" class="mb-4">{{ $t('admin.content.hero.noPage') }}</InlineAlert>
          <ul v-if="heroImages.length" class="space-y-4">
            <li v-for="(img, i) in heroImages" :key="img.url" class="grid sm:grid-cols-[12rem_1fr_auto] gap-4 items-start rounded-xl border border-border p-3">
              <img :src="img.url" :alt="heroAlt(img)" :width="img.width ?? 400" :height="img.height ?? 300" loading="lazy" decoding="async" class="aspect-[4/3] w-full rounded-lg object-cover bg-surface">
              <div class="space-y-3 min-w-0">
                <FormField v-model="img.alt_ar" :name="`hero_images.${i}.alt_ar`" :label="$t('admin.content.hero.altAr')" :help="$t('admin.content.hero.altHelp')" :error="heroForm.errors.value[`hero_images.${i}.alt_ar`]" :maxlength="RULES.titleMax" />
                <FormField v-model="img.alt_en" :name="`hero_images.${i}.alt_en`" :label="$t('admin.content.hero.altEn')" :error="heroForm.errors.value[`hero_images.${i}.alt_en`]" :maxlength="RULES.titleMax" ltr />
                <p class="text-xs text-muted num ltr text-start">{{ img.width }}×{{ img.height }} · webp</p>
              </div>
              <div class="flex sm:flex-col gap-1">
                <AppButton size="sm" variant="ghost" icon :disabled="i === 0" :aria-label="$t('admin.content.hero.moveUp')" @click="moveHero(i, -1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 15l-6-6-6 6" /></svg></AppButton>
                <AppButton size="sm" variant="ghost" icon :disabled="i === heroImages.length - 1" :aria-label="$t('admin.content.hero.moveDown')" @click="moveHero(i, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg></AppButton>
                <AppButton size="sm" variant="ghost" icon class="text-danger" :aria-label="`${$t('common.delete')} — ${heroName(img, i)}`" @click="removeHero(i)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></AppButton>
              </div>
            </li>
          </ul>
          <EmptyState v-else compact :title="$t('admin.content.hero.empty')" :text="$t('admin.content.hero.emptyText')" />
          <p v-if="heroForm.errors.value.hero_images" class="error-text mt-2" role="alert">{{ heroForm.errors.value.hero_images }}</p>
          <FileUpload v-if="heroImages.length < RULES.heroImagesMax" class="mt-4" :label="$t('admin.content.hero.upload')" accept=".jpg,.jpeg,.png,.webp,.gif" :max-mb="RULES.cmsImageMaxMb" :disabled="uploading" @file="onHeroFile" @error="toastError($event)" />
          <p v-else class="mt-4 text-xs text-muted">{{ $t('admin.content.hero.max') }}</p>
          <FormAlert :message="heroForm.formError.value" class="mt-4" /><div class="mt-5 flex justify-end"><AppButton type="submit" :loading="heroForm.submitting.value" :disabled="uploading">{{ $t('common.save') }}</AppButton></div>
        </form>
      </SectionCard>
    </div>

    <!-- Content list -->
    <div v-else class="card overflow-x-auto"><table class="table-base"><thead><tr><th>{{ $t('admin.content.slug') }}</th><th>{{ $t('admin.content.titleAr') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.date') }}</th><th></th></tr></thead><tbody><tr v-for="c in items" :key="c.id"><td class="ltr text-start text-xs num">{{ c.slug }}</td><td class="font-medium">{{ c.title_ar ?? c.title }}<span v-if="c.title_en" class="block text-xs text-muted ltr text-start">{{ c.title_en }}</span><span v-if="kind === 'news' && (c.author_name ?? c.author?.name)" class="block text-xs text-teal-text">{{ $t('news.by', { name: c.author_name ?? c.author?.name }) }}</span><span v-if="kind === 'stories' && (c.sector_ar ?? c.sector)" class="block text-xs text-teal-text">{{ c.sector_ar ?? c.sector }}<template v-if="c.beneficiaries != null"> · {{ $t('stories.beneficiaries') }}: <span class="num">{{ c.beneficiaries }}</span></template></span><span v-if="kind === 'partners'" class="mt-1 flex items-center gap-2"><img v-if="c.meta?.logo?.url" :src="c.meta.logo.url" :alt="c.meta.logo.alt_ar ?? c.title_ar" :width="c.meta.logo.width ?? 80" :height="c.meta.logo.height ?? 32" loading="lazy" decoding="async" class="h-6 w-auto max-w-[5rem] object-contain"><AppBadge kind="generic" :value="c.approved_for_display ? 'approved' : 'pending'" :label="c.approved_for_display ? $t('admin.content.growth.approvedBadge') : $t('admin.content.growth.notApprovedBadge')" /></span><span v-if="kind === 'resources'" class="block text-xs text-teal-text">{{ $t(`resources.audience.${c.audience ?? 'all'}`) }}<template v-if="c.meta?.file?.name"> · <span class="ltr">{{ c.meta.file.name }}</span></template></span></td><td><AppBadge kind="generic" :value="c.is_published ? 'approved' : 'pending'" :label="c.is_published ? $t('admin.content.published') : $t('admin.content.draft')" /></td><td class="text-muted">{{ date(c.published_at ?? c.starts_at) }}</td><td class="text-end whitespace-nowrap"><AppButton size="sm" variant="outline" @click="openEdit(c)">{{ $t('common.edit') }}</AppButton> <AppButton size="sm" variant="ghost" @click="togglePublish(c)">{{ c.is_published ? $t('admin.content.unpublish') : $t('admin.content.publish') }}</AppButton><AppButton v-if="isGrowth" size="sm" variant="ghost" class="text-danger" :aria-label="`${$t('common.delete')} — ${itemName(c)}`" @click="remove(c)">{{ $t('common.delete') }}</AppButton></td></tr><tr v-if="!items.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr></tbody></table></div>

    <Modal :open="open" :title="editing ? $t('common.edit') : $t('admin.content.addItem')" size="lg" @close="open = false">
      <form class="space-y-3" novalidate @submit.prevent="save">
        <div class="grid sm:grid-cols-2 gap-3">
          <FormField v-model="form.slug" name="slug" :label="$t('admin.content.slug')" :help="$t('admin.content.slugHelp')" :placeholder="$t('common.optional')" :error="err.slug" :maxlength="RULES.slugMax" ltr autocomplete="off" />
          <FormField v-if="contentKind === 'faqs'" v-model="form.category" name="category" type="select" :label="$t('contact.category')" :options="['general', 'investor', 'entrepreneur', 'membership'].map((c) => ({ value: c, label: $t(`faq.categories.${c}`) }))" :error="err.category" />
          <FormField v-model="form.is_published" name="is_published" type="checkbox" :label="$t('admin.content.publish')" class="self-end" />
        </div>
        <FormField v-model="form.title_ar" name="title_ar" :label="titleLabel" :error="err.title_ar" :maxlength="RULES.titleMax" required />
        <FormField v-model="form.title_en" name="title_en" :label="titleEnLabel" :error="err.title_en" :maxlength="RULES.titleMax" ltr />
        <FormField v-model="form.body_ar" name="body_ar" type="textarea" :label="bodyLabel" :help="isGrowth ? undefined : $t('admin.content.bodyHelp')" :error="err.body_ar" :rows="isGrowth ? 3 : 6" />
        <FormField v-model="form.body_en" name="body_en" type="textarea" :label="bodyEnLabel" :error="err.body_en" :rows="3" ltr />

        <!-- growth content: stories / partners / resources -->
        <template v-if="isGrowth">
          <div class="grid sm:grid-cols-[1fr_8rem] gap-3">
            <FormField v-model="form.link_url" name="link_url" type="url" :label="$t('admin.content.growth.linkUrl')" :help="$t('admin.content.growth.linkHelp')" :error="err.link_url" ltr />
            <FormField v-model="form.sort" name="sort" type="number" :label="$t('admin.content.growth.sort')" :error="err.sort" :min="0" />
          </div>
          <template v-if="contentKind === 'stories'">
            <div class="grid sm:grid-cols-2 gap-3">
              <FormField v-model="form.sector_ar" name="sector_ar" :label="$t('admin.content.growth.sectorAr')" :error="err.sector_ar" :maxlength="RULES.cmsSectorMax" />
              <FormField v-model="form.sector_en" name="sector_en" :label="$t('admin.content.growth.sectorEn')" :error="err.sector_en" :maxlength="RULES.cmsSectorMax" ltr />
              <FormField v-model="form.impact_metric_ar" name="impact_metric_ar" :label="$t('admin.content.growth.impactMetricAr')" :help="$t('admin.content.growth.impactMetricHelp')" :error="err.impact_metric_ar" :maxlength="RULES.titleMax" />
              <FormField v-model="form.impact_metric_en" name="impact_metric_en" :label="$t('admin.content.growth.impactMetricEn')" :error="err.impact_metric_en" :maxlength="RULES.titleMax" ltr />
            </div>
            <FormField v-model="form.beneficiaries" name="beneficiaries" type="number" :label="$t('admin.content.growth.beneficiaries')" :error="err.beneficiaries" :min="0" :max="RULES.beneficiariesMax" class="sm:max-w-xs" />
            <InlineAlert tone="info">{{ $t('admin.content.growth.demoHint') }}</InlineAlert>
          </template>
          <template v-if="contentKind === 'partners'">
            <FormField v-model="form.approved_for_display" name="approved_for_display" type="checkbox" :label="$t('admin.content.growth.approved')" :help="$t('admin.content.growth.approvedHelp')" />
            <InlineAlert v-if="partnerBlocked" tone="warning">{{ $t('admin.content.growth.approvalRequired') }}</InlineAlert>
          </template>
          <FormField v-if="contentKind === 'resources'" v-model="form.audience" name="audience" type="select" :label="$t('admin.content.growth.audience')" :options="audiences" :error="err.audience" class="sm:max-w-xs" />

          <fieldset v-if="contentKind === 'stories' || contentKind === 'partners'" class="rounded-xl border border-border p-4 space-y-3">
            <legend class="px-1 text-sm font-medium">{{ $t('admin.content.growth.logoLegend') }}</legend>
            <div v-if="form.logo" class="grid sm:grid-cols-[8rem_1fr] gap-4 items-start">
              <img :src="form.logo.url" :alt="form.logo.alt_ar ?? ''" :width="form.logo.width ?? 240" :height="form.logo.height ?? 96" loading="lazy" decoding="async" class="h-20 w-full rounded-lg object-contain bg-bg p-2">
              <div class="space-y-3">
                <FormField v-model="form.logo.alt_ar" name="logo.alt_ar" :label="$t('admin.content.hero.altAr')" :error="err['logo.alt_ar']" :maxlength="RULES.titleMax" />
                <FormField v-model="form.logo.alt_en" name="logo.alt_en" :label="$t('admin.content.hero.altEn')" :error="err['logo.alt_en']" :maxlength="RULES.titleMax" ltr />
                <AppButton size="sm" variant="ghost" class="text-danger" @click="removeAttachment('logo')">{{ $t('admin.content.cover.remove') }}</AppButton>
              </div>
            </div>
            <FileUpload v-else :label="$t('admin.content.growth.logoUpload')" accept=".jpg,.jpeg,.png,.webp" :max-mb="RULES.cmsImageMaxMb" :disabled="uploading" @file="onLogoFile" @error="toastError($event)" />
          </fieldset>

          <fieldset v-if="contentKind === 'resources'" class="rounded-xl border border-border p-4 space-y-3">
            <legend class="px-1 text-sm font-medium">{{ $t('admin.content.growth.fileLegend') }}</legend>
            <div v-if="form.file" class="flex flex-wrap items-center justify-between gap-3 text-sm">
              <a :href="form.file.url" target="_blank" rel="noopener" class="link ltr">{{ form.file.name ?? form.file.path }}</a>
              <AppButton size="sm" variant="ghost" class="text-danger" @click="removeAttachment('file')">{{ $t('admin.content.growth.removeFile') }}</AppButton>
            </div>
            <FileUpload v-else :label="$t('admin.content.growth.fileUpload')" accept=".pdf,.docx,.xlsx,.pptx" :max-mb="RULES.cmsFileMaxMb" :disabled="uploading" @file="onResourceFile" @error="toastError($event)" />
          </fieldset>
        </template>

        <template v-if="contentKind === 'events'">
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="form.starts_at" name="starts_at" type="datetime-local" :label="$t('admin.content.event.startsAt')" :error="err.starts_at" ltr />
            <FormField v-model="form.ends_at" name="ends_at" type="datetime-local" :label="$t('admin.content.event.endsAt')" :error="err.ends_at" ltr />
          </div>
          <FormField v-model="form.location" name="location" :label="$t('events.where')" :error="err.location" :maxlength="RULES.cmsLocationMax" />
          <FormField v-model="form.registration_url" name="registration_url" type="url" :label="$t('admin.content.event.registrationUrl')" :error="err.registration_url" ltr />
        </template>

        <fieldset v-if="contentKind === 'news'" class="rounded-xl border border-border p-4 space-y-3">
          <legend class="px-1 text-sm font-medium">{{ $t('admin.content.author.legend') }}</legend>
          <p class="text-xs text-muted -mt-1">{{ $t('admin.content.author.help') }}</p>
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="form.author_name" name="author_name" :label="$t('admin.content.author.name')" :error="err.author_name" :maxlength="RULES.cmsAuthorNameMax" />
            <FormField v-model="form.author_title" name="author_title" :label="$t('admin.content.author.jobTitle')" :error="err.author_title" :maxlength="RULES.cmsAuthorTitleMax" />
          </div>
          <FormField v-model="form.author_bio" name="author_bio" type="textarea" :label="$t('admin.content.author.bio')" :error="err.author_bio" :maxlength="RULES.noteMax" :rows="3" />
          <div class="grid sm:grid-cols-2 gap-3">
            <FormField v-model="form.author_avatar_url" name="author_avatar_url" type="url" :label="$t('admin.content.author.avatarUrl')" :error="err.author_avatar_url" ltr />
            <FormField v-model="form.author_linkedin_url" name="author_linkedin_url" type="url" :label="$t('admin.content.author.linkedin')" :error="err.author_linkedin_url" ltr />
          </div>
        </fieldset>

        <fieldset v-if="contentKind === 'news' || contentKind === 'events' || contentKind === 'stories'" class="rounded-xl border border-border p-4 space-y-3">
          <legend class="px-1 text-sm font-medium">{{ $t('admin.content.cover.legend') }}</legend>
          <div v-if="form.cover" class="grid sm:grid-cols-[10rem_1fr] gap-4 items-start">
            <img :src="form.cover.url" :alt="form.cover.alt_ar ?? ''" :width="form.cover.width ?? 400" :height="form.cover.height ?? 225" loading="lazy" decoding="async" class="aspect-video w-full rounded-lg object-cover bg-surface">
            <div class="space-y-3">
              <FormField v-model="form.cover.alt_ar" name="cover.alt_ar" :label="$t('admin.content.hero.altAr')" :error="err['cover.alt_ar']" :maxlength="RULES.titleMax" />
              <FormField v-model="form.cover.alt_en" name="cover.alt_en" :label="$t('admin.content.hero.altEn')" :error="err['cover.alt_en']" :maxlength="RULES.titleMax" ltr />
              <AppButton size="sm" variant="ghost" class="text-danger" @click="removeAttachment('cover')">{{ $t('admin.content.cover.remove') }}</AppButton>
            </div>
          </div>
          <FileUpload v-else :label="$t('admin.content.cover.upload')" accept=".jpg,.jpeg,.png,.webp,.gif" :max-mb="RULES.cmsImageMaxMb" :disabled="uploading" @file="onCoverFile" @error="toastError($event)" />
        </fieldset>
      </form>
      <template #footer><FormAlert :message="contentForm.formError.value" class="basis-full" /><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="contentForm.submitting.value" :disabled="uploading || partnerBlocked" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>
