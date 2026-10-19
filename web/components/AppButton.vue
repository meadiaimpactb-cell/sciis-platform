<script setup lang="ts">
/** Primary action button — blue only for primary (BRD). Variants: primary | secondary | ghost | danger | dark | outline */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'dark' | 'outline' | 'link'
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
  const base = 'inline-flex items-center justify-center gap-2 font-medium rounded-lg transition select-none whitespace-nowrap focus-visible:ring-2 focus-visible:ring-blue focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed'
  const sizes = { sm: props.icon ? 'h-8 w-8 text-sm' : 'h-8 px-3 text-sm', md: props.icon ? 'h-10 w-10' : 'h-10 px-4 text-sm', lg: props.icon ? 'h-12 w-12' : 'h-12 px-6 text-base' }
  const variants = {
    primary: 'bg-blue text-white hover:bg-blue-hover shadow-sm',
    secondary: 'bg-surface text-ink border border-border hover:bg-bg',
    outline: 'bg-transparent text-blue border border-blue hover:bg-blue-soft',
    ghost: 'bg-transparent text-ink hover:bg-bg',
    danger: 'bg-danger text-white hover:opacity-90',
    dark: 'bg-ink text-white hover:bg-navy',
    link: 'bg-transparent text-blue hover:underline px-1 h-auto',
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
