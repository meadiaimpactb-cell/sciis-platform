<script setup lang="ts">
/** Launch checklist (BKP-01/03, NTF-02, ACC-01/05) — GET /admin/system-status → {checks: {key, ok, value}[]}. */
type Check = { key: string; ok: boolean; value: string | null }
const api = useApi()
const { dateTime } = useFormat()
const { data, loading, refresh } = useAsyncResource(() => api.get<{ data: { checks: Check[]; checked_at: string } }>('/admin/system-status').then((r) => r.data))
const isDate = (v: string | null) => !!v && /^\d{4}-\d{2}-\d{2}T/.test(v)
</script>

<template>
  <SectionCard :title="$t('admin.system.title')" :sub="$t('admin.system.sub')" flush>
    <template #actions><AppButton size="sm" variant="ghost" :loading="loading" @click="refresh">{{ $t('admin.system.recheck') }}</AppButton></template>
    <p v-if="loading && !data" class="p-5 text-muted">{{ $t('common.loading') }}</p>
    <ul v-else-if="data" class="divide-y divide-border">
      <li v-for="c in data.checks" :key="c.key" class="flex items-start gap-3 px-5 py-4">
        <span class="mt-0.5 h-6 w-6 shrink-0 rounded-full flex items-center justify-center" :class="c.ok ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'" aria-hidden="true">
          <svg v-if="c.ok" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
          <svg v-else class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v7a1 1 0 11-2 0V4a1 1 0 011-1zm0 11.5a1.25 1.25 0 110 2.5 1.25 1.25 0 010-2.5z" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="font-medium">
            {{ $t(`admin.system.checks.${c.key}.title`) }}
            <span class="sr-only">— {{ c.ok ? $t('admin.system.ok') : $t('admin.system.attention') }}</span>
          </p>
          <p class="text-sm text-muted mt-0.5">{{ $t(c.ok ? `admin.system.checks.${c.key}.ok` : `admin.system.checks.${c.key}.fix`) }}</p>
          <p v-if="c.value" class="text-xs text-muted mt-1 ltr text-start num" dir="ltr">{{ isDate(c.value) ? dateTime(c.value) : c.value }}</p>
        </div>
      </li>
    </ul>
  </SectionCard>
</template>
