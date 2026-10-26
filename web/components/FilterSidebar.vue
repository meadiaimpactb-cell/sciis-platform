<script setup lang="ts">
/** Facet filters with counts per option (OPP-02 / OPP-10). Collapsible on mobile; keyboard navigable. */
export interface FilterGroup {
  key: string
  label: string
  options: { value: string; label: string; count?: number }[]
  /** Long lists (SDGs, beneficiary groups) start folded unless one of their options is selected. */
  collapsed?: boolean
}
const props = defineProps<{ groups: FilterGroup[]; modelValue: Record<string, string | null> }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, string | null>): void; (e: 'reset'): void }>()
const open = ref(false)
const expanded = reactive<Record<string, boolean>>({})
props.groups.forEach((g) => (expanded[g.key] = !g.collapsed || !!props.modelValue[g.key]))
// A selection applied from outside (a saved search) unfolds its group.
watch(() => props.modelValue, (v) => { for (const g of props.groups) if (v[g.key]) expanded[g.key] = true }, { deep: true })
const active = computed(() => Object.values(props.modelValue).filter(Boolean).length)
const set = (k: string, v: string | null) => emit('update:modelValue', { ...props.modelValue, [k]: props.modelValue[k] === v ? null : v })
</script>

<template>
  <aside class="card" :aria-label="$t('components.filters.title')">
    <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-border">
      <button type="button" class="lg:hidden inline-flex items-center gap-2 font-medium kbd-focus rounded" :aria-expanded="open" aria-controls="filter-body" @click="open = !open">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" /></svg>
        {{ $t('components.filters.title') }}
        <span v-if="active" class="rounded-full bg-blue text-white text-xs px-1.5 num">{{ active }}</span>
      </button>
      <span class="hidden lg:inline font-medium">{{ $t('components.filters.title') }}<span v-if="active" class="text-muted text-xs ms-2">({{ $t('components.filters.active', { n: active }) }})</span></span>
      <button v-if="active" type="button" class="text-xs text-blue hover:underline kbd-focus rounded" @click="emit('reset')">{{ $t('common.clearAll') }}</button>
    </div>
    <div id="filter-body" class="divide-y divide-border" :class="open ? 'block' : 'hidden lg:block'">
      <section v-for="g in groups" :key="g.key" class="px-4 py-3">
        <button type="button" class="w-full flex items-center justify-between text-sm font-medium kbd-focus rounded" :aria-expanded="expanded[g.key]" :aria-controls="`fg-${g.key}`" @click="expanded[g.key] = !expanded[g.key]">
          {{ g.label }}
          <svg class="h-4 w-4 text-muted transition" :class="expanded[g.key] && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <ul v-show="expanded[g.key]" :id="`fg-${g.key}`" class="mt-2 space-y-0.5" role="group" :aria-label="g.label">
          <li v-for="o in g.options" :key="o.value">
            <button
              type="button"
              class="w-full flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm transition kbd-focus"
              :class="modelValue[g.key] === o.value ? 'bg-blue-soft text-blue font-medium' : 'hover:bg-bg text-ink/90'"
              :aria-pressed="modelValue[g.key] === o.value"
              :disabled="o.count === 0 && modelValue[g.key] !== o.value"
              @click="set(g.key, o.value)"
            >
              <span class="truncate" :class="o.count === 0 && 'text-muted/60'">{{ o.label }}</span>
              <span v-if="o.count != null" class="num text-xs text-muted shrink-0">{{ o.count }}</span>
            </button>
          </li>
        </ul>
      </section>
    </div>
  </aside>
</template>
