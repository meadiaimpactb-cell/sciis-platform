<script setup lang="ts">
/** Primary action button. Variants: primary (navy) | accent (teal) | secondary | outline | ghost | danger | dark | link | light (on dark surfaces) | line (outlined, on dark surfaces) */
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
  const base = 'inline-flex items-center justify-center gap-2 font-medium rounded-md transition duration-200 select-none whitespace-nowrap focus-visible:ring-2 focus-visible:ring-teal-700 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed'
  const sizes = { sm: props.icon ? 'h-8 w-8 text-sm' : 'h-9 px-3.5 text-sm', md: props.icon ? 'h-10 w-10' : 'h-10 px-4 text-sm', lg: props.icon ? 'h-12 w-12' : 'h-12 px-6 text-base' }
  const variants = {
    primary: 'bg-navy text-white hover:bg-navy-800 shadow-sm shadow-navy/20 hover:shadow-md hover:shadow-navy/25',
    accent: 'bg-teal text-navy-900 hover:bg-teal-400 shadow-sm shadow-teal/30 font-semibold',
    secondary: 'bg-surface text-ink border border-border hover:border-navy/40 hover:bg-navy-50',
    outline: 'bg-transparent text-navy border border-navy/60 hover:bg-navy-50 hover:border-navy',
    ghost: 'bg-transparent text-ink hover:bg-navy-50',
    danger: 'bg-danger text-white hover:opacity-90',
    dark: 'bg-navy-900 text-white hover:bg-navy-800',
    light: 'bg-white text-navy hover:bg-teal-50 shadow-sm',
    link: 'bg-transparent text-navy hover:text-teal-text hover:underline px-1 h-auto',
    line: 'bg-transparent text-white border border-white/80 font-normal hover:bg-white hover:text-navy-950',
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
