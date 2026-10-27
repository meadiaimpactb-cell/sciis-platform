<script setup lang="ts">
import { toastError, toastSuccess } from '~/utils/notify'
/**
 * Self-assessment quiz engine (tools/*): one question per step (fieldset/legend + native radios → arrow keys work),
 * progress bar, back/next, "start over", and a result view with an overall score + band, per-axis bars, tailored
 * recommendations, CTAs and a shareable result text.
 * Scoring is pure and client-side (utils/assessments.ts); answers are kept in localStorage only if the visitor opts in.
 */
import type { Answers, AssessmentConfig } from '~/utils/assessments'

const props = defineProps<{
  config: AssessmentConfig
  /** Localized tool name (share text). */
  toolName: string
  cta: { to: string; label: string }
  learn: { to: string; label: string }
}>()

const { t, locale } = useI18n()
const localePath = useLocalePath()
const { number } = useFormat()
const siteUrl = useSiteUrl()
const L = (v: { ar: string; en: string }) => pickL10n(v, locale.value)

const total = props.config.questions.length
const answers = reactive<Answers>({})
const step = ref(0) // 0..total-1 = questions, total = result
const remember = ref(false)
const showError = ref(false)
const done = computed(() => step.value >= total)
const question = computed(() => props.config.questions[Math.min(step.value, total - 1)])
const answeredCount = computed(() => props.config.questions.filter((q) => answers[q.id]).length)
const progress = computed(() => Math.round((answeredCount.value / total) * 100))
const result = computed(() => scoreAssessment(props.config, answers))
const axisLabel = (id: string) => L(props.config.axes.find((a) => a.id === id)?.label ?? { ar: id, en: id })
const bandTone = computed(() => ({ ready: 'bg-success/10 text-success', close: 'bg-teal-soft text-teal-text', prepare: 'bg-warning/10 text-warning' })[result.value.band])
const barColor = (score: number) => (score >= 75 ? 'var(--success)' : score >= 50 ? 'var(--scii-teal)' : 'var(--warning)')

// ---------------------------------------------------------------- focus management
const legendRef = ref<HTMLElement | null>(null)
const resultRef = ref<HTMLElement | null>(null)
const focusCurrent = () => nextTick(() => (done.value ? resultRef.value : legendRef.value)?.focus({ preventScroll: false }))

const go = (to: number) => { showError.value = false; step.value = Math.max(0, Math.min(total, to)); focusCurrent() }
const next = () => {
  if (!answers[question.value.id]) { showError.value = true; return }
  // jump to the first unanswered question (if the visitor went back), otherwise the next one / the result
  const firstOpen = props.config.questions.findIndex((q, i) => i > step.value && !answers[q.id])
  go(firstOpen === -1 ? (answeredCount.value === total ? total : step.value + 1) : firstOpen)
}
const prev = () => go(step.value - 1)
const restart = () => { for (const k of Object.keys(answers)) delete answers[k]; go(0) }
watch(() => answers[question.value.id], (v) => { if (v) showError.value = false })

// ---------------------------------------------------------------- optional local persistence (try/catch: private mode, blocked storage)
const read = () => {
  try {
    const raw = window.localStorage.getItem(props.config.storageKey)
    if (!raw) return
    const saved = JSON.parse(raw) as { answers?: unknown; done?: boolean }
    Object.assign(answers, sanitizeAnswers(props.config, saved.answers))
    remember.value = true
    const firstOpen = props.config.questions.findIndex((q) => !answers[q.id])
    step.value = firstOpen === -1 ? (saved.done ? total : total - 1) : firstOpen
  } catch { /* storage unavailable or corrupt → start fresh */ }
}
const persist = () => {
  try {
    if (remember.value) window.localStorage.setItem(props.config.storageKey, JSON.stringify({ answers: { ...answers }, done: done.value, saved_at: new Date().toISOString() }))
    else window.localStorage.removeItem(props.config.storageKey)
  } catch { /* ignore */ }
}
onMounted(() => { read(); watch([answers, step, remember], persist, { deep: true }) })

// ---------------------------------------------------------------- share (no PII: score + band + public URL only)
const shareText = computed(() => t('tools.share.text', { score: result.value.score, band: L(props.config.bands[result.value.band].label), tool: props.toolName }))
const shareUrl = () => `${siteUrl}${localePath(`/tools/${props.config.id}`)}`
const share = async () => {
  const url = shareUrl()
  try {
    if (navigator.share) { await navigator.share({ title: props.toolName, text: shareText.value, url }); return }
    await navigator.clipboard.writeText(`${shareText.value} ${url}`)
    toastSuccess(t('tools.share.copied'))
  } catch (e) {
    if ((e as Error)?.name !== 'AbortError') toastError(t('tools.share.copyFailed'))
  }
}
</script>

<template>
  <section class="card overflow-hidden" :aria-label="toolName">
    <!-- progress -->
    <div v-if="!done" class="px-5 sm:px-7 pt-5">
      <div class="flex items-center justify-between gap-3 text-sm">
        <span class="font-medium text-ink num" aria-live="polite">{{ $t('tools.quiz.progress', { n: step + 1, total }) }}</span>
        <span class="text-xs text-muted num">{{ number(progress, { maximumFractionDigits: 0 }) }}%</span>
      </div>
      <div class="mt-2 h-2 rounded-full bg-ink/10 overflow-hidden" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" :aria-label="$t('tools.quiz.progressLabel')">
        <div class="h-full rounded-full bg-accent transition-all duration-500" :style="{ width: `${progress}%` }" />
      </div>
    </div>

    <!-- question -->
    <form v-if="!done" class="px-5 sm:px-7 pb-6 pt-5 min-h-[30rem] flex flex-col" novalidate @submit.prevent="next">
      <fieldset :key="question.id" class="flex-1" :aria-describedby="[question.help ? `${question.id}-help` : '', showError ? `${question.id}-err` : ''].filter(Boolean).join(' ') || undefined">
        <legend ref="legendRef" tabindex="-1" class="font-display text-lg sm:text-xl font-bold text-ink leading-8 focus:outline-none">
          <span class="block text-xs font-medium text-teal-text mb-1.5">{{ axisLabel(question.axis) }}</span>
          {{ L(question.prompt) }}
        </legend>
        <p v-if="question.help" :id="`${question.id}-help`" class="mt-2 text-sm text-muted leading-6">{{ L(question.help) }}</p>
        <div class="mt-5 grid gap-2.5">
          <label
            v-for="opt in question.options" :key="opt.id"
            class="group flex items-start gap-3 rounded-xl border px-4 py-3.5 cursor-pointer transition focus-within:ring-2 focus-within:ring-accent-text"
            :class="answers[question.id] === opt.id ? 'border-accent bg-accent/10' : 'border-border hover:border-accent/50 hover:bg-ink/5'"
          >
            <input v-model="answers[question.id]" type="radio" :name="question.id" :value="opt.id" class="mt-1 h-4 w-4 shrink-0 accent-teal">
            <span class="text-sm sm:text-base leading-6 text-ink">{{ L(opt.label) }}</span>
          </label>
        </div>
        <p v-if="showError" :id="`${question.id}-err`" class="error-text mt-3" role="alert">{{ $t('tools.quiz.chooseOne') }}</p>
      </fieldset>

      <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <AppButton v-if="step > 0" variant="ghost" @click="prev">{{ $t('tools.quiz.previous') }}</AppButton>
        <span v-else />
        <AppButton type="submit" size="lg">{{ answeredCount === total || step === total - 1 ? $t('tools.quiz.finish') : $t('tools.quiz.next') }}</AppButton>
      </div>
      <div class="mt-5 pt-4 border-t border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <label class="inline-flex items-start gap-2 text-sm text-ink/85 cursor-pointer">
          <input v-model="remember" type="checkbox" class="mt-0.5 h-4 w-4 rounded accent-teal">
          <span>{{ $t('tools.quiz.remember') }}<span class="block text-xs text-muted">{{ $t('tools.quiz.rememberHelp') }}</span></span>
        </label>
        <button v-if="answeredCount" type="button" class="text-sm text-muted hover:text-danger underline-offset-4 hover:underline kbd-focus rounded w-fit" @click="restart">{{ $t('tools.quiz.restart') }}</button>
      </div>
    </form>

    <!-- result -->
    <div v-else class="px-5 sm:px-7 pb-7 pt-5">
      <h2 ref="resultRef" tabindex="-1" class="card-title mb-4 focus:outline-none">{{ $t('tools.result.title') }}</h2>
      <div class="flex flex-col sm:flex-row sm:items-center gap-5">
        <CompletionRing :percent="result.score" :size="120" :stroke="10" :label="$t('tools.result.scoreLabel')" />
        <div class="min-w-0">
          <p class="text-sm text-muted">{{ $t('tools.result.scoreLabel') }} <span class="num">{{ result.score }}</span> {{ $t('tools.result.outOf') }}</p>
          <p class="mt-1"><span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold" :class="bandTone">{{ L(config.bands[result.band].label) }}</span></p>
          <p class="mt-2 text-ink/85 leading-7">{{ L(config.bands[result.band].summary) }}</p>
        </div>
      </div>

      <h3 class="mt-7 font-semibold text-ink">{{ $t('tools.result.axesTitle') }}</h3>
      <ul class="mt-3 space-y-3" role="list">
        <li v-for="a in result.axes" :key="a.id">
          <div class="flex items-center justify-between gap-3 text-sm"><span class="text-ink/90">{{ axisLabel(a.id) }}</span><span class="num font-medium text-ink">{{ a.score }}%</span></div>
          <div class="mt-1.5 h-2.5 rounded-full bg-ink/10 overflow-hidden" aria-hidden="true"><div class="h-full rounded-full transition-all duration-700" :style="{ width: `${Math.max(3, a.score)}%`, backgroundColor: barColor(a.score) }" /></div>
        </li>
      </ul>

      <h3 class="mt-7 font-semibold text-ink">{{ $t('tools.result.recommendationsTitle') }}</h3>
      <ol v-if="result.weak.length" class="mt-3 space-y-3" role="list">
        <li v-for="(q, i) in result.weak" :key="q.id" class="flex gap-3 rounded-xl border border-border bg-ink/5 p-4">
          <span class="num h-7 w-7 shrink-0 rounded-full bg-accent text-on-accent text-sm font-bold flex items-center justify-center" aria-hidden="true">{{ i + 1 }}</span>
          <div class="min-w-0 text-sm leading-6">
            <p class="font-semibold text-ink">{{ L(q.short) }} <span class="font-normal text-xs text-muted">· {{ axisLabel(q.axis) }}</span></p>
            <p class="mt-1 text-ink/80">{{ L(q.tip) }}</p>
            <NuxtLink v-if="q.link" :to="localePath(q.link.to)" class="mt-1.5 inline-block link text-sm font-medium">{{ L(q.link.label) }}</NuxtLink>
          </div>
        </li>
      </ol>
      <p v-else class="mt-3 rounded-xl bg-success/10 text-success p-4 text-sm font-medium">{{ $t('tools.result.noWeak') }}</p>

      <div class="surface-hero mt-7 rounded-2xl border border-ink/10 p-5 sm:p-6">
        <OrbitBackdrop :orbits="false" />
        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <p class="font-display text-lg font-bold text-ink">{{ $t('tools.result.nextTitle') }}</p>
          <div class="flex flex-wrap gap-2">
            <AppButton :to="cta.to" variant="accent">{{ cta.label }}</AppButton>
            <AppButton :to="learn.to" variant="light">{{ learn.label }}</AppButton>
          </div>
        </div>
      </div>

      <div class="mt-6 flex flex-wrap items-center gap-2">
        <AppButton variant="secondary" size="sm" @click="share">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" /></svg>
          {{ $t('tools.share.button') }}
        </AppButton>
        <AppButton variant="ghost" size="sm" @click="go(0)">{{ $t('tools.quiz.review') }}</AppButton>
        <AppButton variant="ghost" size="sm" @click="restart">{{ $t('tools.quiz.restart') }}</AppButton>
      </div>
      <p class="mt-5 text-xs text-muted leading-6">{{ $t('tools.result.disclaimer') }}</p>
    </div>
  </section>
</template>
