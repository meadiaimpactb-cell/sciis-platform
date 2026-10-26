import type { Config } from 'tailwindcss'

// Brand tokens derived from the official SCII logo (navy #24306C, teal #58B4A8).
// Hex values mirror assets/css/tokens.css. The semantic surface / text tokens read RGB-channel variables (`tok()`), so the
// portal dark theme (BRD-04, assets/css/theme-dark.css) swaps them without per-class `dark:` edits; opacity modifiers
// like `text-muted/70` keep working. Light values are unchanged.
// AA contrast verified for text pairs (UX-04): navy-700 on white 10.4:1 · teal-700 on white 4.7:1 · ink on teal-500 7.1:1.
const tok = (name: string) => `rgb(var(--c-${name}) / <alpha-value>)`

export default <Partial<Config>>{
  content: [
    './components/**/*.{vue,ts}',
    './layouts/**/*.vue',
    './pages/**/*.vue',
    './plugins/**/*.ts',
    './composables/**/*.ts',
    './utils/**/*.ts',
    './app.vue',
    './error.vue',
  ],
  theme: {
    screens: {
      sm: '640px', // mobile ≤640
      md: '768px',
      lg: '1024px', // tablet ≤1024
      xl: '1280px',
      '2xl': '1440px',
    },
    extend: {
      colors: {
        ink: tok('ink'),
        navy: {
          DEFAULT: '#24306C',
          950: '#0E1330',
          900: '#121A3F',
          800: '#1B2555',
          700: '#24306C',
          600: '#33428A',
          100: tok('navy-100'),
          50: tok('navy-50'),
        },
        teal: {
          DEFAULT: '#58B4A8',
          700: tok('teal-700'),
          600: '#3F9A8F',
          500: '#58B4A8',
          400: '#7FCBC0',
          100: tok('teal-100'),
          50: tok('teal-50'),
          hover: '#3F9A8F',
          soft: tok('teal-100'),
          text: tok('teal-700'),
        },
        // Legacy alias: "blue" = brand primary (navy). Existing components keep working.
        blue: {
          DEFAULT: '#24306C',
          hover: '#1B2555',
          soft: tok('navy-100'),
        },
        bg: tok('bg'),
        surface: tok('surface'),
        text: tok('text'),
        muted: tok('muted'),
        border: tok('border'),
        success: '#1F7A5C',
        warning: '#B45309',
        danger: '#B91C1C',
        info: '#24306C',
        status: {
          draft: '#94A3B8',
          new: '#24306C',
          under_review: '#B45309',
          needs_completion: '#D97706',
          not_eligible: '#B91C1C',
          eligible: '#2F7D74',
          connected: '#0F766E',
          negotiating: '#6D28D9',
          in_execution: '#33428A',
          operating: '#065F46',
          withdrawn: '#6B7280',
          closed: '#374151',
        },
      },
      fontFamily: {
        sans: ['"IBM Plex Sans Arabic"', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
      },
      // Type scale measured on falak.sa (desktop 1440 / mobile 390) — the reference the platform follows.
      // Every `text-*` utility in the app maps onto one of these steps, so public pages and the portals share it:
      // 14 body · 16 description · 18 stat label · 20 lead / card title · 25 section label · 30 · 40 section title · 50 display.
      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem' }], // 11px — badges / dense meta only
        xs: ['0.75rem', { lineHeight: '1.125rem' }], // 12px
        sm: ['0.875rem', { lineHeight: '1.3125rem' }], // 14 / 21 — Falak body
        base: ['1rem', { lineHeight: '1.5rem' }], // 16 / 24 (paragraphs use .text-desc → 16 / 30)
        lg: ['1.125rem', { lineHeight: '1.6875rem' }], // 18 / 27
        xl: ['1.25rem', { lineHeight: '1.9375rem' }], // 20 / 31
        '2xl': ['1.5625rem', { lineHeight: '1.875rem' }], // 25 / 30 — section label
        '3xl': ['1.875rem', { lineHeight: '2.25rem' }], // 30 / 36
        '4xl': ['2.5rem', { lineHeight: '3.25rem' }], // 40 / 52 — section title
        '5xl': ['3.125rem', { lineHeight: '3.75rem' }], // 50 / 60 — display
      },
      borderRadius: {
        xl: 'var(--radius-xl)',
        '2xl': 'var(--radius-2xl)',
      },
      boxShadow: {
        card: 'var(--shadow-card)',
        pop: 'var(--shadow-pop)',
        glow: 'var(--shadow-glow)',
      },
      backgroundImage: {
        'gradient-brand': 'var(--gradient-brand)',
        'gradient-hero': 'var(--gradient-hero)',
      },
      maxWidth: {
        '8xl': '88rem',
      },
      keyframes: {
        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-8px)' } },
        'fade-up': { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
      },
      animation: {
        float: 'float 6s ease-in-out infinite',
        'float-slow': 'float 9s ease-in-out infinite',
        'fade-up': 'fade-up 0.6s ease-out both',
      },
    },
  },
  plugins: [],
}
