<script setup lang="ts">
/**
 * The published project stages as an endless strip (design v3; copy from the CMS, home → stages). The list is rendered
 * twice and the track moves by half its width, so the loop has no jump; it runs towards the reading direction and
 * stops for reduced motion. The second copy is hidden from assistive technology.
 */
defineProps<{ label: string; items: string[] }>()
const { locale } = useI18n()
const copies = [0, 1] as const
const pad = (n: number) => String(n).padStart(2, '0')
</script>

<template>
  <div class="stages">
    <div class="site-wrap stages__inner">
      <p class="stages__label">{{ label }}</p>
      <div class="stages__viewport">
        <div class="stages__track" :class="locale === 'ar' ? 'stages__track--rtl' : 'stages__track--ltr'">
          <ol v-for="copy in copies" :key="copy" class="stages__list" :aria-hidden="copy === 1 ? 'true' : undefined">
            <li v-for="(stage, i) in items" :key="i" class="stages__item">
              <span class="stages__num">{{ pad(i + 1) }}</span>
              {{ stage }}
              <svg class="stages__sep" viewBox="0 0 100 100" width="12" height="12" aria-hidden="true"><path d="M92 8A84 84 0 0 0 8 92H36A56 56 0 0 1 92 36Z" fill="var(--accent-hi)" /></svg>
            </li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stages {
  position: relative;
  border-block: 1px solid rgba(var(--fg-rgb), 0.1);
  background: rgba(var(--bg0-rgb), 0.5);
}
.stages__inner {
  display: flex;
  align-items: stretch;
}
.stages__label {
  flex: none;
  display: flex;
  align-items: center;
  max-width: 220px;
  margin: 0;
  padding-block: 22px;
  padding-inline-end: 28px;
  border-inline-end: 1px solid rgba(var(--fg-rgb), 0.1);
  font: 600 14px/1.5 var(--fb);
  color: var(--accent-text);
}
.stages__viewport {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  display: flex;
  align-items: center;
  padding-block: 22px;
  mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
  -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
}
.stages__track {
  display: flex;
  width: max-content;
}
.stages__track--ltr { animation: stages-ltr 55s linear infinite; }
.stages__track--rtl { animation: stages-rtl 55s linear infinite; }
.stages__list {
  display: flex;
  gap: 40px;
  margin: 0;
  padding: 0;
  padding-inline-end: 40px;
  list-style: none;
}
.stages__item {
  display: inline-flex;
  align-items: center;
  gap: 16px;
  flex: none;
  font: 700 17px var(--fd);
  color: var(--fg);
  white-space: nowrap;
}
.stages__num {
  font: 700 13px var(--fd);
  color: var(--accent-text);
}
.stages__sep {
  margin-inline-start: 24px;
  opacity: 0.6;
}
@keyframes stages-ltr { to { transform: translateX(-50%); } }
@keyframes stages-rtl { to { transform: translateX(50%); } }
@media (prefers-reduced-motion: reduce) {
  .stages__track--ltr,
  .stages__track--rtl { animation: none; }
}
@media (max-width: 640px) {
  .stages__inner { flex-direction: column; }
  .stages__label {
    max-width: none;
    padding-block: 16px 0;
    padding-inline-end: 0;
    border-inline-end: 0;
  }
  .stages__viewport { padding-block: 14px 18px; }
}
</style>
