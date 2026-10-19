<script setup lang="ts">
import type { ContentItem, ContactMessage, Paginated } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['role'], roles: [] })
const { t } = useI18n()
useHead({ title: t('admin.content.metaTitle') })
const api = useApi()
const toast = useToast()
const { label } = useEnumLabel()
const { date } = useFormat()
const kind = ref<'pages' | 'faqs' | 'news' | 'events' | 'messages'>('pages')
const tabs = [...(['pages', 'faqs', 'news', 'events'] as const).map((k) => ({ key: k, label: t(`admin.content.tabs.${k}`) })), { key: 'messages', label: t('admin.content.contactMessages') }]
const items = ref<ContentItem[]>([]); const messages = ref<ContactMessage[]>([]); const loading = ref(false)
const load = async () => { loading.value = true; try { if (kind.value === 'messages') messages.value = (await api.get<Paginated<ContactMessage>>('/admin/contact-messages')).data; else items.value = (await api.get<{ data: ContentItem[] }>(`/admin/content/${kind.value}`)).data } finally { loading.value = false } }
onMounted(load); watch(kind, load)
const open = ref(false); const editing = ref<ContentItem | null>(null)
const form = reactive({ slug: '', title_ar: '', title_en: '', body_ar: '', body_en: '', is_published: false, category: 'general' })
const openNew = () => { editing.value = null; Object.assign(form, { slug: '', title_ar: '', title_en: '', body_ar: '', body_en: '', is_published: false, category: 'general' }); open.value = true }
const openEdit = (c: ContentItem) => { editing.value = c; Object.assign(form, { slug: c.slug, title_ar: c.title_ar, title_en: c.title_en ?? '', body_ar: c.body_ar ?? '', body_en: c.body_en ?? '', is_published: c.is_published, category: c.category ?? 'general' }); open.value = true }
const busy = ref(false)
const save = async () => { busy.value = true; try { if (editing.value) await api.patch(`/admin/content/${kind.value}/${editing.value.id}`, form); else await api.post(`/admin/content/${kind.value}`, form); open.value = false; toast.success(t('common.saved')); await load() } catch (e) { toast.error((e as Error).message) } finally { busy.value = false } }
const togglePublish = async (c: ContentItem) => { await api.patch(`/admin/content/${kind.value}/${c.id}`, { is_published: !c.is_published }); await load() }
</script>
<template>
  <div>
    <PageHeader :title="$t('admin.content.title')" :sub="$t('admin.content.sub')"><template #actions><AppButton v-if="kind !== 'messages'" @click="openNew">{{ $t('admin.content.addItem') }}</AppButton></template></PageHeader>
    <Tabs v-model="kind" :tabs="tabs" variant="pills" class="w-fit mb-5" />
    <div v-if="loading" class="text-muted">{{ $t('common.loading') }}</div>
    <div v-else-if="kind === 'messages'" class="card overflow-hidden"><table class="table-base"><thead><tr><th>{{ $t('common.reference') }}</th><th>{{ $t('contact.category') }}</th><th>{{ $t('common.name') }}</th><th>{{ $t('contact.message') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.date') }}</th></tr></thead><tbody><tr v-for="m in messages" :key="m.id"><td class="num">{{ m.reference_no }}</td><td>{{ label('contactCategory', m.category) }}</td><td class="font-medium">{{ m.name }}<span class="block text-xs text-muted ltr text-start">{{ m.email }}</span></td><td class="text-sm max-w-xs">{{ m.message }}</td><td><AppBadge kind="generic" :value="m.status" /></td><td class="text-muted">{{ date(m.created_at) }}</td></tr></tbody></table></div>
    <div v-else class="card overflow-hidden"><table class="table-base"><thead><tr><th>{{ $t('admin.content.slug') }}</th><th>{{ $t('admin.content.titleAr') }}</th><th>{{ $t('common.status') }}</th><th>{{ $t('common.date') }}</th><th></th></tr></thead><tbody><tr v-for="c in items" :key="c.id"><td class="ltr text-start text-xs num">{{ c.slug }}</td><td class="font-medium">{{ c.title_ar }}<span v-if="c.title_en" class="block text-xs text-muted ltr text-start">{{ c.title_en }}</span></td><td><AppBadge kind="generic" :value="c.is_published ? 'approved' : 'pending'" :label="c.is_published ? $t('admin.content.published') : $t('admin.content.draft')" /></td><td class="text-muted">{{ date(c.published_at ?? c.starts_at) }}</td><td class="text-end whitespace-nowrap"><AppButton size="sm" variant="outline" @click="openEdit(c)">{{ $t('common.edit') }}</AppButton> <AppButton v-if="kind !== 'faqs'" size="sm" variant="ghost" @click="togglePublish(c)">{{ c.is_published ? $t('admin.content.unpublish') : $t('admin.content.publish') }}</AppButton></td></tr><tr v-if="!items.length"><td colspan="5" class="text-center text-muted py-6">{{ $t('common.noData') }}</td></tr></tbody></table></div>
    <Modal :open="open" :title="editing ? $t('common.edit') : $t('admin.content.addItem')" size="lg" @close="open = false">
      <div class="space-y-3">
        <div class="grid sm:grid-cols-2 gap-3"><FormField v-if="kind !== 'faqs'" v-model="form.slug" name="slug" :label="$t('admin.content.slug')" ltr /><FormField v-else v-model="form.category" name="category" type="select" :label="$t('contact.category')" :options="['general', 'investor', 'entrepreneur', 'membership'].map((c) => ({ value: c, label: $t(`faq.categories.${c}`) }))" /><FormField v-model="form.is_published" name="published" type="checkbox" :label="$t('admin.content.publish')" class="self-end" /></div>
        <FormField v-model="form.title_ar" name="title_ar" :label="$t('admin.content.titleAr')" required />
        <FormField v-model="form.title_en" name="title_en" :label="$t('admin.content.titleEn')" ltr />
        <FormField v-model="form.body_ar" name="body_ar" type="textarea" :label="$t('admin.content.bodyAr')" :rows="5" />
        <FormField v-model="form.body_en" name="body_en" type="textarea" :label="$t('admin.content.bodyEn')" :rows="3" ltr />
      </div>
      <template #footer><AppButton variant="ghost" @click="open = false">{{ $t('common.cancel') }}</AppButton><AppButton :loading="busy" :disabled="!form.title_ar" @click="save">{{ $t('common.save') }}</AppButton></template>
    </Modal>
  </div>
</template>
