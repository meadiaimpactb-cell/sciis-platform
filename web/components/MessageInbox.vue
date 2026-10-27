<script setup lang="ts">
/**
 * MTG-03 — message inbox: every record thread the viewer can see (owner: their opportunity files; investor: their
 * interests / nominations; staff: by permission), unread first filter, `?thread=` opens one. Used by
 * /app/messages (parties) and /app/admin/messages (staff).
 */
import type { ThreadSummary } from '~/types/collab'
import { toastApiError } from '~/utils/notify'

const props = withDefaults(defineProps<{ staff?: boolean }>(), { staff: false })
const api = useApi()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { relative } = useFormat()
const unread = useMessageUnread()
const prefix = props.staff ? '/admin' : ''

type Meta = { last_page: number; total: number; unread_total: number }
const filter = ref<string>(route.query.unread === '1' ? 'unread' : 'all')
const page = ref(1)
const rows = ref<ThreadSummary[]>([])
const meta = ref<Meta>({ last_page: 1, total: 0, unread_total: 0 })
const loading = ref(false)
const selected = ref<ThreadSummary | null>(null)

const load = async () => {
  loading.value = true
  try {
    const r = await api.get<{ data: ThreadSummary[]; meta?: Partial<Meta> }>(`${prefix}/messages`, { page: page.value, unread: filter.value === 'unread' ? 1 : undefined })
    rows.value = r.data ?? []
    meta.value = { last_page: r.meta?.last_page ?? 1, total: r.meta?.total ?? rows.value.length, unread_total: r.meta?.unread_total ?? 0 }
    const wanted = typeof route.query.thread === 'string' ? route.query.thread : selected.value?.uuid
    if (wanted) selected.value = rows.value.find((s) => s.uuid === wanted) ?? selected.value
  } catch (e) {
    toastApiError(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(filter, () => { page.value = 1; router.replace({ query: { ...route.query, unread: filter.value === 'unread' ? '1' : undefined } }); load() })
watch(page, load)

const open = (s: ThreadSummary) => {
  selected.value = s
  router.replace({ query: { ...route.query, thread: s.uuid } })
}
const onRead = () => {
  const s = selected.value
  if (s && s.unread_count) {
    meta.value.unread_total = Math.max(0, meta.value.unread_total - s.unread_count)
    s.unread_count = 0
  }
  void unread.refresh()
}
const onPosted = () => {
  const s = selected.value
  if (s) s.last_message_at = new Date().toISOString()
}

const tabs = computed(() => [
  { key: 'all', label: t('collab.messages.filters.all') },
  { key: 'unread', label: t('collab.messages.filters.unread'), count: meta.value.unread_total || undefined },
])
/** Link to the record the thread belongs to. */
const recordLink = (s: ThreadSummary) => {
  const sub = s.subject
  if (props.staff) return sub.type === 'interest' ? '/app/admin/interests' : `/app/admin/opportunities/${sub.opportunity_uuid}${sub.type === 'nomination' ? '?tab=nominations' : ''}`
  if (sub.type === 'opportunity') return `/app/entrepreneur/opportunities/${sub.opportunity_uuid}`
  return sub.type === 'interest' ? '/app/investor/interests' : '/app/investor/nominations'
}
</script>

<template>
  <div class="grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)] items-start">
    <section class="card overflow-hidden">
      <div class="p-3 border-b border-border"><Tabs v-model="filter" :tabs="tabs" variant="pills" /></div>
      <p v-if="loading && !rows.length" class="p-5 text-sm text-muted">{{ $t('common.loading') }}</p>
      <div v-else-if="!rows.length" class="p-4"><EmptyState compact :title="$t(filter === 'unread' ? 'collab.messages.noUnread' : 'collab.messages.noThreads')" :text="$t('collab.messages.noThreadsText')" /></div>
      <ul v-else class="divide-y divide-border max-h-[36rem] overflow-y-auto scrollbar-thin">
        <li v-for="s in rows" :key="s.uuid">
          <button type="button" class="w-full text-start px-4 py-3 transition kbd-focus" :class="selected?.uuid === s.uuid ? 'bg-accent/10 shadow-[inset_3px_0_0_rgb(var(--c-accent-hi))] rtl:shadow-[inset_-3px_0_0_rgb(var(--c-accent-hi))]' : 'hover:bg-ink/5'" :aria-current="selected?.uuid === s.uuid ? 'true' : undefined" @click="open(s)">
            <span class="flex items-center justify-between gap-2">
              <span class="text-xs text-muted">{{ $t(`collab.messages.subjects.${s.subject.type}`) }}<template v-if="s.subject.reference_no"> · <span class="num">{{ s.subject.reference_no }}</span></template></span>
              <span v-if="s.unread_count" class="min-w-5 h-5 px-1.5 rounded-full bg-accent text-on-accent text-xs font-semibold flex items-center justify-center num" :aria-label="$t('collab.messages.unreadCount', { n: s.unread_count })">{{ s.unread_count }}</span>
            </span>
            <span class="mt-0.5 block font-medium truncate" :class="s.unread_count ? 'text-ink' : 'text-text'">{{ s.subject.title }}</span>
            <span v-if="s.subject.counterpart" class="block text-xs text-muted truncate">{{ s.subject.counterpart }}</span>
            <span v-if="s.last_message" class="mt-1 flex items-center justify-between gap-2 text-xs text-muted">
              <span class="truncate">{{ s.last_message.author_side === 'staff' ? $t('collab.messages.sides.staff') : $t(`collab.messages.sides.${s.subject.type === 'opportunity' ? 'owner' : 'investor'}`) }}: {{ s.last_message.excerpt }}</span>
              <span class="shrink-0 num">{{ relative(s.last_message.created_at) }}</span>
            </span>
          </button>
        </li>
      </ul>
      <div v-if="meta.last_page > 1" class="border-t border-border px-4 py-3 bg-glass"><Pagination v-model:page="page" :last-page="meta.last_page" :total="meta.total" /></div>
    </section>

    <SectionCard v-if="selected" :key="selected.uuid" :title="selected.subject.title" :sub="selected.subject.counterpart ?? undefined">
      <template #actions><AppButton size="sm" variant="ghost" :to="recordLink(selected)">{{ $t('collab.messages.openRecord') }}</AppButton></template>
      <MessageThread :subject-type="selected.subject.type" :subject-key="selected.subject.key" :staff="staff" @read="onRead" @posted="onPosted" />
    </SectionCard>
    <EmptyState v-else :title="$t('collab.messages.pick')" :text="$t('collab.messages.pickText')" />
  </div>
</template>
