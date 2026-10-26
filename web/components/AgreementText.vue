<script setup lang="ts">
/**
 * AGR-02/04 — renders an agreement text safely (interpolation only, never v-html): `# ` → title, `## ` → clause
 * heading, `- ` / `• ` → list item, other lines → paragraphs. Unfilled placeholders such as {city} are highlighted.
 */
const props = withDefaults(defineProps<{ text: string | null | undefined; lang?: 'ar' | 'en'; highlight?: boolean }>(), { lang: 'ar', highlight: true })

type Seg = { text: string; ph: boolean }
type Block = { kind: 'h1' | 'h2' | 'p' | 'ul'; lines: Seg[][] }
const PLACEHOLDER = /(\{\{?\s*[a-z][a-z0-9_]*\s*\}?\})/i
const segments = (line: string): Seg[] => line.split(PLACEHOLDER).filter(Boolean).map((s) => ({ text: s, ph: props.highlight && PLACEHOLDER.test(s) }))

const blocks = computed<Block[]>(() => {
  const out: Block[] = []
  for (const raw of (props.text ?? '').replace(/\r\n?/g, '\n').split('\n')) {
    const line = raw.trimEnd()
    if (!line.trim()) { out.push({ kind: 'p', lines: [] }); continue }
    if (/^#\s+/.test(line)) { out.push({ kind: 'h1', lines: [segments(line.replace(/^#\s+/, ''))] }); continue }
    if (/^##+\s+/.test(line)) { out.push({ kind: 'h2', lines: [segments(line.replace(/^##+\s+/, ''))] }); continue }
    if (/^\s*[-•]\s+/.test(line)) {
      const item = segments(line.replace(/^\s*[-•]\s+/, ''))
      const last = out[out.length - 1]
      if (last?.kind === 'ul') last.lines.push(item)
      else out.push({ kind: 'ul', lines: [item] })
      continue
    }
    out.push({ kind: 'p', lines: [segments(line)] })
  }
  return out.filter((b, i, all) => b.lines.length || (i > 0 && all[i - 1].lines.length))
})
</script>

<template>
  <div class="agreement-text text-[15px] leading-8 text-ink" :dir="lang === 'en' ? 'ltr' : 'rtl'" :lang="lang">
    <template v-for="(b, i) in blocks" :key="i">
      <h2 v-if="b.kind === 'h1'" class="text-xl font-bold text-ink mb-3"><template v-for="(s, j) in b.lines[0]" :key="j"><mark v-if="s.ph" class="agr-ph">{{ s.text }}</mark><template v-else>{{ s.text }}</template></template></h2>
      <h3 v-else-if="b.kind === 'h2'" class="mt-5 mb-1 text-base font-semibold text-ink"><template v-for="(s, j) in b.lines[0]" :key="j"><mark v-if="s.ph" class="agr-ph">{{ s.text }}</mark><template v-else>{{ s.text }}</template></template></h3>
      <ul v-else-if="b.kind === 'ul'" class="list-disc ps-6 space-y-1"><li v-for="(l, k) in b.lines" :key="k"><template v-for="(s, j) in l" :key="j"><mark v-if="s.ph" class="agr-ph">{{ s.text }}</mark><template v-else>{{ s.text }}</template></template></li></ul>
      <p v-else-if="b.lines.length"><template v-for="(s, j) in b.lines[0]" :key="j"><mark v-if="s.ph" class="agr-ph">{{ s.text }}</mark><template v-else>{{ s.text }}</template></template></p>
      <div v-else class="h-3" aria-hidden="true" />
    </template>
  </div>
</template>

<style scoped>
.agr-ph {
  background: color-mix(in srgb, #d97706 14%, white);
  color: #92400e;
  border-radius: 0.25rem;
  padding: 0 0.25rem;
  direction: ltr;
  unicode-bidi: isolate;
}
@media print {
  .agr-ph { background: none; color: inherit; border-bottom: 1px dashed currentColor; }
}
</style>
