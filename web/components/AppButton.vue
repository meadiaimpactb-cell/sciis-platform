<script setup lang="ts">
/**
 * Action button (design v3 pills). Variants: primary (accent fill) · secondary (outlined) · ghost · link · danger ·
 * outline (accent outline) · light (solid contrast fill: white on dark, navy on light). `accent` = primary with glow;
 * `dark` and `line` are kept as aliases of light / secondary for older call sites.
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'accent' | 'secondary' | 'ghost' | 'danger' | 'dark' | 'outline' | 'link' | 'light' | 'line'
    size?: 'sm' | 'md' | 'lg'
    to?: string | Record<string, unknown>
    href?: string
    type?: 'button' | 'submit' | 'reset'
    loading?: boolean
    disabled?: boolean
    block?: boolean
    icon?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false, icon: false },
)

const classes = computed(() => {
  const base = 'inline-flex items-center justify-center gap-2 font-semibold rounded-full border border-transparent transition-colors duration-150 select-none whitespace-nowrap focus-visible:ring-2 focus-visible:ring-accent-text focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed'
  const sizes = { sm: props.icon ? 'h-9 w-9 text-sm' : 'h-9 px-4 text-sm', md: props.icon ? 'h-10 w-10' : 'h-10 px-[1.125rem] text-sm', lg: props.icon ? 'h-[3.25rem] w-[3.25rem]' : 'h-[3.25rem] px-[1.625rem] text-[0.9375rem]' }
  const solid = 'bg-[var(--btn2-bg)] text-[var(--btn2-fg)] hover:bg-[var(--btn2-hover)]'
  const outlined = 'bg-transparent text-ink border-ink/20 hover:bg-ink/10'
  const variants = {
    primary: 'bg-accent text-on-accent hover:bg-accent-hi',
    accent: 'bg-accent text-on-accent hover:bg-accent-hi shadow-glow',
    secondary: outlined,
    line: outlined,
    outline: 'bg-transparent text-accent-text border-accent-text/50 hover:bg-accent/10 hover:border-accent-text',
    ghost: 'bg-transparent text-ink hover:bg-ink/10',
    danger: 'bg-danger text-on-status hover:bg-danger/90',
    light: solid,
    dark: solid,
    link: 'bg-transparent text-accent-text hover:text-ink hover:underline underline-offset-4 !px-1 !h-auto !rounded',
  }
  return [base, sizes[props.size], variants[props.variant], props.block ? 'w-full' : ''].join(' ')
})
const localePath = useLocalePath()
const resolvedTo = computed(() => (typeof props.to === 'string' ? localePath(props.to) : props.to))
</script>

<template>
  <NuxtLink v-if="to && !disabled" :to="resolvedTo" :class="classes">
    <slot />
  </NuxtLink>
  <a v-else-if="href" :href="href" :class="classes" target="_blank" rel="noopener">
    <slot />
  </a>
  <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
    <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
      <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" class="opacity-90" />
    </svg>
    <slot />
  </button>
</template>
