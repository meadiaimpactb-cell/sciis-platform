import type { Config } from 'tailwindcss'

// SCII identity v3. Every themed colour reads an RGB-channel variable from assets/css/tokens.css (`tok()`), so the
// dark default and [data-theme="light"] swap them without per-class `dark:` edits, and opacity modifiers like
// `text-muted/70` keep working. The v3 names (bg0, bg1, fg, t2, t3, line, accent …) and the older semantic names
// (bg, surface, ink, text, muted, border, navy, blue …) read the same tokens. Contrast pairs are listed in tokens.css.
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
        // v3 names
        bg0: tok('bg'),
        bg1: tok('surface'),
        deep: tok('deep'),
        field: tok('field'),
        fg: tok('ink'),
        t2: tok('text'),
        t3: tok('muted'),
        line: tok('border'),
        accent: { DEFAULT: '#58B0A8', text: tok('teal-700'), hi: tok('accent-hi') },
        'on-accent': '#07102A',
        'on-status': tok('on-status'),
        overlay: tok('overlay'),
        glass: 'rgb(var(--c-ink) / 0.06)',
        // semantic names used across the app (same tokens)
        ink: tok('ink'),
        bg: tok('bg'),
        surface: tok('surface'),
        text: tok('text'),
        muted: tok('muted'),
        border: tok('border'),
        // navy as text / line / small marks = brand emphasis (navy on light, accent text on dark); the numbered
        // dark shades stay the fixed logo colours.
        navy: {
          DEFAULT: tok('link'),
          950: '#0E1330',
          900: '#121A3F',
          800: '#1B2555',
          700: tok('link'),
          600: tok('link'),
          100: tok('navy-100'),
          50: tok('navy-50'),
        },
        teal: {
          DEFAULT: '#58B0A8',
          700: tok('teal-700'),
          600: '#3F9A8F',
          500: '#58B0A8',
          400: tok('accent-hi'),
          100: tok('teal-100'),
          50: tok('teal-50'),
          hover: tok('accent-hi'),
          soft: tok('teal-100'),
          text: tok('teal-700'),
        },
        // Legacy alias: "blue" = brand emphasis (as navy).
        blue: {
          DEFAULT: tok('link'),
          hover: tok('link'),
          soft: tok('navy-100'),
        },
        success: tok('ok'),
        warning: tok('warn'),
        danger: tok('bad'),
        info: tok('link'),
      },
      // focus-ring offsets take the card colour of the active theme instead of white
      ringOffsetColor: { DEFAULT: 'rgb(var(--c-surface))' },
      fontFamily: {
        sans: ['"IBM Plex Sans Arabic"', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
        // headings: Almarai in Arabic, Sora in English (tokens.css → --fd)
        display: ['var(--fd)'],
      },
      // Type scale measured on falak.sa (desktop 1440 / mobile 390) — the reference the platform follows (tokens.css --fs-*).
      // Every `text-*` utility in the app maps onto one of these steps, so public pages and the portals share it:
      // 14 body · 16 description · 18 stat label · 20 lead / card title · 25 section label · 30 · 40 section title · 50 display.
      fontSize: {
        '2xs': ['0.75rem', { lineHeight: '1.125rem' }], // 12px — badges / dense meta only
        xs: ['0.8125rem', { lineHeight: '1.25rem' }], // 13px — the smallest reading size
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
