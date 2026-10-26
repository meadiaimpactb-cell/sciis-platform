<script setup lang="ts">
/** AUD-03 / ACC-04 — readable audit action: translated label + raw code, and a one-line summary for
 *  `access.denied` (method, path, route, status) and `*.viewed` (the record that was opened). */
const props = defineProps<{ action: string; after?: Record<string, unknown> | null; auditableType?: string | null; auditableId?: number | string | null; showSubject?: boolean }>()
const { t, te } = useI18n()
const key = computed(() => `admin.audit.actions.${props.action.replace(/\./g, '_')}`)
const text = computed(() => (te(key.value) ? t(key.value) : null))
const denied = computed(() => props.action === 'access.denied')
const str = (v: unknown) => (v == null || v === '' ? null : String(v))
const subjectKey = computed(() => `admin.audit.subjects.${props.auditableType ?? ''}`)
const subject = computed(() => (props.auditableType ? `${te(subjectKey.value) ? t(subjectKey.value) : props.auditableType}${props.auditableId ? ` #${props.auditableId}` : ''}` : null))
</script>

<template>
  <span class="inline-flex flex-col gap-0.5 min-w-0">
    <span class="inline-flex flex-wrap items-center gap-1.5">
      <span v-if="text" class="font-medium" :class="denied && 'text-danger'">{{ text }}</span>
      <code class="text-[11px] ltr inline-block rounded bg-bg px-1.5 py-0.5">{{ action }}</code>
    </span>
    <span v-if="denied && after" class="text-xs text-muted ltr text-start break-all">
      <span class="num font-medium text-danger">{{ str(after.status) ?? '403' }}</span> {{ str(after.method) }} {{ str(after.path) }}<template v-if="str(after.route)"> · {{ str(after.route) }}</template><template v-if="str(after.code) && after.code !== 'forbidden'"> · {{ str(after.code) }}</template>
    </span>
    <span v-else-if="showSubject && subject" class="text-xs text-muted">{{ subject }}</span>
  </span>
</template>
