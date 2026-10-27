<script setup lang="ts">
/**
 * Two-party consent (design v3): each side's state (agreed / waiting), joined by a line that lights up once both have
 * agreed — e.g. the investor and the project owner before contact details or a meeting are shared.
 */
interface Party { label: string; done: boolean; hint?: string }
defineProps<{ parties: [Party, Party]; title?: string }>()
</script>

<template>
  <div class="rounded-2xl border border-border bg-glass p-4">
    <p v-if="title" class="mb-3 text-sm font-semibold text-ink">{{ title }}</p>
    <div class="flex items-center gap-3">
      <template v-for="(p, i) in parties" :key="i">
        <span v-if="i === 1" class="h-0.5 min-w-6 flex-1 rounded-full" :class="parties[0].done && parties[1].done ? 'bg-accent' : 'bg-ink/15'" aria-hidden="true" />
        <div class="flex min-w-0 items-center gap-2.5">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border" :class="p.done ? 'border-accent bg-accent text-on-accent' : 'border-ink/20 text-t3'" aria-hidden="true">
            <svg v-if="p.done" class="h-4 w-4" viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8" /><path d="M12 8v4l2.5 2" /></svg>
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm font-medium text-ink">{{ p.label }}</span>
            <span class="block text-xs" :class="p.done ? 'text-accent-text' : 'text-t3'">{{ p.hint ?? (p.done ? $t('components.consent.done') : $t('components.consent.waiting')) }}</span>
          </span>
        </div>
      </template>
    </div>
  </div>
</template>
