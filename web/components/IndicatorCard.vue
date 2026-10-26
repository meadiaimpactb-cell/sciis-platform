<script setup lang="ts">
/**
 * Illustrative impact-indicator card of the home page (design v3): baseline, target, data source, update cycle and the
 * current reading on a progress bar. Sample values, labelled as such.
 */
const BASELINE = 12
const TARGET = 45
const CURRENT = 31
const { percent: pct } = useFormat()
// Position of the baseline marker / fill along the bar (the bar runs from 0 to the target).
const baselineAt = `${Math.round((BASELINE / TARGET) * 100)}%`
const fillTo = `${Math.round((CURRENT / TARGET) * 100)}%`
</script>

<template>
  <div class="indicator">
    <svg class="indicator__arc" viewBox="0 0 600 600" aria-hidden="true">
      <circle cx="300" cy="300" r="280" fill="none" stroke="rgba(var(--accent-rgb), .25)" stroke-width="1" />
      <path d="M300 20A280 280 0 0 1 580 300" fill="none" stroke="var(--accent-hi)" stroke-width="2.5" opacity=".7" />
    </svg>
    <div class="site-glass indicator__card">
      <div class="indicator__head">
        <span class="indicator__badge">{{ $t('landing.impact.card.badge') }}</span>
        <span class="indicator__sample">{{ $t('landing.impact.card.sample') }}</span>
      </div>
      <p class="indicator__name">{{ $t('landing.impact.card.name') }}</p>
      <dl class="indicator__grid">
        <div class="indicator__cell">
          <dt>{{ $t('landing.impact.card.baseline') }}</dt>
          <dd class="indicator__value num">{{ pct(BASELINE) }}</dd>
        </div>
        <div class="indicator__cell">
          <dt>{{ $t('landing.impact.card.target') }}</dt>
          <dd class="indicator__value indicator__value--accent num">{{ pct(TARGET) }}</dd>
        </div>
        <div class="indicator__cell">
          <dt>{{ $t('landing.impact.card.source') }}</dt>
          <dd>{{ $t('landing.impact.card.sourceValue') }}</dd>
        </div>
        <div class="indicator__cell">
          <dt>{{ $t('landing.impact.card.frequency') }}</dt>
          <dd>{{ $t('landing.impact.card.frequencyValue') }}</dd>
        </div>
      </dl>
      <div class="indicator__progress">
        <div class="indicator__row">
          <span>{{ $t('landing.impact.card.current') }}</span>
          <span class="indicator__current num">{{ pct(CURRENT) }}</span>
        </div>
        <div class="indicator__bar" role="img" :aria-label="`${$t('landing.impact.card.current')} ${pct(CURRENT)}`">
          <div class="indicator__fill" :style="{ width: fillTo }" />
          <div class="indicator__marker" :style="{ insetInlineStart: baselineAt }" />
        </div>
        <div class="indicator__row indicator__row--small">
          <span>{{ $t('landing.impact.card.baseline') }}</span>
          <span>{{ $t('landing.impact.card.target') }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.indicator { position: relative; }
.indicator__arc {
  position: absolute;
  inset-inline-end: -140px;
  top: -140px;
  width: 460px;
  height: 460px;
  pointer-events: none;
}
.indicator__card {
  padding: 32px;
  display: flex;
  flex-direction: column;
  gap: 22px;
}
.indicator__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.indicator__badge {
  display: inline-flex;
  align-items: center;
  height: 26px;
  padding-inline: 10px;
  border-radius: 999px;
  background: rgba(88, 176, 168, 0.16);
  color: var(--accent-text);
  font: 500 12px var(--fb);
}
.indicator__sample { font: 400 12px var(--fb); color: var(--t3); }
.indicator__name { margin: 0; font: 700 21px/1.5 var(--fd); color: var(--fg); }
.indicator__grid {
  margin: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  background: rgba(var(--fg-rgb), 0.1);
  border: 1px solid rgba(var(--fg-rgb), 0.1);
  border-radius: 14px;
  overflow: hidden;
}
.indicator__cell {
  background: var(--bg1);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.indicator__cell dt { font: 400 12px var(--fb); color: var(--t3); }
.indicator__cell dd { margin: 0; font: 500 15px/1.5 var(--fb); color: var(--fg); }
.indicator__cell .indicator__value { font: 800 30px/1 var(--fd); }
.indicator__cell .indicator__value--accent { color: var(--accent-text); }
.indicator__progress { display: flex; flex-direction: column; gap: 10px; }
.indicator__row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font: 400 13px var(--fb);
  color: var(--t3);
}
.indicator__row--small { font-size: 12px; }
.indicator__current { color: var(--accent-text); font-weight: 600; }
.indicator__bar {
  position: relative;
  height: 10px;
  border-radius: 5px;
  background: rgba(var(--fg-rgb), 0.12);
}
.indicator__fill {
  position: absolute;
  inset-inline-start: 0;
  top: 0;
  height: 10px;
  border-radius: 5px;
  background: linear-gradient(90deg, #58b0a8, var(--accent-hi));
  box-shadow: 0 0 18px rgba(var(--accent-rgb), 0.6);
}
.indicator__marker {
  position: absolute;
  top: -5px;
  width: 2px;
  height: 20px;
  background: var(--fg);
  border-radius: 1px;
}
@media (max-width: 640px) {
  .indicator__arc { display: none; }
  .indicator__card { padding: 22px; }
}
</style>
