<script setup lang="ts">
/**
 * "How the platform works" (design v3; steps from the CMS, home → how): a list of tabs and a preview panel. Clicking a
 * step selects it; "Next step" advances and wraps from the last back to the first. Arrow keys move between the tabs.
 */
type Step = { title?: string; text?: string; meta?: string }
const props = defineProps<{ steps: Step[]; label: string }>()
const STEPS = computed(() => props.steps.length)
const step = ref(1)
const current = computed<Step>(() => props.steps[step.value - 1] ?? {})
const pad = (n: number) => String(n).padStart(2, '0')
const select = (n: number) => (step.value = n)
const next = () => (step.value = (step.value % STEPS.value) + 1)
const segment = (n: number) => (n < step.value ? 'done' : n === step.value ? 'current' : 'todo')

const tabs = ref<HTMLButtonElement[]>([])
const onKey = (e: KeyboardEvent) => {
  const forward = ['ArrowDown', 'ArrowRight'].includes(e.key)
  const back = ['ArrowUp', 'ArrowLeft'].includes(e.key)
  if (!forward && !back) return
  e.preventDefault()
  const total = STEPS.value
  const n = forward ? (step.value % total) + 1 : ((step.value + total - 2) % total) + 1
  select(n)
  tabs.value[n - 1]?.focus()
}
</script>

<template>
  <div class="how">
    <div class="how__tabs" role="tablist" :aria-label="label" aria-orientation="vertical" @keydown="onKey">
      <button
        v-for="n in STEPS"
        :id="`how-tab-${n}`"
        :key="n"
        ref="tabs"
        type="button"
        role="tab"
        class="how__tab"
        :class="{ 'how__tab--active': n === step }"
        :aria-selected="n === step"
        :tabindex="n === step ? 0 : -1"
        aria-controls="how-panel"
        @click="select(n)"
      >
        <span class="how__tab-num">{{ pad(n) }}</span>
        <span class="how__tab-title">{{ steps[n - 1]?.title }}</span>
      </button>
    </div>

    <div id="how-panel" class="site-glass how__panel" role="tabpanel" :aria-labelledby="`how-tab-${step}`">
      <div class="how__top">
        <span class="how__counter">{{ $t('landing.how.counter', { n: pad(step), total: STEPS }) }}</span>
        <span class="how__preview"><span class="how__dot" aria-hidden="true" />{{ $t('landing.how.preview') }}</span>
      </div>
      <div class="how__segments" :style="{ gridTemplateColumns: `repeat(${STEPS}, 1fr)` }" aria-hidden="true">
        <span v-for="n in STEPS" :key="n" class="how__segment" :class="`how__segment--${segment(n)}`" />
      </div>
      <div class="how__body" aria-live="polite">
        <span class="how__num">{{ pad(step) }}</span>
        <h3 class="how__title">{{ current.title }}</h3>
        <p class="how__text">{{ current.text }}</p>
      </div>
      <div class="how__foot">
        <span class="how__meta">{{ current.meta }}</span>
        <button type="button" class="site-btn site-btn--outline how__next" @click="next">
          {{ $t('landing.how.next') }} <span class="inline-block rtl:rotate-180" aria-hidden="true">→</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.how {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 400px), 1fr));
  gap: 40px 72px;
  align-items: start;
}
.how__tabs {
  display: flex;
  flex-direction: column;
  border-top: 1px solid rgba(var(--fg-rgb), 0.12);
}
.how__tab {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 22px 20px;
  border: 0;
  border-bottom: 1px solid rgba(var(--fg-rgb), 0.12);
  border-inline-start: 3px solid transparent;
  background: transparent;
  color: var(--fg);
  text-align: start;
  cursor: pointer;
  transition: background-color 0.15s ease;
}
.how__tab:hover { background: rgba(var(--fg-rgb), 0.04); }
.how__tab-num { min-width: 30px; font: 800 15px var(--fd); color: var(--t3); }
.how__tab-title { font: 700 18px var(--fd); color: var(--t2); }
.how__tab--active,
.how__tab--active:hover {
  background: rgba(var(--accent-rgb), 0.08);
  border-inline-start-color: var(--accent-hi);
}
.how__tab--active .how__tab-num { color: var(--accent-text); }
.how__tab--active .how__tab-title { color: var(--fg); }

.how__panel {
  padding: 36px;
  min-height: 380px;
  display: flex;
  flex-direction: column;
  gap: 26px;
}
.how__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.how__counter { font: 500 13px var(--fb); color: var(--t3); }
.how__preview {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font: 500 13px var(--fb);
  color: var(--accent-text);
}
.how__dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent-hi); }
.how__segments { display: grid; gap: 6px; }
.how__segment { height: 5px; border-radius: 3px; background: rgba(var(--fg-rgb), 0.14); transition: background-color 0.2s ease; }
.how__segment--done { background: var(--scii-teal); }
.how__segment--current { background: var(--accent-hi); }
.how__body { display: flex; flex-direction: column; gap: 14px; }
.how__num { font: 800 64px/1 var(--fd); color: var(--accent-text); }
.how__title { font: 800 30px/1.35 var(--fd); }
.how__text { margin: 0; font: 400 17px/1.85 var(--fb); color: var(--t2); }
.how__foot {
  margin-top: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding-top: 22px;
  border-top: 1px solid rgba(var(--fg-rgb), 0.1);
}
.how__meta { font: 400 14px/1.6 var(--fb); color: var(--t3); }
.how__next { height: 42px; font-size: 14px; padding-inline: 18px; }
@media (max-width: 640px) {
  .how__panel { padding: 24px; min-height: 0; }
  .how__num { font-size: 48px; }
  .how__title { font-size: 24px; }
}
</style>
