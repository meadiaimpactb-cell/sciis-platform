import type { Config } from 'tailwindcss'

// Brand tokens from SPEC §10 mapped to Tailwind. Colors read from CSS variables so that
// tokens.css stays the single source of truth (BRD-01). AA contrast verified for text pairs (UX-04).
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
      // Hex values are identical to assets/css/tokens.css (kept as hex so Tailwind opacity modifiers like `text-muted/70` work).
      colors: {
        ink: '#0A1020',
        navy: '#101B33',
        blue: {
          DEFAULT: '#1F5BFF',
          hover: '#1548D6',
          soft: '#E8EEFF',
        },
        bg: '#F4F6FA',
        surface: '#FFFFFF',
        text: '#0A1020',
        muted: '#5B6478',
        border: '#E3E7EF',
        success: '#047857',
        warning: '#B45309',
        danger: '#B91C1C',
        info: '#1F5BFF',
        status: {
          draft: '#94A3B8',
          new: '#1F5BFF',
          under_review: '#B45309',
          needs_completion: '#D97706',
          not_eligible: '#B91C1C',
          eligible: '#047857',
          connected: '#0F766E',
          negotiating: '#6D28D9',
          in_execution: '#1D4ED8',
          operating: '#065F46',
          withdrawn: '#6B7280',
          closed: '#374151',
        },
      },
      fontFamily: {
        sans: ['"IBM Plex Sans Arabic"', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
      },
      borderRadius: {
        xl: 'var(--radius-xl)',
        '2xl': 'var(--radius-2xl)',
      },
      boxShadow: {
        card: 'var(--shadow-card)',
        pop: 'var(--shadow-pop)',
      },
      maxWidth: {
        '8xl': '88rem',
      },
    },
  },
  plugins: [],
}
