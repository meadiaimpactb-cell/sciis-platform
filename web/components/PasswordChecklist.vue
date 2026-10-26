<script setup lang="ts">
/**
 * Live password policy checklist (ACC-06) — shown under every field that SETS a password (register, reset,
 * account password change). Rules come from `passwordChecks()` in utils/validation.ts (same policy as the API).
 */
import { passwordChecks } from '~/utils/validation'

const props = defineProps<{ password?: string | null }>()
const items = computed(() => {
  const c = passwordChecks(props.password ?? '')
  return (['length', 'upper', 'lower', 'number', 'symbol'] as const).map((key) => ({ key, ok: c[key] }))
})
const done = computed(() => items.value.filter((i) => i.ok).length)
</script>

<template>
  <div class="mt-2" aria-live="polite">
    <div class="h-1 rounded-full bg-border overflow-hidden" aria-hidden="true">
      <div class="h-full rounded-full transition-all duration-300" :class="done === items.length ? 'bg-success' : done >= 3 ? 'bg-warning' : 'bg-danger'" :style="{ width: `${(done / items.length) * 100}%` }" />
    </div>
    <ul class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1 text-xs">
      <li v-for="i in items" :key="i.key" class="flex items-center gap-1.5" :class="i.ok ? 'text-success' : 'text-muted'">
        <svg v-if="i.ok" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
        <span v-else class="h-3.5 w-3.5 shrink-0 rounded-full border-2 border-current opacity-60" aria-hidden="true" />
        <span>{{ $t(`validation.passwordChecks.${i.key}`) }}</span>
        <span class="sr-only">{{ i.ok ? $t('validation.passwordChecks.met') : $t('validation.passwordChecks.notMet') }}</span>
      </li>
    </ul>
  </div>
</template>
