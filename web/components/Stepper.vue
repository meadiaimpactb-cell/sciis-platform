<script setup lang="ts">
/** Multi-step progress header (registration, project form). */
/** `clickable` lets the user go back to completed steps; `free` lets them jump to any step (autosaved forms). */
const props = defineProps<{ steps: { key: string; label: string }[]; current: number; clickable?: boolean; free?: boolean }>()
const canGo = (i: number) => (props.free && i !== props.current) || (!!props.clickable && i < props.current)
const emit = defineEmits<{ (e: 'go', i: number): void }>()
const { t } = useI18n()
const state = (i: number) => (i < props.current ? 'done' : i === props.current ? 'current' : 'upcoming')
</script>

<template>
  <nav :aria-label="t('common.step', { n: current + 1, total: steps.length })">
    <!-- Wraps instead of scrolling: an RTL overflow-x:auto flex row leaks its overflow into the page width in Chromium. -->
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-2 pb-1">
      <li v-for="(s, i) in steps" :key="s.key" class="flex items-center gap-2 shrink-0">
        <component
          :is="canGo(i) ? 'button' : 'div'"
          :type="canGo(i) ? 'button' : undefined"
          class="flex items-center gap-2 rounded-full border ps-1 pe-3 py-1 text-sm transition"
          :class="[state(i) === 'current' ? 'border-accent/35 bg-accent/10 text-ink font-semibold' : state(i) === 'done' ? 'border-transparent text-ink' : 'border-transparent text-muted', canGo(i) && 'hover:bg-ink/10 kbd-focus']"
          :aria-current="state(i) === 'current' ? 'step' : undefined"
          @click="canGo(i) && emit('go', i)"
        >
          <span class="h-6 w-6 shrink-0 rounded-full flex items-center justify-center text-xs font-bold num border" :class="state(i) === 'done' ? 'bg-accent border-accent text-on-accent' : state(i) === 'current' ? 'bg-surface border-accent-hi text-accent-text ring-2 ring-accent/25' : 'bg-transparent border-ink/20 text-muted'">
            <svg v-if="state(i) === 'done'" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="whitespace-nowrap">{{ s.label }}</span>
          <span class="sr-only">— {{ t(`components.stepper.${state(i) === 'done' ? 'completed' : state(i)}`) }}</span>
        </component>
        <span v-if="i < steps.length - 1" class="h-0.5 w-5 rounded-full" :class="i < current ? 'bg-accent' : 'bg-ink/15'" aria-hidden="true" />
      </li>
    </ol>
    <p class="mt-2 text-xs text-muted sm:hidden">{{ t('common.step', { n: current + 1, total: steps.length }) }}</p>
  </nav>
</template>
