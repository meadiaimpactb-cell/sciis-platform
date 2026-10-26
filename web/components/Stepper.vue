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
          class="flex items-center gap-2 rounded-full ps-1 pe-3 py-1 text-sm transition"
          :class="[state(i) === 'current' ? 'bg-navy-100 text-navy font-medium' : state(i) === 'done' ? 'text-ink' : 'text-muted', canGo(i) && 'hover:bg-bg kbd-focus']"
          :aria-current="state(i) === 'current' ? 'step' : undefined"
          @click="canGo(i) && emit('go', i)"
        >
          <span class="h-6 w-6 rounded-full flex items-center justify-center text-xs num border" :class="state(i) === 'done' ? 'bg-teal border-teal text-navy-900' : state(i) === 'current' ? 'bg-navy border-navy text-white' : 'bg-surface border-border'">
            <svg v-if="state(i) === 'done'" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="whitespace-nowrap">{{ s.label }}</span>
          <span class="sr-only">— {{ t(`components.stepper.${state(i) === 'done' ? 'completed' : state(i)}`) }}</span>
        </component>
        <span v-if="i < steps.length - 1" class="h-px w-6 bg-border" aria-hidden="true" />
      </li>
    </ol>
    <p class="mt-2 text-xs text-muted sm:hidden">{{ t('common.step', { n: current + 1, total: steps.length }) }}</p>
  </nav>
</template>
